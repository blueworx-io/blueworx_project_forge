<?php
/**
 * What the clients screen's forms do.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Onboarding\Assignment;
use Blueworx\Forge\Onboarding\Templates;
use Blueworx\Forge\Rest\IntegrationsController;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Clients;
use Blueworx\Forge\Tenancy\Contacts;
use Blueworx\Forge\Tenancy\Users;
use Blueworx\Forge\Tenancy\Validate;
use WP_REST_Request;

/**
 * The actions behind the clients screen: add a client, add a site under one,
 * edit or deactivate either, and issue or revoke a site's connection key.
 * Separate from the screen that renders them because these change state and
 * that one does not, and because each has the same three guards to get right:
 * the capability, the nonce, then the work.
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
		add_action( 'admin_post_bwx_forge_edit_client', array( self::class, 'edit_client' ) );
		add_action( 'admin_post_bwx_forge_edit_client_site', array( self::class, 'edit_client_site' ) );
		add_action( 'admin_post_bwx_forge_issue_site_key', array( self::class, 'issue_site_key' ) );
		add_action( 'admin_post_bwx_forge_revoke_site_key', array( self::class, 'revoke_site_key' ) );
		add_action( 'admin_post_bwx_forge_assign_contact', array( self::class, 'assign_contact' ) );
		add_action( 'admin_post_bwx_forge_assign_onboarding', array( self::class, 'assign_onboarding' ) );
	}

	/**
	 * Issues a site's first key, or rotates the one it has (#89).
	 *
	 * The work is the REST controller's, called directly rather than
	 * reimplemented: registering, recording and the rules about inactive and
	 * revoked sites must be identical whichever door somebody came through, and
	 * two copies of that would eventually disagree.
	 *
	 * The key never reaches the redirect. It goes into the one-shot store the
	 * client sites screen already uses, and the screen takes it from there.
	 */
	public static function issue_site_key(): void {
		$site_id = self::field( 'site_id' );

		self::require_admin();
		check_admin_referer( 'bwx_forge_issue_site_key_' . $site_id );

		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'site_id', $site_id );

		$response = IntegrationsController::issue_key( $request );

		if ( is_wp_error( $response ) ) {
			self::back( 'bwx_forge_unknown_client_site' === $response->get_error_code() ? 'unknown' : 'invalid' );
		}

		$data = $response->get_data();

		IssuedKey::remember( get_current_user_id(), (string) $data['integration']['registry_site_id'], (string) $data['key'] );

		self::back( 'added' );
	}

	/**
	 * Cuts a site off.
	 */
	public static function revoke_site_key(): void {
		$site_id = self::field( 'site_id' );

		self::require_admin();
		check_admin_referer( 'bwx_forge_revoke_site_key_' . $site_id );

		$request = new WP_REST_Request( 'DELETE' );
		$request->set_param( 'site_id', $site_id );

		$response = IntegrationsController::revoke_key( $request );

		self::back( is_wp_error( $response ) ? 'invalid' : 'added' );
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

		// The raw value goes to Validate, unescaped: it and the REST route must
		// refuse the same input for the same reason, and esc_url_raw() rewrites
		// what is not a URL into one, which would let this door accept what the
		// API refuses.
		$input = array(
			'name' => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'url'  => isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '',
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
	 * Edits a client — every writable field, including status, so this is also
	 * how an inactive client is set back to active.
	 */
	public static function edit_client(): void {
		$client_id = self::field( 'client_id' );

		self::require_admin();
		check_admin_referer( 'bwx_forge_edit_client_' . $client_id );

		if ( null === Clients::get( $client_id ) ) {
			self::back( 'unknown' );
		}

		$input = array(
			'display_name'  => isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '',
			'legal_name'    => isset( $_POST['legal_name'] ) ? sanitize_text_field( wp_unslash( $_POST['legal_name'] ) ) : '',
			'timezone'      => isset( $_POST['timezone'] ) ? sanitize_text_field( wp_unslash( $_POST['timezone'] ) ) : '',
			'email_domains' => isset( $_POST['email_domains'] ) ? sanitize_text_field( wp_unslash( $_POST['email_domains'] ) ) : '',
			'status'        => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '',
		);

		$checked = Validate::client( $input, true );

		if ( array() !== $checked['errors'] ) {
			self::back( 'invalid' );
		}

		$version = (int) self::field( 'record_version' );

		$updated = 'inactive' === ( $checked['values']['status'] ?? '' )
			? Clients::deactivate( $client_id, $version, $checked['values'] )
			: Clients::update( $client_id, $checked['values'], $version );

		self::back( null === $updated ? 'stale' : 'added' );
	}

	/**
	 * Names our point of contact for a client (#95).
	 *
	 * Appends rather than overwrites: the previous contacts stay, so the answer
	 * to "who was looking after this in March" survives the person moving on.
	 * Naming nobody is one of the answers, and a real one — it is the difference
	 * between a client that has never had a contact and one whose contact left.
	 */
	public static function assign_contact(): void {
		$client_id = self::field( 'client_id' );

		self::require_admin();
		check_admin_referer( 'bwx_forge_assign_contact_' . $client_id );

		if ( null === Clients::get( $client_id ) ) {
			self::back( 'unknown' );
		}

		$user_id = self::field( 'user_id' );

		if ( '' !== $user_id ) {
			$person = Users::get( $user_id );

			// Somebody who has left cannot be made the contact. Recording it
			// would create the exact state the screen then flags as broken.
			if ( null === $person || 'active' !== (string) $person['status'] ) {
				self::back( 'invalid' );
			}
		}

		$assigned = Contacts::assign( $client_id, $user_id, get_current_user_id() );

		self::back( null === $assigned ? 'invalid' : 'added' );
	}

	/**
	 * Edits a client site — every writable field, including status, so this is
	 * also how an inactive site is set back to active.
	 */
	public static function edit_client_site(): void {
		$site_id = self::field( 'site_id' );

		self::require_admin();
		check_admin_referer( 'bwx_forge_edit_client_site_' . $site_id );

		if ( null === ClientSites::get( $site_id ) ) {
			self::back( 'unknown' );
		}

		// The raw value goes to Validate, unescaped — see the note in
		// add_client_site() above.
		$input = array(
			'name'   => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'url'    => isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '',
			'status' => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '',
		);

		$checked = Validate::site( $input, true );

		if ( array() !== $checked['errors'] ) {
			self::back( 'invalid' );
		}

		$version = (int) self::field( 'record_version' );

		self::back( null === ClientSites::update( $site_id, $checked['values'], $version ) ? 'stale' : 'added' );
	}

	/**
	 * Gives a site the current checklist (#160).
	 *
	 * Once, and then never again. ONB-1 fixes a client's checklist at the
	 * moment they are given it, so there is no handler here to change it
	 * afterwards — Assignment refuses a second one as well, because a rule kept
	 * only at the screen is a rule that lasts until the next caller.
	 */
	public static function assign_onboarding(): void {
		self::require_admin();

		$site_id = self::field( 'site_id' );

		check_admin_referer( 'bwx_forge_assign_onboarding_' . $site_id );

		$site = ClientSites::get( $site_id );

		if ( null === $site ) {
			self::back( 'unknown' );
		}

		$template = Templates::current();

		if ( null === $template ) {
			self::back( 'no-checklist' );
		}

		$assigned = Assignment::assign(
			$site_id,
			(string) $site['client_id'],
			(string) $template['id'],
			get_current_user_id()
		);

		self::back( null === $assigned ? 'already-onboarding' : 'onboarding-started' );
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
