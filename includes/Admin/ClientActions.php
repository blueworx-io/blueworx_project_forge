<?php
/**
 * What the clients screen's forms do.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Clients;
use Blueworx\Forge\Tenancy\Validate;

/**
 * The four actions behind the clients screen: add a client, add a site under
 * one, and deactivate either. Separate from the screen that renders them
 * because these change state and that one does not, and because each has the
 * same three guards to get right: the capability, the nonce, then the work.
 *
 * Every write goes through Tenancy\Validate — the same rules the REST routes
 * apply (#83) — so the screen and the API cannot disagree about what is
 * refused, and every deactivation quotes the record's current version, so the
 * screen obeys the same stale-write rule (ARCH-5) the API does.
 */
final class ClientActions {

	/**
	 * Hooks the handlers up.
	 */
	public static function boot(): void {
		add_action( 'admin_post_bwx_forge_add_client', array( self::class, 'add_client' ) );
		add_action( 'admin_post_bwx_forge_add_client_site', array( self::class, 'add_client_site' ) );
		add_action( 'admin_post_bwx_forge_deactivate_client', array( self::class, 'deactivate_client' ) );
		add_action( 'admin_post_bwx_forge_deactivate_client_site', array( self::class, 'deactivate_client_site' ) );
	}

	/**
	 * Adds a client.
	 */
	public static function add_client(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_add_client' );

		$input = array(
			'display_name'  => isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '',
			'timezone'      => isset( $_POST['timezone'] ) ? sanitize_text_field( wp_unslash( $_POST['timezone'] ) ) : '',
			'email_domains' => isset( $_POST['email_domains'] ) ? sanitize_text_field( wp_unslash( $_POST['email_domains'] ) ) : '',
		);

		$checked = Validate::client( $input, false );

		if ( array() !== $checked['errors'] ) {
			self::back( 'invalid' );
		}

		Clients::create( $checked['values'], get_current_user_id() );

		self::back( 'added' );
	}

	/**
	 * Adds a site under a client.
	 */
	public static function add_client_site(): void {
		$client_id = self::field( 'client_id' );

		self::require_admin();
		check_admin_referer( 'bwx_forge_add_client_site_' . $client_id );

		if ( null === Clients::get( $client_id ) ) {
			self::back( 'unknown' );
		}

		$input = array(
			'name' => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'url'  => isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '',
		);

		$checked = Validate::site( $input, false );

		if ( array() !== $checked['errors'] ) {
			self::back( 'invalid' );
		}

		ClientSites::create( $client_id, $checked['values'], get_current_user_id() );

		self::back( 'added' );
	}

	/**
	 * Deactivates a client, and every site under it.
	 */
	public static function deactivate_client(): void {
		$client_id = self::field( 'client_id' );

		self::require_admin();
		check_admin_referer( 'bwx_forge_deactivate_client_' . $client_id );

		if ( null === Clients::get( $client_id ) ) {
			self::back( 'unknown' );
		}

		$version = (int) self::field( 'record_version' );

		self::back( null === Clients::deactivate( $client_id, $version ) ? 'stale' : 'added' );
	}

	/**
	 * Deactivates a single site.
	 */
	public static function deactivate_client_site(): void {
		$site_id = self::field( 'site_id' );

		self::require_admin();
		check_admin_referer( 'bwx_forge_deactivate_client_site_' . $site_id );

		if ( null === ClientSites::get( $site_id ) ) {
			self::back( 'unknown' );
		}

		$version = (int) self::field( 'record_version' );

		self::back( null === ClientSites::deactivate( $site_id, $version ) ? 'stale' : 'added' );
	}

	/**
	 * Reads one posted field.
	 *
	 * Read before the nonce is checked in the id case, because the nonce name
	 * contains it — so it is treated as untrusted text and sanitised on the way
	 * in. A wrong or invented id simply names a nonce that does not verify.
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	private static function field( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- names the nonce the caller then verifies; see the note above.
		return isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
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
				esc_html__( 'You are not allowed to manage clients.', 'blueworx-forge' ),
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
		wp_safe_redirect( ClientsScreen::url( $result ) );
		exit;
	}
}
