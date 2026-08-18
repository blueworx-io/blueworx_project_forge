<?php
/**
 * The studio's client sites screen.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Sites\Registry;
use Blueworx\Forge\Sites\SecurityLog;

/**
 * Connecting a client site, and cutting one off, in the browser.
 *
 * Everything here already existed as REST routes (#83). What did not exist was
 * a way to use it without hand-crafting an authenticated API call, which made
 * connecting a real site a developer job rather than an administrator's.
 *
 * Deliberately a plain WordPress admin screen, the same shape as the client
 * plugin's: this is an operational tool for us, not part of the product's
 * designed interface, and it should not wait on that design or carry a build
 * step of its own.
 *
 * The key is shown once, on the screen that issues it. It is never stored
 * anywhere it can be read back, and never travels in a URL — see IssuedKey.
 */
final class SitesScreen {

	/**
	 * The admin page slug.
	 */
	public const SLUG = 'blueworx-forge-sites';

	/**
	 * Handle of the design token stylesheet.
	 */
	public const STYLE = 'blueworx-forge-tokens';

	/**
	 * Adds the menu entry.
	 */
	public static function register(): void {
		add_menu_page(
			__( 'Forge', 'blueworx-forge' ),
			__( 'Forge', 'blueworx-forge' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' ),
			'dashicons-hammer',
			58
		);
	}

	/**
	 * Loads the design tokens, on this screen only (#85, #193).
	 *
	 * @param string $hook The screen being loaded.
	 */
	public static function enqueue( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}

		$tokens = BWX_FORGE_PATH . 'tokens/forge.css';

		if ( ! file_exists( $tokens ) ) {
			return;
		}

		wp_enqueue_style(
			self::STYLE,
			BWX_FORGE_URL . 'tokens/forge.css',
			array(),
			(string) filemtime( $tokens )
		);
	}

	/**
	 * This screen's URL, optionally carrying a result to report.
	 *
	 * @param string $notice A result code, or an empty string.
	 * @return string
	 */
	public static function url( string $notice = '' ): string {
		$url = admin_url( 'admin.php?page=' . self::SLUG );

		return '' === $notice ? $url : add_query_arg( 'bwx-result', $notice, $url );
	}

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Forge — client sites', 'blueworx-forge' ) . '</h1>';

		self::result_notice();
		self::issued_key();
		self::sites_table();
		self::register_form();
		self::security_log();

		echo '</div>';
	}

	/**
	 * The outcome of the last action, if there was one.
	 */
	private static function result_notice(): void {
		// A result code chosen from the fixed list below, never free text: it
		// comes off the URL, so anything it can say is something anyone can make
		// an administrator's screen say.
		$result = isset( $_GET['bwx-result'] ) ? sanitize_key( wp_unslash( $_GET['bwx-result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reporting the outcome of an action that carried its own nonce.

		$messages = array(
			'registered' => array( 'success', __( 'Site registered. Its key is shown once, below.', 'blueworx-forge' ) ),
			'rotated'    => array( 'success', __( 'A new key has been issued. The old one stopped working immediately.', 'blueworx-forge' ) ),
			'revoked'    => array( 'success', __( 'That site has been cut off. It keeps its key; the studio now refuses it.', 'blueworx-forge' ) ),
			'unknown'    => array( 'error', __( 'No such site.', 'blueworx-forge' ) ),
			'invalid'    => array( 'error', __( 'A name and a web address are both needed.', 'blueworx-forge' ) ),
		);

		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s" data-bwx-result="%2$s"><p>%3$s</p></div>',
			esc_attr( $messages[ $result ][0] ),
			esc_attr( $result ),
			esc_html( $messages[ $result ][1] )
		);
	}

	/**
	 * A key that has just been issued, shown for the only time.
	 */
	private static function issued_key(): void {
		$issued = IssuedKey::take( get_current_user_id() );

		if ( null === $issued ) {
			return;
		}

		echo '<div class="notice notice-warning" data-bwx-issued-key="1">';
		echo '<p><strong>' . esc_html__( 'Copy this key now. It cannot be shown again.', 'blueworx-forge' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Site id', 'blueworx-forge' ) . ': <code data-bwx-site-id="1">' . esc_html( $issued['site_id'] ) . '</code></p>';
		echo '<p>' . esc_html__( 'Key', 'blueworx-forge' ) . ': <code data-bwx-key="1">' . esc_html( $issued['key'] ) . '</code></p>';
		echo '<p class="description">' . esc_html__( 'Paste both into the client site. If the key is lost, issue a new one — there is nowhere to look it up.', 'blueworx-forge' ) . '</p>';
		echo '</div>';
	}

	/**
	 * The registered sites, and what can be done to each.
	 */
	private static function sites_table(): void {
		$sites = Registry::all();

		if ( array() === $sites ) {
			echo '<p data-bwx-no-sites="1">' . esc_html__( 'No client sites are connected yet.', 'blueworx-forge' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped" data-bwx-sites="1"><thead><tr>';
		echo '<th>' . esc_html__( 'Site', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Address', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Site id', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'blueworx-forge' ) . '</th>';
		echo '</tr></thead><tbody>';

		// Keyed by id: Registry::all() returns the id as the array key, and the
		// record itself is not guaranteed to repeat it.
		foreach ( $sites as $key => $site ) {
			$site_id = (string) $key;
			$status  = (string) ( $site['status'] ?? '' );

			echo '<tr data-bwx-site="' . esc_attr( $site_id ) . '">';
			echo '<td>' . esc_html( (string) ( $site['name'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $site['url'] ?? '' ) ) . '</td>';
			echo '<td><code>' . esc_html( $site_id ) . '</code></td>';
			echo '<td data-bwx-status="' . esc_attr( $status ) . '">' . esc_html( $status ) . '</td>';
			echo '<td>';
			self::action_button( 'bwx_forge_rotate_site', $site_id, __( 'Issue a new key', 'blueworx-forge' ), false );

			if ( Registry::STATUS_REVOKED !== $status ) {
				self::action_button( 'bwx_forge_revoke_site', $site_id, __( 'Cut off', 'blueworx-forge' ), true );
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * One button that does something, as a form rather than a link.
	 *
	 * A link would make these actions reachable by putting a URL in front of an
	 * administrator, which for "cut this client off" is not a theoretical worry.
	 *
	 * @param string $action  The admin-post action.
	 * @param string $site_id The site acted on.
	 * @param string $label   Button label.
	 * @param bool   $confirm Whether to ask first.
	 */
	private static function action_button( string $action, string $site_id, string $label, bool $confirm ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		wp_nonce_field( $action . '_' . $site_id );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '">';
		echo '<button type="submit" class="button" data-bwx-action="' . esc_attr( $action ) . '"';

		if ( $confirm ) {
			echo ' onclick="return confirm(' . esc_attr( (string) wp_json_encode( __( 'Cut this site off from the studio?', 'blueworx-forge' ) ) ) . ')"';
		}

		echo '>' . esc_html( $label ) . '</button> ';
		echo '</form>';
	}

	/**
	 * The form that registers a new site.
	 */
	private static function register_form(): void {
		echo '<h2>' . esc_html__( 'Connect a client site', 'blueworx-forge' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-register="1">';
		wp_nonce_field( 'bwx_forge_register_site' );
		echo '<input type="hidden" name="action" value="bwx_forge_register_site">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row"><label for="bwx-site-name">' . esc_html__( 'Client name', 'blueworx-forge' ) . '</label></th>';
		echo '<td><input type="text" id="bwx-site-name" name="name" class="regular-text" required></td></tr>';
		echo '<tr><th scope="row"><label for="bwx-site-url">' . esc_html__( 'Site address', 'blueworx-forge' ) . '</label></th>';
		echo '<td><input type="url" id="bwx-site-url" name="url" class="regular-text" placeholder="https://" required></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Register this site', 'blueworx-forge' ) );
		echo '</form>';
	}

	/**
	 * Requests the studio refused, most recent first.
	 *
	 * On this screen because it is the screen where you would look: a client
	 * site reporting that it cannot connect is answered here, and the reason a
	 * request was refused is deliberately not told to the caller (#83).
	 */
	private static function security_log(): void {
		$refused = SecurityLog::recent( 10 );

		echo '<h2>' . esc_html__( 'Refused requests', 'blueworx-forge' ) . '</h2>';

		if ( array() === $refused ) {
			echo '<p data-bwx-no-refusals="1">' . esc_html__( 'Nothing has been refused.', 'blueworx-forge' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped" data-bwx-refusals="1"><thead><tr>';
		echo '<th>' . esc_html__( 'When', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Site id claimed', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Why', 'blueworx-forge' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $refused as $entry ) {
			$when = (int) ( $entry['time'] ?? 0 );

			echo '<tr>';
			echo '<td>' . esc_html(
				$when > 0
					/* translators: %s: human-readable duration, e.g. "2 mins". */
					? sprintf( __( '%s ago', 'blueworx-forge' ), human_time_diff( $when ) )
					: ''
			) . '</td>';
			echo '<td><code>' . esc_html( (string) ( $entry['site_id'] ?? '' ) ) . '</code></td>';
			echo '<td>' . esc_html( (string) ( $entry['reason'] ?? '' ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}
}
