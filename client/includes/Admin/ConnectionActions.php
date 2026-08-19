<?php
/**
 * What the connection screen's buttons do.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

use Blueworx\Forge\Client\Connection;

/**
 * Saving and forgetting this site's studio credentials.
 *
 * Separate from the screen because these change state and that one does not.
 * Both require `manage_options` on this site — the client's own administrator
 * configuring their own WordPress, which is a different thing entirely from the
 * per-site key that authenticates this site to the studio.
 */
final class ConnectionActions {

	/**
	 * Hooks the handlers up.
	 */
	public static function boot(): void {
		add_action( 'admin_post_bwx_forge_client_connect', array( self::class, 'connect' ) );
		add_action( 'admin_post_bwx_forge_client_disconnect', array( self::class, 'disconnect' ) );
	}

	/**
	 * Stores what the studio issued.
	 */
	public static function connect(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_client_connect' );

		$fixed = Connection::fixed();

		$studio_url = isset( $_POST['studio_url'] ) ? esc_url_raw( wp_unslash( $_POST['studio_url'] ) ) : '';
		$site_id    = isset( $_POST['site_id'] ) ? sanitize_text_field( wp_unslash( $_POST['site_id'] ) ) : '';
		$key        = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';

		// Anything wp-config.php sets wins, and the form does not offer it. Read
		// the stored value back for those, so saving the form does not blank
		// what it never showed.
		$studio_url = $fixed['studio_url'] ? Connection::studio_url() : $studio_url;
		$site_id    = $fixed['site_id'] ? Connection::site_id() : $site_id;

		// An empty key field means "leave the stored key alone", not "delete the
		// key" — the field is never filled in for editing, so an untouched form
		// posts it empty every time.
		if ( $fixed['key'] || '' === $key ) {
			$key = Connection::key();
		}

		if ( '' === $studio_url || '' === $site_id || '' === $key ) {
			self::back( 'incomplete' );
		}

		Connection::store( $studio_url, $site_id, $key );

		self::back( 'connected' );
	}

	/**
	 * Forgets them.
	 */
	public static function disconnect(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_client_disconnect' );

		Connection::forget();

		self::back( 'disconnected' );
	}

	/**
	 * Refuses anyone who does not administer this site.
	 *
	 * The nonce check sits in each handler rather than here, because the coding
	 * standard only recognises a nonce checked in the same function as the form
	 * data it protects.
	 */
	private static function require_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to change this site\'s connection.', 'blueworx-forge' ),
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
		wp_safe_redirect( ConnectionScreen::url( $result ) );
		exit;
	}
}
