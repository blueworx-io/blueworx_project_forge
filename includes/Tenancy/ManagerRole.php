<?php
/**
 * Who may get into Forge on the studio site.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

/**
 * #406. One capability and one role: the Forge: Manager.
 *
 * Only administrators and Managers get into Forge. A Manager is one of our
 * people who is not a WordPress administrator; what they then reach is
 * decided by their memberships, exactly as before.
 *
 * Separate from the client plugin's role of the same name
 * (client/includes/Access.php). The two run on different sites and never
 * meet; this one has its own capability so neither can stand in for the other.
 *
 * Administrators hold the capability through `user_has_cap`, so nothing is
 * written for them. Our people are given the role when they become staff and
 * lose it when they stop (sync_person()); the accounts that existed before
 * this were given it once, by upgrade().
 */
final class ManagerRole {

	/**
	 * Getting into Forge: the app page and every signed-in route.
	 */
	public const USE = 'bwx_forge_use';

	/**
	 * The role slug and the name people see in Users.
	 */
	public const ROLE      = 'forge_manager';
	public const ROLE_NAME = 'Forge: Manager';

	/**
	 * Which plugin version, and which definition of the role, it was last
	 * checked against.
	 */
	private const ROLE_OPTION = 'bwx_forge_role_version';

	/**
	 * Set once the existing staff accounts have been given the role.
	 */
	private const UPGRADE_OPTION = 'bwx_forge_manager_upgrade_done';

	/**
	 * Hooks the grant, the role repair and the keeping in step.
	 */
	public static function boot(): void {
		add_filter( 'user_has_cap', array( self::class, 'grant' ), 10, 4 );
		add_action( 'init', array( self::class, 'ensure_role_on_version_change' ) );
		add_action( 'init', array( self::class, 'maybe_upgrade' ), 11 );
		add_action( 'bwx_forge_access_changed', array( self::class, 'sync_person' ) );
		add_action( 'bwx_forge_account_unlinked', array( self::class, 'sync_account' ) );
		add_filter( 'login_redirect', array( self::class, 'after_sign_in' ), 10, 3 );
	}

	/**
	 * Where somebody lands after signing in. A Manager who asked for nowhere
	 * in particular goes straight to Forge; everybody else as WordPress
	 * decided. Pure.
	 *
	 * @param string $decided   Where WordPress would send them.
	 * @param string $requested Where they asked to go, if anywhere.
	 * @param bool   $manager   Whether they are a Manager and not an administrator.
	 * @param string $forge     The Forge app page.
	 * @param string $admin     The wp-admin address.
	 * @return string
	 */
	public static function landing( string $decided, string $requested, bool $manager, string $forge, string $admin ): string {
		if ( ! $manager ) {
			return $decided;
		}

		$asked = rtrim( $requested, '/' );

		return '' === $asked || rtrim( $admin, '/' ) === $asked ? $forge : $decided;
	}

	/**
	 * The `login_redirect` filter.
	 *
	 * @param string             $redirect_to Where WordPress would send them.
	 * @param string             $requested   Where they asked to go.
	 * @param \WP_User|\WP_Error $user        Who signed in.
	 * @return string
	 */
	public static function after_sign_in( $redirect_to, $requested, $user ): string {
		$manager = $user instanceof \WP_User && $user->has_cap( self::USE ) && ! $user->has_cap( 'manage_options' );

		return self::landing(
			(string) $redirect_to,
			(string) $requested,
			$manager,
			\Blueworx\Forge\Frontend::instance()->app_page_url(),
			admin_url()
		);
	}

	/**
	 * Administrators hold the capability: the administrator role, or anybody
	 * who can manage the site, so the door agrees with Permissions::manage().
	 * Pure; the filter feeds it.
	 *
	 * @param array<string, bool> $allcaps What the user already has.
	 * @param array<int, string>  $roles   The user's roles.
	 * @return array<string, bool>
	 */
	public static function with_forge_caps( array $allcaps, array $roles ): array {
		if ( in_array( 'administrator', $roles, true ) || ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ self::USE ] = true;
		}

		return $allcaps;
	}

	/**
	 * What the role is made of. Pure.
	 *
	 * `read` lets a Manager into wp-admin for their own profile, and nothing
	 * else there.
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
	 * Whether a person should hold the role: active, and one of our people
	 * (#405's rule — an active studio-side membership). Pure.
	 *
	 * @param string                           $status      The person's status.
	 * @param array<int, array<string, mixed>> $memberships Their memberships, any status.
	 * @return bool
	 */
	public static function should_hold( string $status, array $memberships ): bool {
		return 'active' === $status && Reach::is_studio_staff( $memberships );
	}

	/**
	 * The accounts the one-off upgrade gives the role to. Pure.
	 *
	 * @param array<int, array<string, mixed>> $people      People: id, status, wp_user_id.
	 * @param array<int, array<string, mixed>> $memberships Their memberships, any status.
	 * @param array<int, int>                  $admins      WordPress ids of administrators.
	 * @return array<int, int>
	 */
	public static function upgrade_accounts( array $people, array $memberships, array $admins ): array {
		$accounts = array();

		foreach ( $people as $person ) {
			$wp_id = (int) ( $person['wp_user_id'] ?? 0 );

			if ( $wp_id <= 0 || in_array( $wp_id, $admins, true ) ) {
				continue;
			}

			$held = array_values(
				array_filter(
					$memberships,
					static fn( array $row ): bool => (string) $row['user_id'] === (string) $person['id']
				)
			);

			if ( self::should_hold( (string) ( $person['status'] ?? '' ), $held ) ) {
				$accounts[] = $wp_id;
			}
		}

		return $accounts;
	}

	/**
	 * The `user_has_cap` filter.
	 *
	 * @param array<string, bool> $allcaps What the user has.
	 * @param array<int, string>  $caps    The capabilities asked about (unused).
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

		// A role somebody widened by hand is narrowed back: Managers never
		// run the site.
		$role->remove_cap( 'manage_options' );
	}

	/**
	 * Runs ensure_role() when the version or the role's definition changes.
	 */
	public static function ensure_role_on_version_change(): void {
		$state = BWX_FORGE_VERSION . ':' . md5( (string) wp_json_encode( self::role_caps() ) );

		if ( get_option( self::ROLE_OPTION ) === $state ) {
			return;
		}

		self::ensure_role();
		update_option( self::ROLE_OPTION, $state );
	}

	/**
	 * Keeps a person's account in step after their memberships or account
	 * changed: staff hold the role, anybody else loses it.
	 *
	 * @param string $person_id Forge person id.
	 */
	public static function sync_person( $person_id ): void {
		$person = Users::get( (string) $person_id );

		if ( null !== $person ) {
			self::sync_account( (int) $person['wp_user_id'] );
		}
	}

	/**
	 * Keeps one WordPress account in step with the Forge person behind it.
	 *
	 * Administrators are left alone: they get in anyway, and their roles are
	 * theirs to set. Only this role is ever added or taken; an account's
	 * other roles are never touched.
	 *
	 * @param int $wp_user_id WordPress user id.
	 */
	public static function sync_account( $wp_user_id ): void {
		$account = (int) $wp_user_id > 0 ? get_userdata( (int) $wp_user_id ) : false;

		if ( ! $account instanceof \WP_User || in_array( 'administrator', (array) $account->roles, true ) ) {
			return;
		}

		$person = Users::by_wp_user( (int) $wp_user_id );
		$staff  = null !== $person
			&& self::should_hold( (string) $person['status'], Memberships::for_user( (string) $person['id'], null ) );
		$holds  = in_array( self::ROLE, (array) $account->roles, true );

		if ( $staff && ! $holds ) {
			self::ensure_role_exists();
			$account->add_role( self::ROLE );
		} elseif ( ! $staff && $holds ) {
			$account->remove_role( self::ROLE );
		}
	}

	/**
	 * Runs upgrade() once per site.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::UPGRADE_OPTION ) ) {
			return;
		}

		// Marked done only when it really ran: a failed read would otherwise
		// leave staff locked out for good.
		if ( self::upgrade() ) {
			update_option( self::UPGRADE_OPTION, 1 );
		}
	}

	/**
	 * The one-off upgrade: every existing staff account that is not an
	 * administrator is given the role, so nobody is locked out. Their other
	 * roles stay as they were.
	 *
	 * @return bool False when the people could not be read.
	 */
	public static function upgrade(): bool {
		global $wpdb;

		self::ensure_role();

		$wpdb->last_error = '';
		$people           = Users::all( null );
		$memberships      = array();

		foreach ( $people as $person ) {
			$memberships = array_merge( $memberships, Memberships::for_user( (string) $person['id'], null ) );
		}

		if ( '' !== $wpdb->last_error ) {
			return false;
		}

		$admins = array_map(
			'intval',
			get_users(
				array(
					'role'   => 'administrator',
					'fields' => 'ID',
				)
			)
		);

		foreach ( self::upgrade_accounts( $people, $memberships, $admins ) as $wp_id ) {
			$account = get_userdata( $wp_id );

			if ( $account instanceof \WP_User && ! in_array( self::ROLE, (array) $account->roles, true ) ) {
				$account->add_role( self::ROLE );
			}
		}

		return true;
	}

	/**
	 * Makes the role before it is first handed out, on a site that has not
	 * reached init since the plugin was updated.
	 */
	private static function ensure_role_exists(): void {
		if ( null === get_role( self::ROLE ) ) {
			self::ensure_role();
		}
	}
}
