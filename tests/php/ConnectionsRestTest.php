<?php
/**
 * Who may reach the Connections routes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Rest\ConnectionsController;
use Blueworx\Forge\Tenancy\ManagerRole;
use PHPUnit\Framework\TestCase;

/**
 * #621. Connecting a service and seeing the servers are an administrator's
 * alone: a Forge Manager is refused every route here.
 */
final class ConnectionsRestTest extends TestCase {

	/**
	 * The routes, by path and method.
	 *
	 * @return array<string, callable>
	 */
	private function permissions(): array {
		$GLOBALS['bwx_forge_test_routes'] = array();
		ConnectionsController::register_routes( 'blueworx-forge/v1' );

		$found = array();

		foreach ( $GLOBALS['bwx_forge_test_routes'] as $route ) {
			$found[ $route['args']['methods'] . ' ' . $route['route'] ] = $route['args']['permission_callback'];
		}

		return $found;
	}

	public function test_the_routes_exist(): void {
		$routes = array_keys( $this->permissions() );

		foreach ( array( 'GET /connections', 'POST /connections/cloudways', 'DELETE /connections/cloudways', 'GET /servers' ) as $expected ) {
			$this->assertContains( $expected, $routes );
		}
	}

	public function test_a_manager_is_refused_every_route(): void {
		$GLOBALS['bwx_forge_test_can'] = array( ManagerRole::USE );

		foreach ( $this->permissions() as $name => $permission ) {
			$this->assertFalse( (bool) call_user_func( $permission, null ), $name );
		}
	}

	public function test_an_administrator_is_let_through(): void {
		$GLOBALS['bwx_forge_test_can'] = array( ManagerRole::USE, 'manage_options' );

		foreach ( $this->permissions() as $name => $permission ) {
			$this->assertTrue( (bool) call_user_func( $permission, null ), $name );
		}
	}
}
