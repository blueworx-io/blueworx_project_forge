<?php
/**
 * What the network does when the studio asks for a client's site.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Network;

use Blueworx\Forge\Client\Access;
use Blueworx\Forge\Client\Connection;
use WP_Error;
use WP_User;

/**
 * #621. Three steps, each its own signed call so the studio can show them as
 * they happen and try again from the one that failed:
 *
 * 1. **Create** a site at a checked address. Its owner is one of the
 *    network's administrators, who has access already, so nobody new is ever
 *    made an administrator — let alone a super admin. The site is marked with
 *    the Forge site it was made for, and asking again for the same Forge site
 *    answers with the site already made.
 * 2. **Link** it to its Forge site: the client plugin's own connection is
 *    stored on the new site, so nobody copies a key by hand.
 * 3. **Logins**: each of the client's people, found by email so somebody
 *    already on the network is added rather than given a second account.
 *    Client admins get Editor and Forge: Manager; viewers get Forge: Manager.
 *    New accounts get WordPress's own set-password email; no password is ever
 *    sent or shown.
 *
 * Linking and logins act only on a site this class made for that same Forge
 * site, so the studio's key cannot be used to add people to the network's
 * main site or to anybody else's.
 */
final class Sites {

	/**
	 * Blog option marking a site as made by Forge, holding which Forge site.
	 */
	public const MARK = 'bwx_forge_network_site_id';

	/**
	 * Addresses WordPress keeps for itself, or that would confuse people.
	 */
	private const RESERVED = array( 'www', 'page', 'comments', 'blog', 'files', 'feed', 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'embed', 'admin', 'forge' );

	/**
	 * What each access the studio may ask for is made of on the new site.
	 */
	private const ACCESS = array(
		'editor'  => array( 'editor', Access::ROLE ),
		'manager' => array( Access::ROLE ),
	);

	/**
	 * Whether an address is one this route will make: lowercase letters,
	 * digits and inner hyphens, at most 63 characters, not reserved.
	 *
	 * @param string $slug The address.
	 * @return bool
	 */
	public static function valid_slug( string $slug ): bool {
		return 1 === preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $slug ) && ! in_array( $slug, self::RESERVED, true );
	}

	/**
	 * The roles a kind of access gives. Anything not named gives nothing.
	 *
	 * @param string $access 'editor' or 'manager'.
	 * @return array<int, string>
	 */
	public static function roles_for( string $access ): array {
		return self::ACCESS[ $access ] ?? array();
	}

	/**
	 * Makes the site, or finds the one already made for this Forge site.
	 *
	 * @param string $slug    The address.
	 * @param string $title   The site's title.
	 * @param string $site_id The Forge site it is for.
	 * @return array{blog_id: int, url: string, admin_url: string, existed: bool}|WP_Error
	 */
	public static function create( string $slug, string $title, string $site_id ) {
		if ( ! is_multisite() ) {
			return self::error( 'not_a_network', __( 'This WordPress is not a multisite network.', 'blueworx-forge' ), 409 );
		}

		$title = trim( $title );

		if ( ! self::valid_slug( $slug ) || '' === $title || strlen( $title ) > 200 || 1 !== preg_match( '/^[A-Za-z0-9_\-]+$/', $site_id ) ) {
			return self::error( 'invalid_site', __( 'The address or title cannot be used.', 'blueworx-forge' ), 400 );
		}

		$network = get_network();

		if ( is_subdomain_install() ) {
			$domain = $slug . '.' . preg_replace( '/^www\./', '', (string) $network->domain );
			$path   = (string) $network->path;
		} else {
			$domain = (string) $network->domain;
			$path   = (string) $network->path . $slug . '/';
		}

		$existing = (int) get_blog_id_from_url( $domain, $path );

		if ( $existing > 0 ) {
			if ( (string) get_blog_option( $existing, self::MARK, '' ) !== $site_id ) {
				return self::error( 'address_taken', __( 'That address is already taken on the network.', 'blueworx-forge' ), 409 );
			}

			return self::made( $existing, true );
		}

		$owner = self::owner();

		if ( 0 === $owner ) {
			return self::error( 'no_owner', __( 'The network has no administrator to own the new site.', 'blueworx-forge' ), 409 );
		}

		$blog_id = wp_insert_site(
			array(
				'domain'  => $domain,
				'path'    => $path,
				'title'   => $title,
				'user_id' => $owner,
			)
		);

		if ( is_wp_error( $blog_id ) ) {
			return self::error( 'create_failed', $blog_id->get_error_message(), 500 );
		}

		update_blog_option( (int) $blog_id, self::MARK, $site_id );

		return self::made( (int) $blog_id, false );
	}

	/**
	 * Links a site this class made to its Forge site.
	 *
	 * @param int    $blog_id          The site.
	 * @param string $site_id          The Forge site it was made for.
	 * @param string $studio_url       The studio's address.
	 * @param string $registry_site_id The site id the studio issued.
	 * @param string $key              The key the studio issued.
	 * @return true|WP_Error
	 */
	public static function link( int $blog_id, string $site_id, string $studio_url, string $registry_site_id, string $key ) {
		if ( ! self::ours( $blog_id, $site_id ) ) {
			return self::not_ours();
		}

		if ( 1 !== preg_match( '#^https?://#', $studio_url ) || 1 !== preg_match( '/^[A-Za-z0-9_\-]+$/', $registry_site_id ) || 1 !== preg_match( '/^[A-Za-z0-9]{32,128}$/', $key ) ) {
			return self::error( 'invalid_link', __( 'The connection details cannot be used.', 'blueworx-forge' ), 400 );
		}

		switch_to_blog( $blog_id );

		try {
			Connection::store( $studio_url, $registry_site_id, $key );
		} finally {
			restore_current_blog();
		}

		return true;
	}

	/**
	 * Gives the client's people their logins on a site this class made.
	 *
	 * @param int                              $blog_id The site.
	 * @param string                           $site_id The Forge site it was made for.
	 * @param array<int, array<string, mixed>> $people  email, name, access.
	 * @return array<int, array{email: string, outcome: string, reason: string}>|WP_Error
	 */
	public static function people( int $blog_id, string $site_id, array $people ) {
		if ( ! self::ours( $blog_id, $site_id ) ) {
			return self::not_ours();
		}

		$outcomes = array();

		switch_to_blog( $blog_id );

		try {
			// The role is made on a site the first time it is loaded; this
			// one may never have been, and nobody can be given a role that
			// does not exist yet.
			Access::ensure_role();

			foreach ( $people as $person ) {
				$outcomes[] = self::one( $blog_id, (array) $person );
			}
		} finally {
			restore_current_blog();
		}

		return $outcomes;
	}

	/**
	 * Whether a site was made here for this Forge site.
	 *
	 * @param int    $blog_id The site.
	 * @param string $site_id The Forge site.
	 * @return bool
	 */
	public static function ours( int $blog_id, string $site_id ): bool {
		return is_multisite() && $blog_id > 0 && '' !== $site_id && (string) get_blog_option( $blog_id, self::MARK, '' ) === $site_id;
	}

	/**
	 * One person's login.
	 *
	 * @param int                  $blog_id The site.
	 * @param array<string, mixed> $person  email, name, access.
	 * @return array{email: string, outcome: string, reason: string}
	 */
	private static function one( int $blog_id, array $person ): array {
		$email = strtolower( trim( (string) ( $person['email'] ?? '' ) ) );
		$roles = self::roles_for( (string) ( $person['access'] ?? '' ) );

		if ( ! is_email( $email ) ) {
			return self::outcome( $email, 'failed', __( 'Not an email address.', 'blueworx-forge' ) );
		}

		if ( array() === $roles ) {
			return self::outcome( $email, 'failed', __( 'No access was named for them.', 'blueworx-forge' ) );
		}

		$user    = get_user_by( 'email', $email );
		$created = false;

		if ( false === $user ) {
			$user_id = wpmu_create_user( self::login_for( $email ), wp_generate_password( 32, true, true ), $email );

			if ( false === $user_id ) {
				return self::outcome( $email, 'failed', __( 'The account could not be made.', 'blueworx-forge' ) );
			}

			$created = true;
		} else {
			$user_id = (int) $user->ID;
		}

		add_user_to_blog( $blog_id, (int) $user_id, $roles[0] );

		$wp_user = new WP_User( (int) $user_id );

		foreach ( array_slice( $roles, 1 ) as $role ) {
			$wp_user->add_role( $role );
		}

		if ( $created ) {
			// WordPress's own email, with a link to set a password. Nothing
			// else ever carries one.
			wp_new_user_notification( (int) $user_id, null, 'user' );
		}

		return self::outcome( $email, $created ? 'created' : 'added', '' );
	}

	/**
	 * A login nobody on the network has, made from the email.
	 *
	 * @param string $email The email.
	 * @return string
	 */
	private static function login_for( string $email ): string {
		$base = substr( sanitize_user( (string) strstr( $email, '@', true ), true ), 0, 50 );
		$base = '' === $base ? 'client' : $base;
		$name = $base;

		$n = 2;

		while ( false !== get_user_by( 'login', $name ) ) {
			$name = $base . $n;
			++$n;
		}

		return $name;
	}

	/**
	 * The network administrator who owns new sites: the first super admin.
	 *
	 * @return int 0 when there is none.
	 */
	private static function owner(): int {
		foreach ( get_super_admins() as $login ) {
			$user = get_user_by( 'login', (string) $login );

			if ( false !== $user ) {
				return (int) $user->ID;
			}
		}

		return 0;
	}

	/**
	 * The answer for a site made, or found.
	 *
	 * @param int  $blog_id The site.
	 * @param bool $existed Whether it was already there.
	 * @return array{blog_id: int, url: string, admin_url: string, existed: bool}
	 */
	private static function made( int $blog_id, bool $existed ): array {
		return array(
			'blog_id'   => $blog_id,
			'url'       => get_home_url( $blog_id ),
			'admin_url' => get_admin_url( $blog_id ),
			'existed'   => $existed,
		);
	}

	/**
	 * One person's outcome.
	 *
	 * @param string $email   Email.
	 * @param string $outcome created, added or failed.
	 * @param string $reason  Why it failed, or ''.
	 * @return array{email: string, outcome: string, reason: string}
	 */
	private static function outcome( string $email, string $outcome, string $reason ): array {
		return array(
			'email'   => $email,
			'outcome' => $outcome,
			'reason'  => $reason,
		);
	}

	/**
	 * The refusal for a site this class did not make for that Forge site.
	 *
	 * @return WP_Error
	 */
	private static function not_ours(): WP_Error {
		return self::error( 'not_ours', __( 'That site was not made by Forge for this client site.', 'blueworx-forge' ), 404 );
	}

	/**
	 * An error with a status the route answers with.
	 *
	 * @param string $code    Short code.
	 * @param string $message What to tell the studio.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	private static function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( 'bwx_forge_network_' . $code, $message, array( 'status' => $status ) );
	}
}
