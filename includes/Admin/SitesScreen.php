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
 * A WordPress admin screen rather than a screen in the application, per ARCH-7:
 * this is the plumbing between us and a site, not work anybody does for a
 * client. It is built from the shared admin design system, like every other
 * studio screen.
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

		Page::open(
			__( 'Client sites', 'blueworx-forge' ),
			__( 'Forge', 'blueworx-forge' ),
			__( 'The sites the studio looks after, and the keys that let them talk to it.', 'blueworx-forge' )
		);

		self::result_notice();
		self::issued_key();

		Page::panel_open( __( 'Connect a client site', 'blueworx-forge' ), 'register' );
		self::register_form();
		Page::panel_close();

		Page::panel_open( __( 'Connected sites', 'blueworx-forge' ), 'sites' );
		self::sites_table();
		Page::panel_close();

		Page::panel_open( __( 'Refused requests', 'blueworx-forge' ), 'refusals' );
		self::security_log();
		Page::panel_close();

		Page::close();
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
			'unknown'    => array( 'danger', __( 'No such site.', 'blueworx-forge' ) ),
			'invalid'    => array( 'danger', __( 'A name and a web address are both needed.', 'blueworx-forge' ) ),
		);

		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}

		Page::notice(
			$messages[ $result ][0],
			$messages[ $result ][1],
			array( 'data-bwx-result' => $result )
		);
	}

	/**
	 * A key that has just been issued, shown for the only time.
	 *
	 * Taken rather than read, so the next page load has nothing to show. The
	 * key is never stored anywhere it can be read back and never travels in a
	 * URL; that is IssuedKey's job, and none of it changes here.
	 */
	private static function issued_key(): void {
		$issued = IssuedKey::take( get_current_user_id() );

		if ( null === $issued ) {
			return;
		}

		/*
		 * The id and the key are the notice rather than a sentence about it, so
		 * they are markup, and everything interpolated is escaped here.
		 *
		 * Elements that carry text rather than readonly inputs, for two reasons
		 * that both bite: the specs read the key with innerText, which an input
		 * has none of, and wp_kses_post — which Page::notice runs markup
		 * through — drops an <input> out of a notice entirely. The look of a
		 * mono field comes from the class instead.
		 */
		$text = sprintf(
			'<strong>%1$s</strong><br>%2$s <code class="bw-input bw-input--mono" data-bwx-site-id="1">%3$s</code><br>%4$s <code class="bw-input bw-input--mono" data-bwx-key="1">%5$s</code><br>%6$s',
			esc_html__( 'Copy this key now. It cannot be shown again.', 'blueworx-forge' ),
			esc_html__( 'Site id', 'blueworx-forge' ),
			esc_html( $issued['site_id'] ),
			esc_html__( 'Key', 'blueworx-forge' ),
			esc_html( $issued['key'] ),
			esc_html__( 'Paste both into the client site. If the key is lost, issue a new one — there is nowhere to look it up.', 'blueworx-forge' )
		);

		Page::notice( 'success', $text, array( 'data-bwx-issued-key' => '1' ), true );
	}

	/**
	 * The registered sites, and what can be done to each.
	 */
	private static function sites_table(): void {
		$sites = Registry::all();

		if ( array() === $sites ) {
			echo '<div class="bw-empty" data-bwx-no-sites="1">';
			echo '<i class="bw-icon bw-empty__icon" data-lucide="plug"></i>';
			echo '<p class="bw-empty__title">' . esc_html__( 'No client sites are connected yet', 'blueworx-forge' ) . '</p>';
			echo '<p class="bw-empty__text">' . esc_html__( 'Connect one above, then paste the key it is given into that site.', 'blueworx-forge' ) . '</p>';
			echo '</div>';

			return;
		}

		echo '<div class="bw-tablescroll">';
		echo '<table class="bw-table" data-bwx-sites="1"><thead><tr>';
		echo '<th>' . esc_html__( 'Site', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Address', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Site id', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'blueworx-forge' ) . '</th>';
		echo '<th class="bw-table__actions">' . esc_html__( 'Actions', 'blueworx-forge' ) . '</th>';
		echo '</tr></thead><tbody>';

		// Keyed by id: Registry::all() returns the id as the array key, and the
		// record itself is not guaranteed to repeat it.
		foreach ( $sites as $key => $site ) {
			$site_id = (string) $key;
			$status  = (string) ( $site['status'] ?? '' );

			echo '<tr data-bwx-site="' . esc_attr( $site_id ) . '">';
			echo '<td class="bw-table__primary">' . esc_html( (string) ( $site['name'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $site['url'] ?? '' ) ) . '</td>';
			echo '<td><code class="bw-input--mono">' . esc_html( $site_id ) . '</code></td>';
			echo '<td>' . self::status_badge( $status ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- status_badge escapes everything it writes.
			echo '<td class="bw-table__actions"><div class="bw-rowactions">';
			self::action_button( 'bwx_forge_rotate_site', $site_id, __( 'Issue a new key', 'blueworx-forge' ), false );

			if ( Registry::STATUS_REVOKED !== $status ) {
				self::action_button( 'bwx_forge_revoke_site', $site_id, __( 'Cut off', 'blueworx-forge' ), true );
			}

			echo '</div></td></tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * A site's status, toned by what it means.
	 *
	 * A site that has been cut off is the one somebody may have to do something
	 * about, so that is the danger tone; a connected site needs no colour at
	 * all. Whole class names, so the admin UI check can read them.
	 *
	 * @param string $status One of Registry's status constants.
	 * @return string
	 */
	private static function status_badge( string $status ): string {
		$classes = array(
			Registry::STATUS_ACTIVE  => 'bw-badge',
			Registry::STATUS_REVOKED => 'bw-badge bw-badge--danger',
		);

		return sprintf(
			'<span class="%1$s" data-bwx-status="%2$s">%3$s</span>',
			esc_attr( $classes[ $status ] ?? 'bw-badge bw-badge--neutral' ),
			esc_attr( $status ),
			esc_html( $status )
		);
	}

	/**
	 * One action, as a form rather than a link.
	 *
	 * A link would make these actions reachable by putting a URL in front of an
	 * administrator, which for "cut this client off" is not a theoretical worry.
	 * It is dressed as a row action; it is still a posted form.
	 *
	 * @param string $action  The admin-post action.
	 * @param string $site_id The site acted on.
	 * @param string $label   Button label.
	 * @param bool   $confirm Whether to ask first.
	 */
	private static function action_button( string $action, string $site_id, string $label, bool $confirm ): void {
		// Whole class names rather than a stem with a modifier appended: the
		// admin UI check reads the classes a screen writes, and one assembled
		// from a variable is one it cannot see. Asking first and being the
		// dangerous one are the same action here.
		$class = $confirm ? 'bw-rowactions__link bw-rowactions__link--danger' : 'bw-rowactions__link';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( $action . '_' . $site_id );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="site_id" value="' . esc_attr( $site_id ) . '">';
		echo '<button type="submit" class="' . esc_attr( $class ) . '" data-bwx-action="' . esc_attr( $action ) . '"';

		if ( $confirm ) {
			echo ' onclick="return confirm(' . esc_attr( (string) wp_json_encode( __( 'Cut this site off from the studio?', 'blueworx-forge' ) ) ) . ')"';
		}

		echo '>' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * The form that registers a new site.
	 */
	private static function register_form(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-register="1">';
		wp_nonce_field( 'bwx_forge_register_site' );
		echo '<input type="hidden" name="action" value="bwx_forge_register_site">';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-site-name">' . esc_html__( 'Client name', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control">';
		echo '<input type="text" id="bwx-site-name" name="name" class="bw-input" required>';
		echo '</div></div>';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-site-url">' . esc_html__( 'Site address', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control">';
		echo '<input type="url" id="bwx-site-url" name="url" class="bw-input" placeholder="https://" required>';
		echo '<p class="bw-formrow__help">' . esc_html__( 'The address the client site is served from.', 'blueworx-forge' ) . '</p>';
		echo '</div></div>';

		// submit_button() rather than a <button>, and this is not cosmetic:
		// the specs click `input[type="submit"]`, so the element is as much
		// part of the contract as a data-bwx hook is. What changes is the class
		// it carries.
		echo '<div class="bw-savebar">';
		submit_button( __( 'Register this site', 'blueworx-forge' ), 'bw-btn bw-btn--primary', 'submit', false );
		echo '</div>';
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

		if ( array() === $refused ) {
			echo '<div class="bw-empty" data-bwx-no-refusals="1">';
			echo '<i class="bw-icon bw-empty__icon" data-lucide="shield"></i>';
			echo '<p class="bw-empty__title">' . esc_html__( 'Nothing has been refused', 'blueworx-forge' ) . '</p>';
			echo '<p class="bw-empty__text">' . esc_html__( 'Every request that reached the studio was one it recognised.', 'blueworx-forge' ) . '</p>';
			echo '</div>';

			return;
		}

		echo '<div class="bw-tablescroll">';
		echo '<table class="bw-table" data-bwx-refusals="1"><thead><tr>';
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
			echo '<td><code class="bw-input--mono">' . esc_html( (string) ( $entry['site_id'] ?? '' ) ) . '</code></td>';
			echo '<td>' . esc_html( (string) ( $entry['reason'] ?? '' ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}
}
