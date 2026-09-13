<?php
/**
 * Who may use Forge on a client site.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * Two capabilities and one role.
 *
 * Every screen, the workspace page and every REST route used to ask for
 * `manage_options`, which made "can use Forge" and "is an Administrator" the
 * same question. They are not: a client's project lead should run the work
 * without being able to break the site. So Forge asks for its own
 * capabilities, and who holds them is decided here and nowhere else.
 *
 * Administrators hold both, granted on the fly through `user_has_cap` so
 * nothing has to be written to the database for a site that already has
 * the plugin. The Forge: Manager role holds USE only; it is created on
 * activation and repaired on every version change, so an existing site
 * gets it on update without anybody reactivating anything.
 */
final class Access {

	/**
	 * Every screen but the connection, the workspace page, and every REST
	 * route that reads or writes work.
	 */
	public const USE = 'bwx_forge_client_use';

	/**
	 * The Connection screen, its actions, and the connection REST routes.
	 */
	public const CONNECT = 'bwx_forge_client_connect';

	/**
	 * The role slug and the name people see in Users.
	 */
	public const ROLE      = 'forge_manager';
	public const ROLE_NAME = 'Forge: Manager';

	/**
	 * Remembers which plugin version last checked the role exists.
	 */
	private const ROLE_OPTION = 'bwx_forge_client_role_version';

	/**
	 * Hooks the grant and the role repair up.
	 */
	public static function boot(): void {
		add_filter( 'user_has_cap', array( self::class, 'grant' ), 10, 4 );
		add_action( 'init', array( self::class, 'ensure_role_on_version_change' ) );
	}

	/**
	 * Administrators hold both capabilities. Pure; the filter below feeds it.
	 *
	 * @param array<string, bool> $allcaps What the user already has.
	 * @param array<int, string>  $roles   The user's roles.
	 * @return array<string, bool>
	 */
	public static function with_forge_caps( array $allcaps, array $roles ): array {
		if ( in_array( 'administrator', $roles, true ) ) {
			$allcaps[ self::USE ]     = true;
			$allcaps[ self::CONNECT ] = true;
		}

		return $allcaps;
	}

	/**
	 * What the Forge: Manager role is made of. Pure.
	 *
	 * `read` is what lets somebody into wp-admin at all; without it WordPress
	 * sends them to the front of the site.
	 *
	 * @return array<string, bool>
	 */
	public static function role_caps(): array {
		return array(
			'read'    => true,
			self::USE => true,
		);
	}

	/**
	 * The `user_has_cap` filter.
	 *
	 * @param array<string, bool> $allcaps What the user has.
	 * @param array<int, string>  $caps    The capabilities being asked about (unused).
	 * @param array<int, mixed>   $args    The arguments to current_user_can (unused).
	 * @param \WP_User            $user    The user.
	 * @return array<string, bool>
	 */
	public static function grant( $allcaps, $caps, $args, $user ): array {
		$roles = is_object( $user ) && isset( $user->roles ) && is_array( $user->roles ) ? $user->roles : array();

		return self::with_forge_caps( is_array( $allcaps ) ? $allcaps : array(), $roles );
	}

	/**
	 * Creates the role, or brings an existing one back to exactly role_caps().
	 */
	public static function ensure_role(): void {
		$role = get_role( self::ROLE );

		if ( null === $role ) {
			add_role( self::ROLE, self::ROLE_NAME, self::role_caps() );
			return;
		}

		foreach ( self::role_caps() as $cap => $grant ) {
			$role->add_cap( $cap, $grant );
		}

		// A role somebody widened by hand is narrowed back: the connection is
		// the one thing this role exists to keep away from.
		$role->remove_cap( self::CONNECT );
		$role->remove_cap( 'manage_options' );
	}

	/**
	 * Runs ensure_role() once per plugin version, so a site that updated
	 * without reactivating still gets the role.
	 */
	public static function ensure_role_on_version_change(): void {
		if ( get_option( self::ROLE_OPTION ) === BWX_FORGE_CLIENT_VERSION ) {
			return;
		}

		self::ensure_role();
		update_option( self::ROLE_OPTION, BWX_FORGE_CLIENT_VERSION );
	}
}
