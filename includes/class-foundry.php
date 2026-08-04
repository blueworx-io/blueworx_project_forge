<?php
/**
 * Temporary connector: pushes newly created Forge items to Foundry as proposals.
 *
 * Self-contained on purpose — deleting this file and its require line removes
 * the integration entirely.
 */
defined( 'ABSPATH' ) || exit;

class Forge_PM_Foundry {

	const OPTION_KEY = 'forge_pm_foundry';
	const BASE_URL   = 'https://foundry.gitwork.co.uk';
	const CRON_HOOK  = 'forge_pm_foundry_push';

	const META_ID        = '_forge_foundry_id';
	const META_ERROR     = '_forge_foundry_error';
	const META_PUSHED_AT = '_forge_foundry_pushed_at';

	const ITEM_TYPES = [
		'forge_feature',
		'forge_subitem',
		'forge_bug',
		'forge_feedback',
		'forge_release',
		'forge_company_date',
	];

	public static function init() {
		add_action( 'save_post', [ __CLASS__, 'maybe_queue' ], 20, 2 );
		add_action( self::CRON_HOOK, [ __CLASS__, 'push' ] );

		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ], 11 );
		add_action( 'add_meta_boxes', [ __CLASS__, 'register_meta_box' ] );

		add_action( 'admin_post_forge_foundry_save', [ __CLASS__, 'handle_save' ] );
		add_action( 'admin_post_forge_foundry_test', [ __CLASS__, 'handle_test' ] );
		add_action( 'admin_post_forge_foundry_push', [ __CLASS__, 'handle_push' ] );
	}

	// ── Configuration ────────────────────────────────────────────

	public static function settings(): array {
		return wp_parse_args( get_option( self::OPTION_KEY, [] ), [
			'enabled'     => false,
			'clientName'  => '',
			'productName' => '',
			'templateId'  => '',
		] );
	}

	/** The key never lives in the database — only in wp-config.php. */
	public static function api_key(): string {
		return defined( 'FORGE_FOUNDRY_API_KEY' ) ? (string) FORGE_FOUNDRY_API_KEY : '';
	}

	/** Everything that must be in place before a push can be attempted. */
	private static function missing_config(): array {
		$settings = self::settings();
		$missing  = [];

		if ( self::api_key() === '' )      $missing[] = 'FORGE_FOUNDRY_API_KEY in wp-config.php';
		if ( $settings['clientName'] === '' )  $missing[] = 'Client name';
		if ( $settings['productName'] === '' ) $missing[] = 'Product name';
		if ( $settings['templateId'] === '' )  $missing[] = 'Template ID';

		return $missing;
	}

	// ── Queueing ─────────────────────────────────────────────────

	/**
	 * Queue a push for a first-time publish. Runs on cron rather than inline so
	 * post meta is fully written before the payload is built, and so a slow or
	 * unreachable Foundry never delays saving an item.
	 */
	public static function maybe_queue( $post_id, $post ) {
		if ( ! $post instanceof WP_Post ) return;
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
		if ( ! in_array( $post->post_type, self::ITEM_TYPES, true ) ) return;
		if ( $post->post_status !== 'publish' ) return;

		$settings = self::settings();
		if ( empty( $settings['enabled'] ) ) return;
		if ( self::missing_config() ) return;

		// Already in Foundry — never push the same item twice.
		if ( get_post_meta( $post_id, self::META_ID, true ) ) return;

		if ( ! wp_next_scheduled( self::CRON_HOOK, [ $post_id ] ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK, [ $post_id ] );
		}
	}

	// ── Pushing ──────────────────────────────────────────────────

	/**
	 * Send one item to Foundry. Records the Foundry id on success, the error on
	 * failure. Safe to call twice — a pushed item is skipped.
	 *
	 * @return string|WP_Error Foundry record id, or the reason it failed.
	 */
	public static function push( $post_id ) {
		$post_id  = absint( $post_id );
		$post     = get_post( $post_id );
		$settings = self::settings();

		if ( ! $post || ! in_array( $post->post_type, self::ITEM_TYPES, true ) ) {
			return new WP_Error( 'forge_foundry_not_item', 'Not a Forge item.' );
		}

		$existing = get_post_meta( $post_id, self::META_ID, true );
		if ( $existing ) {
			return (string) $existing;
		}

		$missing = self::missing_config();
		if ( $missing ) {
			return self::record_failure( $post_id, new WP_Error(
				'forge_foundry_config',
				'Connector not configured — missing: ' . implode( ', ', $missing ) . '.'
			) );
		}

		// Only the four documented fields. Foundry's handling of unknown keys is
		// unverified, so nothing extra is sent.
		$response = self::request( 'POST', '/api/proposals', [
			'title'       => $post->post_title !== '' ? $post->post_title : "Forge item {$post_id}",
			'clientName'  => $settings['clientName'],
			'productName' => $settings['productName'],
			'templateId'  => $settings['templateId'],
		] );

		if ( is_wp_error( $response ) ) {
			return self::record_failure( $post_id, $response );
		}

		$foundry_id = self::extract_id( $response );
		if ( $foundry_id === '' ) {
			return self::record_failure( $post_id, new WP_Error(
				'forge_foundry_no_id',
				'Foundry accepted the item but returned no id.'
			) );
		}

		update_post_meta( $post_id, self::META_ID, $foundry_id );
		update_post_meta( $post_id, self::META_PUSHED_AT, current_time( 'mysql' ) );
		delete_post_meta( $post_id, self::META_ERROR );

		return $foundry_id;
	}

	private static function record_failure( int $post_id, WP_Error $error ): WP_Error {
		update_post_meta( $post_id, self::META_ERROR, $error->get_error_message() );
		return $error;
	}

	/** Foundry's create response shape is unverified — check the likely places. */
	private static function extract_id( array $data ): string {
		$candidates = [
			$data['id']                 ?? null,
			$data['proposalId']         ?? null,
			$data['data']['id']         ?? null,
			$data['proposal']['id']     ?? null,
		];

		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && $candidate !== '' ) return $candidate;
			if ( is_int( $candidate ) ) return (string) $candidate;
		}

		return '';
	}

	// ── HTTP ─────────────────────────────────────────────────────

	/**
	 * @return array|WP_Error Decoded response body, or the failure.
	 */
	private static function request( string $method, string $path, ?array $body = null ) {
		$key = self::api_key();
		if ( $key === '' ) {
			return new WP_Error( 'forge_foundry_no_key', 'No Foundry API key — add FORGE_FOUNDRY_API_KEY to wp-config.php.' );
		}

		$args = [
			'method'  => $method,
			'timeout' => 15,
			'headers' => [
				'Authorization' => 'Bearer ' . $key,
				'Accept'        => 'application/json',
			],
		];

		if ( $body !== null ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::BASE_URL . $path, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'forge_foundry_unreachable', 'Could not reach Foundry: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : [];

		if ( $code < 200 || $code > 299 ) {
			return new WP_Error( "forge_foundry_http_{$code}", self::explain( $code, $data ), [ 'status' => $code ] );
		}

		return $data;
	}

	private static function explain( int $code, array $data ): string {
		$reasons = [
			400 => 'Foundry rejected the fields we sent',
			401 => 'Foundry rejected the API key',
			403 => 'The API key is not allowed to do that',
			404 => 'Foundry has no such endpoint or record',
			500 => 'Foundry hit a server error',
		];

		$reason = $reasons[ $code ] ?? "Foundry returned HTTP {$code}";
		$detail = $data['message'] ?? $data['error'] ?? '';

		return is_string( $detail ) && $detail !== '' ? "{$reason}: {$detail}" : "{$reason}.";
	}

	// ── Settings screen ──────────────────────────────────────────

	public static function register_menu() {
		add_submenu_page(
			'forge-project-management',
			__( 'Foundry', 'forge-pm' ),
			__( 'Foundry', 'forge-pm' ),
			'manage_options',
			'forge-foundry',
			[ __CLASS__, 'render_settings' ]
		);
	}

	public static function render_settings() {
		$settings = self::settings();
		$missing  = self::missing_config();
		$notice   = isset( $_GET['forge_foundry_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['forge_foundry_notice'] ) ) : '';
		$ok       = ! empty( $_GET['forge_foundry_ok'] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Foundry', 'forge-pm' ); ?></h1>
			<p><?php esc_html_e( 'Sends each new Forge item to Foundry as a proposal. Temporary connector.', 'forge-pm' ); ?></p>

			<?php if ( $notice ) : ?>
				<div class="notice <?php echo $ok ? 'notice-success' : 'notice-error'; ?>">
					<p><?php echo esc_html( $notice ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $missing ) : ?>
				<div class="notice notice-warning">
					<p><?php echo esc_html( __( 'Not ready yet — still needs: ', 'forge-pm' ) . implode( ', ', $missing ) . '.' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="forge_foundry_save">
				<?php wp_nonce_field( 'forge_foundry_save' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Send items to Foundry', 'forge-pm' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
								<?php esc_html_e( 'On', 'forge-pm' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Existing items are never sent — only items added from now on.', 'forge-pm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="forge-foundry-client"><?php esc_html_e( 'Client name', 'forge-pm' ); ?></label></th>
						<td><input class="regular-text" type="text" id="forge-foundry-client" name="clientName" value="<?php echo esc_attr( $settings['clientName'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="forge-foundry-product"><?php esc_html_e( 'Product name', 'forge-pm' ); ?></label></th>
						<td><input class="regular-text" type="text" id="forge-foundry-product" name="productName" value="<?php echo esc_attr( $settings['productName'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="forge-foundry-template"><?php esc_html_e( 'Template ID', 'forge-pm' ); ?></label></th>
						<td>
							<input class="regular-text" type="text" id="forge-foundry-template" name="templateId" value="<?php echo esc_attr( $settings['templateId'] ); ?>">
							<p class="description"><?php esc_html_e( 'Every proposal is created from this Foundry template.', 'forge-pm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'API key', 'forge-pm' ); ?></th>
						<td>
							<p>
								<?php echo self::api_key() !== ''
									? esc_html__( 'Found in wp-config.php.', 'forge-pm' )
									: esc_html__( 'Not set. Add define( \'FORGE_FOUNDRY_API_KEY\', \'…\' ); to wp-config.php.', 'forge-pm' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="forge_foundry_test">
				<?php wp_nonce_field( 'forge_foundry_test' ); ?>
				<?php submit_button( __( 'Test connection', 'forge-pm' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'forge-pm' ) );
		}
		check_admin_referer( 'forge_foundry_save' );

		update_option( self::OPTION_KEY, [
			'enabled'     => ! empty( $_POST['enabled'] ),
			'clientName'  => sanitize_text_field( wp_unslash( $_POST['clientName'] ?? '' ) ),
			'productName' => sanitize_text_field( wp_unslash( $_POST['productName'] ?? '' ) ),
			'templateId'  => sanitize_text_field( wp_unslash( $_POST['templateId'] ?? '' ) ),
		] );

		self::redirect_to_settings( __( 'Saved.', 'forge-pm' ), true );
	}

	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'forge-pm' ) );
		}
		check_admin_referer( 'forge_foundry_test' );

		$response = self::request( 'GET', '/api/proposals' );

		if ( is_wp_error( $response ) ) {
			self::redirect_to_settings( $response->get_error_message(), false );
		}

		self::redirect_to_settings( __( 'Connected — Foundry accepted the key.', 'forge-pm' ), true );
	}

	private static function redirect_to_settings( string $message, bool $ok ) {
		wp_safe_redirect( add_query_arg( [
			'page'                 => 'forge-foundry',
			'forge_foundry_notice' => rawurlencode( $message ),
			'forge_foundry_ok'     => $ok ? '1' : '0',
		], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Item edit screen ─────────────────────────────────────────

	public static function register_meta_box() {
		foreach ( self::ITEM_TYPES as $type ) {
			add_meta_box( 'forge-foundry', __( 'Foundry', 'forge-pm' ), [ __CLASS__, 'render_meta_box' ], $type, 'side', 'low' );
		}
	}

	public static function render_meta_box( WP_Post $post ) {
		$foundry_id = get_post_meta( $post->ID, self::META_ID, true );
		$error      = get_post_meta( $post->ID, self::META_ERROR, true );
		$pushed_at  = get_post_meta( $post->ID, self::META_PUSHED_AT, true );

		if ( $foundry_id ) {
			printf(
				'<p><strong>%s</strong><br><code>%s</code></p>',
				esc_html__( 'Sent to Foundry', 'forge-pm' ),
				esc_html( $foundry_id )
			);
			if ( $pushed_at ) {
				printf( '<p class="description">%s</p>', esc_html( $pushed_at ) );
			}
			return;
		}

		if ( $error ) {
			printf(
				'<p><strong>%s</strong><br>%s</p>',
				esc_html__( 'Not sent', 'forge-pm' ),
				esc_html( $error )
			);
		} else {
			printf( '<p>%s</p>', esc_html__( 'Not sent to Foundry yet.', 'forge-pm' ) );
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=forge_foundry_push&post=' . $post->ID ),
			'forge_foundry_push_' . $post->ID
		);
		printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( $url ), esc_html__( 'Send now', 'forge-pm' ) );
	}

	public static function handle_push() {
		$post_id = absint( $_GET['post'] ?? 0 );

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Permission denied.', 'forge-pm' ) );
		}
		check_admin_referer( 'forge_foundry_push_' . $post_id );

		self::push( $post_id );

		wp_safe_redirect( get_edit_post_link( $post_id, 'raw' ) );
		exit;
	}
}
