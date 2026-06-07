<?php
defined( 'ABSPATH' ) || exit;

class Forge_PM_Status {

	const CLIENT_ERRORS_OPTION = 'forge_pm_client_errors';
	const PHP_ERRORS_OPTION    = 'forge_pm_php_errors';
	const MAX_ERRORS           = 50;

	// Registers the shutdown handler that captures fatal PHP errors originating
	// from this plugin — works even when WP_DEBUG_LOG is disabled.
	public static function init() {
		register_shutdown_function( [ __CLASS__, 'capture_fatal' ] );
	}

	public static function capture_fatal() {
		$e = error_get_last();
		if ( ! $e ) return;

		$fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
		if ( ! ( $e['type'] & $fatal_types ) ) return;

		// Only record errors that originate from this plugin's files.
		if ( empty( $e['file'] ) || strpos( $e['file'], FORGE_PM_DIR ) === false ) return;

		self::store_error( self::PHP_ERRORS_OPTION, [
			'time'    => current_time( 'mysql' ),
			'type'    => self::php_error_label( $e['type'] ),
			'message' => $e['message'],
			'file'    => str_replace( FORGE_PM_DIR, '', $e['file'] ),
			'line'    => $e['line'],
		] );
	}

	private static function php_error_label( $type ) {
		$map = [
			E_ERROR             => 'Fatal Error',
			E_PARSE             => 'Parse Error',
			E_CORE_ERROR        => 'Core Error',
			E_COMPILE_ERROR     => 'Compile Error',
			E_USER_ERROR        => 'User Error',
			E_RECOVERABLE_ERROR => 'Recoverable Error',
		];
		return $map[ $type ] ?? 'Error';
	}

	// Prepends an entry to a capped, newest-first rolling log stored in an option.
	private static function store_error( $option, array $entry ) {
		$errors = get_option( $option, [] );
		if ( ! is_array( $errors ) ) $errors = [];
		array_unshift( $errors, $entry );
		if ( count( $errors ) > self::MAX_ERRORS ) {
			$errors = array_slice( $errors, 0, self::MAX_ERRORS );
		}
		update_option( $option, $errors, false );
	}

	public static function get_client_errors(): array {
		$e = get_option( self::CLIENT_ERRORS_OPTION, [] );
		return is_array( $e ) ? $e : [];
	}

	public static function get_php_errors(): array {
		$e = get_option( self::PHP_ERRORS_OPTION, [] );
		return is_array( $e ) ? $e : [];
	}

	// REST: POST /forge/v1/client-errors — receives JS / network errors from the app.
	public static function register_routes() {
		register_rest_route( 'forge/v1', '/client-errors', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'receive_client_error' ],
			'permission_callback' => function () { return current_user_can( 'read' ); },
		] );
	}

	public static function receive_client_error( $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid', 'No data supplied.', [ 'status' => 400 ] );
		}

		$kind    = sanitize_key( $data['kind'] ?? 'js' );
		$message = sanitize_text_field( mb_substr( (string) ( $data['message'] ?? '' ), 0, 500 ) );

		if ( $message === '' ) {
			return new WP_Error( 'empty', 'Empty error message.', [ 'status' => 400 ] );
		}

		self::store_error( self::CLIENT_ERRORS_OPTION, [
			'time'       => current_time( 'mysql' ),
			'kind'       => in_array( $kind, [ 'js', 'promise', 'network' ], true ) ? $kind : 'js',
			'message'    => $message,
			'source'     => sanitize_text_field( (string) ( $data['source'] ?? '' ) ),
			'line'       => isset( $data['line'] ) ? (int) $data['line'] : null,
			'col'        => isset( $data['col'] ) ? (int) $data['col'] : null,
			'stack'      => sanitize_textarea_field( mb_substr( (string) ( $data['stack'] ?? '' ), 0, 2000 ) ),
			'pageUrl'    => esc_url_raw( (string) ( $data['url'] ?? '' ) ),
			'requestUrl' => esc_url_raw( (string) ( $data['requestUrl'] ?? '' ) ),
			'status'     => isset( $data['status'] ) ? (int) $data['status'] : null,
			'user'       => wp_get_current_user()->user_login,
		] );

		return rest_ensure_response( [ 'success' => true ] );
	}

	public static function register_menu() {
		add_submenu_page(
			'forge-project-management',
			__( 'Status', 'forge-pm' ),
			__( 'Status', 'forge-pm' ),
			'edit_posts',
			'forge-pm-status',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function get_diagnostics() {
		$settings  = Forge_PM_Settings::get();
		$page_id   = get_option( 'forge_pm_page_id' );
		$page_post = $page_id ? get_post( $page_id ) : null;

		$cpt_counts = [];
		foreach ( [ 'forge_feature', 'forge_subitem', 'forge_bug', 'forge_feedback', 'forge_release', 'forge_company_date' ] as $cpt ) {
			$counts         = wp_count_posts( $cpt );
			$cpt_counts[ $cpt ] = (int) ( $counts->publish ?? 0 ) + (int) ( $counts->draft ?? 0 );
		}

		$api_url  = rest_url( 'forge/v1/items' );
		$api_start = microtime( true );
		$api_response = wp_remote_get( $api_url, [ 'timeout' => 10, 'sslverify' => false ] );
		$api_ms   = round( ( microtime( true ) - $api_start ) * 1000 );
		$api_code = is_wp_error( $api_response ) ? $api_response->get_error_message() : wp_remote_retrieve_response_code( $api_response );

		$debug_log_lines = [];
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && file_exists( WP_DEBUG_LOG ) ) {
			$lines = file( WP_DEBUG_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			$debug_log_lines = array_slice( $lines, -20 );
		} elseif ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG === true ) {
			$default = WP_CONTENT_DIR . '/debug.log';
			if ( file_exists( $default ) ) {
				$lines = file( $default, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
				$debug_log_lines = array_slice( $lines, -20 );
			}
		}

		return [
			'plugin'      => [
				'version'  => FORGE_PM_VERSION,
				'dir'      => FORGE_PM_DIR,
				'basename' => FORGE_PM_BASENAME,
			],
			'environment' => [
				'wordpress' => get_bloginfo( 'version' ),
				'php'       => PHP_VERSION,
				'theme'     => wp_get_theme()->get( 'Name' ),
				'multisite' => is_multisite() ? 'Yes' : 'No',
				'debug'     => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'Enabled' : 'Disabled',
			],
			'post_counts' => $cpt_counts,
			'api'         => [
				'url'  => $api_url,
				'code' => $api_code,
				'ms'   => $api_ms,
			],
			'settings'    => [
				'project_name'     => $settings['projectName'] ?? '(not set)',
				'brands_count'     => count( $settings['brands'] ?? [] ),
				'categories_count' => count( $settings['categories'] ?? [] ),
				'statuses_count'   => count( $settings['statuses'] ?? [] ),
			],
			'cache'       => [
				'auth' => get_transient( 'forge_pm_items_auth' ) !== false ? 'Cached' : 'Empty',
				'pub'  => get_transient( 'forge_pm_items_pub' )  !== false ? 'Cached' : 'Empty',
			],
			'page'        => [
				'id'     => $page_id ?: 'None',
				'status' => $page_post ? $page_post->post_status : 'Not found',
				'url'    => $page_post ? get_permalink( $page_id ) : 'N/A',
			],
			'debug_log'   => $debug_log_lines,
			'client_errors' => self::get_client_errors(),
			'php_errors'    => self::get_php_errors(),
		];
	}

	public static function render_page() {
		// Handle "Clear" actions before reading diagnostics.
		if ( isset( $_POST['forge_clear_errors'] ) && check_admin_referer( 'forge_clear_errors' ) ) {
			$what = sanitize_key( wp_unslash( $_POST['forge_clear_errors'] ) );
			if ( $what === 'client' || $what === 'all' ) delete_option( self::CLIENT_ERRORS_OPTION );
			if ( $what === 'php'    || $what === 'all' ) delete_option( self::PHP_ERRORS_OPTION );
			echo '<div class="notice notice-success is-dismissible"><p>Errors cleared.</p></div>';
		}

		$d = self::get_diagnostics();

		$cpt_labels = [
			'forge_feature'      => 'Features',
			'forge_subitem'      => 'Sub-Items',
			'forge_bug'          => 'Bugs',
			'forge_feedback'     => 'Feedback',
			'forge_release'      => 'Releases',
			'forge_company_date' => 'Company Dates',
		];

		$api_ok = $d['api']['code'] === 200;

		// Build plain-text copy block
		$copy  = "=== Forge PM Status Report ===\n";
		$copy .= "Generated: " . current_time( 'Y-m-d H:i:s' ) . "\n\n";

		$copy .= "-- Plugin --\n";
		$copy .= "Version: {$d['plugin']['version']}\n";
		$copy .= "Path: {$d['plugin']['dir']}\n\n";

		$copy .= "-- Environment --\n";
		foreach ( $d['environment'] as $k => $v ) {
			$copy .= ucfirst( $k ) . ": $v\n";
		}
		$copy .= "\n";

		$copy .= "-- Post Counts --\n";
		foreach ( $d['post_counts'] as $cpt => $count ) {
			$copy .= ( $cpt_labels[ $cpt ] ?? $cpt ) . ": $count\n";
		}
		$copy .= "\n";

		$copy .= "-- REST API --\n";
		$copy .= "URL: {$d['api']['url']}\n";
		$copy .= "Status: {$d['api']['code']}\n";
		$copy .= "Response time: {$d['api']['ms']}ms\n\n";

		$copy .= "-- Settings --\n";
		$copy .= "Project: {$d['settings']['project_name']}\n";
		$copy .= "Brands: {$d['settings']['brands_count']}\n";
		$copy .= "Categories: {$d['settings']['categories_count']}\n";
		$copy .= "Statuses: {$d['settings']['statuses_count']}\n\n";

		$copy .= "-- Cache --\n";
		$copy .= "Auth cache: {$d['cache']['auth']}\n";
		$copy .= "Public cache: {$d['cache']['pub']}\n\n";

		$copy .= "-- Front-end Page --\n";
		$copy .= "ID: {$d['page']['id']}\n";
		$copy .= "Status: {$d['page']['status']}\n";
		$copy .= "URL: {$d['page']['url']}\n\n";

		$copy .= "-- JavaScript & Network Errors (" . count( $d['client_errors'] ) . ") --\n";
		if ( ! empty( $d['client_errors'] ) ) {
			foreach ( $d['client_errors'] as $e ) {
				$loc = $e['kind'] === 'network'
					? ( ( $e['status'] ?? '' ) . ' ' . ( $e['requestUrl'] ?? '' ) )
					: ( ( $e['source'] ?? '' ) . ( isset( $e['line'] ) ? ':' . $e['line'] : '' ) );
				$copy .= "[{$e['time']}] ({$e['kind']}) {$e['message']}" . ( $loc ? " — {$loc}" : '' ) . "\n";
				if ( ! empty( $e['stack'] ) ) $copy .= "  " . str_replace( "\n", "\n  ", $e['stack'] ) . "\n";
			}
		} else {
			$copy .= "None\n";
		}
		$copy .= "\n";

		$copy .= "-- PHP Errors (" . count( $d['php_errors'] ) . ") --\n";
		if ( ! empty( $d['php_errors'] ) ) {
			foreach ( $d['php_errors'] as $e ) {
				$copy .= "[{$e['time']}] {$e['type']}: {$e['message']} — {$e['file']}:{$e['line']}\n";
			}
		} else {
			$copy .= "None captured\n";
		}
		$copy .= "\n";

		if ( ! empty( $d['debug_log'] ) ) {
			$copy .= "-- PHP Debug Log (last 20 lines) --\n";
			$copy .= implode( "\n", $d['debug_log'] ) . "\n";
		} else {
			$copy .= "-- PHP Debug Log --\nNot available (WP_DEBUG_LOG not enabled or empty)\n";
		}

		$copy_json = json_encode( $copy );
		?>
		<div class="wrap">
			<h1>Forge PM — Status</h1>
			<p style="color:#555;margin-top:-8px;">Diagnostic snapshot of the plugin. Use the Copy button to paste directly into Claude.</p>

			<button
				id="forge-copy-btn"
				class="button button-primary"
				style="margin-bottom:24px;"
				onclick="(function(btn){
					var text = <?php echo $copy_json; ?>;
					navigator.clipboard.writeText(text).then(function(){
						btn.textContent = 'Copied!';
						setTimeout(function(){ btn.textContent = 'Copy Status Report'; }, 2000);
					});
				})(this)"
			>Copy Status Report</button>

			<?php
			$sections = [
				'Plugin' => [
					[ 'Version',  $d['plugin']['version'] ],
					[ 'Path',     $d['plugin']['dir'] ],
					[ 'Basename', $d['plugin']['basename'] ],
				],
				'Environment' => [
					[ 'WordPress', $d['environment']['wordpress'] ],
					[ 'PHP',       $d['environment']['php'] ],
					[ 'Theme',     $d['environment']['theme'] ],
					[ 'Multisite', $d['environment']['multisite'] ],
					[ 'Debug',     $d['environment']['debug'] ],
				],
				'Post Counts' => array_map(
					function( $cpt, $count ) use ( $cpt_labels ) {
						return [ $cpt_labels[ $cpt ] ?? $cpt, $count ];
					},
					array_keys( $d['post_counts'] ),
					array_values( $d['post_counts'] )
				),
				'REST API' => [
					[ 'Endpoint',      $d['api']['url'] ],
					[ 'HTTP Status',   $api_ok ? '<span style="color:#16a34a;font-weight:600">200 OK</span>' : '<span style="color:#dc2626;font-weight:600">' . esc_html( (string) $d['api']['code'] ) . '</span>' ],
					[ 'Response Time', $d['api']['ms'] . 'ms' ],
				],
				'Settings' => [
					[ 'Project Name', esc_html( $d['settings']['project_name'] ) ],
					[ 'Brands',       $d['settings']['brands_count'] ],
					[ 'Categories',   $d['settings']['categories_count'] ],
					[ 'Statuses',     $d['settings']['statuses_count'] ],
				],
				'Cache' => [
					[ 'Auth cache',   $d['cache']['auth'] ],
					[ 'Public cache', $d['cache']['pub'] ],
				],
				'Front-end Page' => [
					[ 'Page ID', $d['page']['id'] ],
					[ 'Status',  $d['page']['status'] ],
					[ 'URL',     $d['page']['url'] !== 'N/A' ? '<a href="' . esc_url( $d['page']['url'] ) . '" target="_blank">' . esc_html( $d['page']['url'] ) . '</a>' : 'N/A' ],
				],
			];

			foreach ( $sections as $title => $rows ) : ?>
				<h2 style="margin-top:28px;margin-bottom:8px;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#888;"><?php echo esc_html( $title ); ?></h2>
				<table class="widefat striped" style="max-width:720px;margin-bottom:4px;">
					<tbody>
						<?php foreach ( $rows as [ $label, $value ] ) : ?>
							<tr>
								<td style="width:200px;font-weight:600;color:#333;"><?php echo esc_html( $label ); ?></td>
								<td style="font-family:monospace;"><?php echo $value; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>

			<?php
			// ── JavaScript & Network errors (captured from the browser) ──────────
			$clear_form = function ( $what, $label ) {
				$btn  = '<form method="post" style="display:inline;margin:0 0 0 12px;">';
				$btn .= wp_nonce_field( 'forge_clear_errors', '_wpnonce', true, false );
				$btn .= '<input type="hidden" name="forge_clear_errors" value="' . esc_attr( $what ) . '">';
				$btn .= '<button type="submit" class="button button-small">' . esc_html( $label ) . '</button>';
				$btn .= '</form>';
				return $btn;
			};
			$kind_color = [ 'js' => '#dc2626', 'promise' => '#ea580c', 'network' => '#7c3aed' ];
			?>

			<h2 style="margin-top:28px;margin-bottom:8px;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#888;">
				JavaScript &amp; Network Errors <span style="color:#aaa;font-size:11px;">(<?php echo count( $d['client_errors'] ); ?>)</span>
				<?php if ( ! empty( $d['client_errors'] ) ) echo $clear_form( 'client', 'Clear' ); ?>
			</h2>
			<?php if ( empty( $d['client_errors'] ) ) : ?>
				<p style="color:#16a34a;">No JavaScript or network errors logged. 🎉</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:900px;margin-bottom:4px;">
					<thead><tr>
						<th style="width:140px;">Time</th>
						<th style="width:80px;">Type</th>
						<th>Message</th>
						<th style="width:240px;">Location</th>
					</tr></thead>
					<tbody>
						<?php foreach ( $d['client_errors'] as $e ) :
							$loc = $e['kind'] === 'network'
								? trim( ( $e['status'] ? $e['status'] . ' · ' : '' ) . ( $e['requestUrl'] ?? '' ) )
								: trim( ( $e['source'] ?? '' ) . ( isset( $e['line'] ) ? ':' . $e['line'] : '' ) );
						?>
							<tr>
								<td style="font-family:monospace;font-size:12px;white-space:nowrap;"><?php echo esc_html( $e['time'] ); ?></td>
								<td><span style="display:inline-block;padding:1px 7px;border-radius:4px;font-size:11px;font-weight:600;color:#fff;background:<?php echo esc_attr( $kind_color[ $e['kind'] ] ?? '#64748b' ); ?>;"><?php echo esc_html( $e['kind'] ); ?></span></td>
								<td style="font-family:monospace;font-size:12px;">
									<?php echo esc_html( $e['message'] ); ?>
									<?php if ( ! empty( $e['stack'] ) ) : ?>
										<details style="margin-top:4px;"><summary style="cursor:pointer;color:#2563eb;font-family:sans-serif;font-size:11px;">stack trace</summary><pre style="white-space:pre-wrap;margin:6px 0 0;color:#555;font-size:11px;"><?php echo esc_html( $e['stack'] ); ?></pre></details>
									<?php endif; ?>
								</td>
								<td style="font-family:monospace;font-size:11px;word-break:break-all;color:#555;"><?php echo esc_html( $loc ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2 style="margin-top:28px;margin-bottom:8px;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#888;">
				PHP Errors <span style="color:#aaa;font-size:11px;">(<?php echo count( $d['php_errors'] ); ?>)</span>
				<?php if ( ! empty( $d['php_errors'] ) ) echo $clear_form( 'php', 'Clear' ); ?>
			</h2>
			<?php if ( empty( $d['php_errors'] ) ) : ?>
				<p style="color:#16a34a;">No PHP fatal errors captured from the plugin. 🎉</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:900px;margin-bottom:4px;">
					<thead><tr>
						<th style="width:140px;">Time</th>
						<th style="width:110px;">Type</th>
						<th>Message</th>
						<th style="width:240px;">File:Line</th>
					</tr></thead>
					<tbody>
						<?php foreach ( $d['php_errors'] as $e ) : ?>
							<tr>
								<td style="font-family:monospace;font-size:12px;white-space:nowrap;"><?php echo esc_html( $e['time'] ); ?></td>
								<td style="color:#dc2626;font-weight:600;font-size:12px;"><?php echo esc_html( $e['type'] ); ?></td>
								<td style="font-family:monospace;font-size:12px;"><?php echo esc_html( $e['message'] ); ?></td>
								<td style="font-family:monospace;font-size:11px;word-break:break-all;color:#555;"><?php echo esc_html( $e['file'] . ':' . $e['line'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( ! empty( $d['debug_log'] ) ) : ?>
				<h2 style="margin-top:28px;margin-bottom:8px;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#888;">PHP Debug Log <span style="color:#aaa;font-size:11px;">(last 20 lines)</span></h2>
				<pre style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:4px;max-width:900px;overflow-x:auto;font-size:12px;line-height:1.5;"><?php echo esc_html( implode( "\n", $d['debug_log'] ) ); ?></pre>
			<?php else : ?>
				<h2 style="margin-top:28px;margin-bottom:8px;font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:#888;">PHP Debug Log</h2>
				<p style="color:#888;font-style:italic;">Not available — <code>WP_DEBUG_LOG</code> is not enabled or the log is empty.</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
