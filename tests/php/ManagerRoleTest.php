<?php
/**
 * The Forge: Manager role on the studio site (#406).
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Rest\Permissions;
use Blueworx\Forge\Tenancy\ManagerRole;
use Blueworx\Forge\Tenancy\Roles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Who gets into Forge, and who is given the role, as rules.
 */
final class ManagerRoleTest extends TestCase {

	/**
	 * Resets the fake capabilities before each test.
	 */
	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_can'] = array();
	}

	/**
	 * Administrators get in without being given anything.
	 */
	public function test_administrators_hold_the_forge_capability(): void {
		$caps = ManagerRole::with_forge_caps( array( 'read' => true ), array( 'administrator' ) );

		$this->assertTrue( $caps[ ManagerRole::USE ] );
	}

	/**
	 * Nobody else is handed it by the filter.
	 */
	public function test_other_roles_are_not_handed_it(): void {
		foreach ( array( array( 'subscriber' ), array( 'editor' ), array() ) as $roles ) {
			$caps = ManagerRole::with_forge_caps( array( 'read' => true ), $roles );

			$this->assertArrayNotHasKey( ManagerRole::USE, $caps );
		}
	}

	/**
	 * The role is Forge and a way into wp-admin for their profile. Nothing
	 * that runs the site.
	 */
	public function test_the_role_holds_forge_and_read_only(): void {
		$this->assertSame(
			array(
				'read'           => true,
				ManagerRole::USE => true,
			),
			ManagerRole::role_caps()
		);
	}

	/**
	 * Separate from the client plugin's role: its own capability name.
	 */
	public function test_the_capability_is_the_studio_one(): void {
		$this->assertSame( 'bwx_forge_use', ManagerRole::USE );
		$this->assertSame( 'forge_manager', ManagerRole::ROLE );
	}

	/**
	 * Our people hold it; a client's people, somebody offboarded and
	 * somebody whose access ended do not.
	 *
	 * @param string                           $status      Person status.
	 * @param array<int, array<string, mixed>> $memberships Their memberships.
	 * @param bool                             $expected    Whether they hold it.
	 */
	#[DataProvider( 'holders' )]
	public function test_who_holds_the_role( string $status, array $memberships, bool $expected ): void {
		$this->assertSame( $expected, ManagerRole::should_hold( $status, $memberships ) );
	}

	/**
	 * The cases.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function holders(): array {
		$row = static fn( string $role, string $status = 'active' ): array => array(
			'role'   => $role,
			'status' => $status,
		);

		return array(
			'staff'                => array( 'active', array( $row( Roles::STAFF ) ), true ),
			'internal viewer'      => array( 'active', array( $row( Roles::INTERNAL_VIEWER ) ), true ),
			'client admin'         => array( 'active', array( $row( Roles::CLIENT_ADMIN ) ), false ),
			'client viewer'        => array( 'active', array( $row( Roles::CLIENT_VIEWER ) ), false ),
			'no memberships'       => array( 'active', array(), false ),
			'staff access ended'   => array( 'active', array( $row( Roles::STAFF, 'inactive' ) ), false ),
			'offboarded'           => array( 'inactive', array( $row( Roles::STAFF ) ), false ),
			'staff on one, client' => array( 'active', array( $row( Roles::CLIENT_VIEWER ), $row( Roles::STAFF ) ), true ),
		);
	}

	/**
	 * The upgrade gives the role to linked accounts of our people, and skips
	 * administrators, unlinked people and anybody who is not staff.
	 */
	public function test_the_upgrade_picks_linked_staff_who_are_not_administrators(): void {
		$people = array(
			array( 'id' => 'usr_staff', 'status' => 'active', 'wp_user_id' => 11 ),
			array( 'id' => 'usr_admin', 'status' => 'active', 'wp_user_id' => 1 ),
			array( 'id' => 'usr_client', 'status' => 'active', 'wp_user_id' => 12 ),
			array( 'id' => 'usr_unlinked', 'status' => 'active', 'wp_user_id' => 0 ),
			array( 'id' => 'usr_gone', 'status' => 'inactive', 'wp_user_id' => 13 ),
		);

		$memberships = array(
			array( 'user_id' => 'usr_staff', 'role' => Roles::STAFF, 'status' => 'active' ),
			array( 'user_id' => 'usr_admin', 'role' => Roles::STAFF, 'status' => 'active' ),
			array( 'user_id' => 'usr_client', 'role' => Roles::CLIENT_ADMIN, 'status' => 'active' ),
			array( 'user_id' => 'usr_unlinked', 'role' => Roles::STAFF, 'status' => 'active' ),
			array( 'user_id' => 'usr_gone', 'role' => Roles::STAFF, 'status' => 'active' ),
		);

		$this->assertSame( array( 11 ), ManagerRole::upgrade_accounts( $people, $memberships, array( 1 ) ) );
	}

	/**
	 * Getting into Forge is its own capability.
	 */
	public function test_forge_user_asks_for_the_forge_capability(): void {
		$this->assertFalse( Permissions::forge_user() );

		$GLOBALS['bwx_forge_test_can'] = array( 'read', 'edit_posts' );
		$this->assertFalse( Permissions::forge_user() );

		$GLOBALS['bwx_forge_test_can'] = array( ManagerRole::USE );
		$this->assertTrue( Permissions::forge_user() );
	}

	/**
	 * Every route asks for it, except the ones a client site signs and the
	 * product's own public shape.
	 */
	public function test_which_routes_are_behind_the_door(): void {
		$this->assertFalse( Permissions::behind_the_door( array( Permissions::class, 'client_site' ) ) );
		$this->assertFalse( Permissions::behind_the_door( array( Permissions::class, 'read' ) ) );

		$this->assertTrue( Permissions::behind_the_door( array( Permissions::class, 'manage' ) ) );
		$this->assertTrue( Permissions::behind_the_door( array( Permissions::class, 'signed_in' ) ) );
		$this->assertTrue( Permissions::behind_the_door( array( Permissions::class, 'manage_or_self' ) ) );
		$this->assertTrue( Permissions::behind_the_door( array( 'Some\\Controller', 'may_read' ) ) );
		$this->assertTrue( Permissions::behind_the_door( '__return_true' ) );
	}

	/**
	 * Registering puts the door in front of the route's own check, and a
	 * signed client-site route is left exactly as it was.
	 */
	public function test_registering_puts_the_door_in_front(): void {
		$GLOBALS['bwx_forge_test_routes'] = array();

		$open = array(
			'kind'   => \Blueworx\Forge\Rest\Boundary::SCOPE_OPEN,
			'reason' => 'A fixture.',
		);

		\Blueworx\Forge\Rest\Server::register_route(
			'blueworx-forge/v1',
			'/staffed',
			array(
				'methods'             => 'GET',
				'callback'            => '__return_true',
				'permission_callback' => static fn(): bool => true,
				'scope'               => $open,
			)
		);
		\Blueworx\Forge\Rest\Server::register_route(
			'blueworx-forge/v1',
			'/signed',
			array(
				'methods'             => 'GET',
				'callback'            => '__return_true',
				'permission_callback' => array( Permissions::class, 'client_site' ),
				'scope'               => $open,
			)
		);

		list( $staffed, $signed ) = $GLOBALS['bwx_forge_test_routes'];

		$this->assertSame( array( Permissions::class, 'client_site' ), $signed['args']['permission_callback'] );

		// Without the Forge capability the route's own "yes" is never asked.
		$this->assertFalse( call_user_func( $staffed['args']['permission_callback'], null ) );

		$GLOBALS['bwx_forge_test_can'] = array( ManagerRole::USE );
		$this->assertTrue( call_user_func( $staffed['args']['permission_callback'], null ) );
	}
}
