<?php
/**
 * What the people screen's forms do.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Tenancy\Clients;
use Blueworx\Forge\Tenancy\Memberships;
use Blueworx\Forge\Tenancy\Users;
use Blueworx\Forge\Tenancy\Validate;

/**
 * Adding a person, editing one, offboarding one, and granting or ending access
 * to a client (#90).
 *
 * Every write goes through Tenancy\Validate — the same rules the REST routes
 * apply — so the screen and the API cannot disagree about what is refused, and
 * every edit quotes the record's current version so the screen obeys the same
 * stale-write rule (ARCH-5) the API does.
 *
 * The membership handlers live here rather than in ClientActions even though
 * their forms are on the clients screen: they are about who somebody is, the
 * checks are the same ones the person handlers make, and splitting them would
 * put the cross-client guard in two files.
 */
final class PeopleActions {

	/**
	 * Hooks the handlers up.
	 */
	public static function boot(): void {
		add_action( 'admin_post_bwx_forge_add_person', array( self::class, 'add_person' ) );
		add_action( 'admin_post_bwx_forge_edit_person', array( self::class, 'edit_person' ) );
		add_action( 'admin_post_bwx_forge_offboard_person', array( self::class, 'offboard_person' ) );
		add_action( 'admin_post_bwx_forge_add_membership', array( self::class, 'add_membership' ) );
		add_action( 'admin_post_bwx_forge_end_membership', array( self::class, 'end_membership' ) );
	}

	/**
	 * Adds a person.
	 */
	public static function add_person(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_add_person' );

		$input = array(
			'display_name' => self::field( 'display_name' ),
			'email'        => self::field( 'email' ),
		);

		$checked = Validate::user( $input, false );

		if ( array() !== $checked['errors'] ) {
			self::back( 'invalid' );
		}

		// Told apart from any other failure, because this one has a fix somebody
		// can act on: that person already exists, go and give them a membership.
		if ( null !== Users::by_email( (string) $checked['values']['email'] ) ) {
			self::back( 'duplicate' );
		}

		if ( null === Users::create( $checked['values'], get_current_user_id() ) ) {
			self::back( 'invalid' );
		}

		self::back( 'added' );
	}

	/**
	 * Edits a person, including bringing an offboarded one back.
	 */
	public static function edit_person(): void {
		$user_id = self::field( 'user_id' );

		self::require_admin();
		check_admin_referer( 'bwx_forge_edit_person_' . $user_id );

		$user = Users::get( $user_id );

		if ( null === $user ) {
			self::back( 'unknown' );
		}

		$input = array(
			'display_name' => self::field( 'display_name' ),
			'email'        => self::field( 'email' ),
			'status'       => self::field( 'status' ),
		);

		$checked = Validate::user( $input, true );

		if ( array() !== $checked['errors'] ) {
			self::back( 'invalid' );
		}

		if ( array_key_exists( 'email', $checked['values'] ) ) {
			$holder = Users::by_email( (string) $checked['values']['email'] );

			// Moving somebody onto an address that is already somebody else's
			// would merge two people into one row's worth of history.
			if ( null !== $holder && $holder['id'] !== $user['id'] ) {
				self::back( 'duplicate' );
			}
		}

		$version = (int) self::field( 'record_version' );

		// Offboarding goes through deactivate(), which ends every membership
		// with it. A plain update would leave a closed account holding live
		// access to every client.
		$updated = 'inactive' === (string) ( $checked['values']['status'] ?? '' )
			? Users::deactivate( $user['id'], $version, $checked['values'] )
			: Users::update( $user['id'], $checked['values'], $version );

		self::back( null === $updated ? 'stale' : 'added' );
	}

	/**
	 * Offboards somebody: the account and every membership, in one action.
	 */
	public static function offboard_person(): void {
		$user_id = self::field( 'user_id' );

		self::require_admin();
		check_admin_referer( 'bwx_forge_offboard_person_' . $user_id );

		if ( null === Users::get( $user_id ) ) {
			self::back( 'unknown' );
		}

		$version = (int) self::field( 'record_version' );

		self::back( null === Users::deactivate( $user_id, $version ) ? 'stale' : 'added' );
	}

	/**
	 * Gives somebody a role on a client, or on one site beneath it.
	 */
	public static function add_membership(): void {
		$client_id = self::field( 'client_id' );

		self::require_admin();
		check_admin_referer( 'bwx_forge_add_membership_' . $client_id );

		$client = Clients::get( $client_id );
		$user   = Users::get( self::field( 'user_id' ) );

		if ( null === $client || null === $user ) {
			self::back( 'unknown' );
		}

		$checked = Validate::membership(
			array(
				'role'           => self::field( 'role' ),
				'client_site_id' => self::field( 'client_site_id' ),
			),
			false
		);

		if ( array() !== $checked['errors'] ) {
			self::back( 'invalid' );
		}

		// The cross-client guard, in the same words as the REST route: a
		// membership naming another client's site would grant one client's
		// person access to another's work.
		if ( null !== self::scope_error( (string) $checked['values']['client_site_id'], $client_id ) ) {
			self::back( 'invalid' );
		}

		$domain_error = Validate::domain_error(
			(string) $user['email'],
			(string) $checked['values']['role'],
			(array) $client['email_domains']
		);

		if ( null !== $domain_error ) {
			self::back( 'invalid' );
		}

		$membership = Memberships::create( $user['id'], $client_id, $checked['values'], get_current_user_id() );

		self::back( null === $membership ? 'invalid' : 'added' );
	}

	/**
	 * Ends one membership. The row stays, so what they did while they held it
	 * still resolves.
	 */
	public static function end_membership(): void {
		$membership_id = self::field( 'membership_id' );

		self::require_admin();
		check_admin_referer( 'bwx_forge_end_membership_' . $membership_id );

		if ( null === Memberships::get( $membership_id ) ) {
			self::back( 'unknown' );
		}

		$version = (int) self::field( 'record_version' );

		self::back( null === Memberships::deactivate( $membership_id, $version ) ? 'stale' : 'added' );
	}

	/**
	 * Whether a named site belongs to the client the membership is on.
	 *
	 * @param string $client_site_id The site named, or '' for the whole client.
	 * @param string $client_id      The client.
	 * @return string|null The refusal, or null when there is nothing to refuse.
	 */
	private static function scope_error( string $client_site_id, string $client_id ): ?string {
		if ( '' === $client_site_id ) {
			return null;
		}

		$site = \Blueworx\Forge\Tenancy\ClientSites::get( $client_site_id );

		if ( null === $site || (string) $site['client_id'] !== $client_id ) {
			return 'There is no such client site.';
		}

		return null;
	}

	/**
	 * One posted field, sanitised.
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	private static function field( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- names the nonce the caller then verifies; see the note below.
		return isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
	}

	/**
	 * Refuses anyone who is not an administrator.
	 *
	 * The nonce check sits in each handler rather than beside this one, where it
	 * would read better: the coding standard's sniff only recognises a nonce
	 * checked in the same function as the form data it protects.
	 */
	private static function require_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to manage people.', 'blueworx-forge' ),
				'',
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Returns to the screen the form was on, with the outcome, and stops.
	 *
	 * A membership form lives on the clients screen and a person form on the
	 * people screen, so the redirect follows the form rather than the handler.
	 *
	 * @param string $result One of the result codes the screens know.
	 */
	private static function back( string $result ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the handler that got here verified its own nonce first.
		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';

		$on_clients_screen = in_array(
			$action,
			array( 'bwx_forge_add_membership', 'bwx_forge_end_membership' ),
			true
		);

		wp_safe_redirect( $on_clients_screen ? ClientsScreen::url( $result ) : PeopleScreen::url( $result ) );
		exit;
	}
}
