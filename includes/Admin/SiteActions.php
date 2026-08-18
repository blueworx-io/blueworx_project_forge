<?php
/**
 * What the client sites screen's buttons do.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Sites\Registry;

/**
 * The three actions behind the screen: register, rotate, revoke.
 *
 * Separate from the screen that renders them because these change state and
 * that one does not, and because each has the same three guards to get right:
 * the capability, the nonce, then the work. They post to admin-post.php and
 * redirect, so a refresh re-renders the result rather than registering a second
 * site.
 *
 * The permission is `manage_options`, matching "register or revoke a client
 * site key" in docs/architecture/permission-matrix.md, and matching the REST
 * routes that do the same things (#83) — one rule, two doors.
 */
final class SiteActions {

	/**
	 * Hooks the three handlers up.
	 */
	public static function boot(): void {
		add_action( 'admin_post_bwx_forge_register_site', array( self::class, 'register_site' ) );
		add_action( 'admin_post_bwx_forge_rotate_site', array( self::class, 'rotate_site' ) );
		add_action( 'admin_post_bwx_forge_revoke_site', array( self::class, 'revoke_site' ) );
	}

	/**
	 * Registers a site and holds its key for the one screen that shows it.
	 */
	public static function register_site(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_register_site' );

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$url  = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

		if ( '' === $name || '' === $url ) {
			self::back( 'invalid' );
		}

		$site = Registry::register( $name, $url );

		IssuedKey::remember( get_current_user_id(), $site['site_id'], $site['key'] );

		self::back( 'registered' );
	}

	/**
	 * Issues a new key, which stops the old one working immediately.
	 */
	public static function rotate_site(): void {
		$site_id = self::site_id();

		self::require_admin();
		check_admin_referer( 'bwx_forge_rotate_site_' . $site_id );

		$key = Registry::rotate( $site_id );

		if ( null === $key ) {
			// Rotate returns nothing for a site that does not exist, and for one
			// that has been cut off — reissuing a key to a revoked site would
			// quietly undo the cutting off.
			self::back( 'unknown' );
		}

		IssuedKey::remember( get_current_user_id(), $site_id, (string) $key );

		self::back( 'rotated' );
	}

	/**
	 * Cuts a site off.
	 */
	public static function revoke_site(): void {
		$site_id = self::site_id();

		self::require_admin();
		check_admin_referer( 'bwx_forge_revoke_site_' . $site_id );

		self::back( Registry::revoke( $site_id ) ? 'revoked' : 'unknown' );
	}

	/**
	 * The site id being acted on.
	 *
	 * Read before the nonce is checked, because the nonce name contains it — so
	 * it is treated as untrusted text and sanitised on the way in. A wrong or
	 * invented id simply names a nonce that does not verify.
	 *
	 * @return string
	 */
	private static function site_id(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- names the nonce the caller then verifies; see the note above.
		return isset( $_POST['site_id'] ) ? sanitize_text_field( wp_unslash( $_POST['site_id'] ) ) : '';
	}

	/**
	 * Refuses anyone who is not an administrator.
	 *
	 * The nonce check sits in each handler rather than in here beside this one,
	 * where it would read better: the coding standard's sniff only recognises a
	 * nonce checked in the same function as the form data it protects, and a
	 * suppression comment saying "it is checked elsewhere, honestly" is exactly
	 * the thing that stops being true.
	 */
	private static function require_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to manage client sites.', 'blueworx-forge' ),
				'',
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Returns to the screen with the outcome, and stops.
	 *
	 * @param string $result One of the result codes the screen knows.
	 */
	private static function back( string $result ): void {
		wp_safe_redirect( SitesScreen::url( $result ) );
		exit;
	}
}
