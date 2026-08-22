<?php
/**
 * Tests for the route a client site reads its board from.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Rest\Boundary;
use Blueworx\Forge\Rest\Permissions;
use Blueworx\Forge\Rest\Server;
use PHPUnit\Framework\TestCase;

/**
 * #128. The board a client site reads is answered for the site that signed the
 * request, and there is no other way to ask.
 *
 * The generic sweeps in TenantBoundaryTest already refuse a route with no scope
 * or no reason. What is specific here is the shape of this one: it is a read,
 * it is authenticated by a signature rather than a capability, and it names no
 * client — so there is nothing on it to edit into somebody else's.
 */
final class ClientBoardRouteTest extends TestCase {

	/**
	 * The route as registered.
	 *
	 * @return array<string, mixed>
	 */
	private function route(): array {
		Server::register_routes();

		foreach ( $GLOBALS['bwx_forge_test_routes'] as $route ) {
			if ( '/client/board' === $route['route'] ) {
				return $route;
			}
		}

		$this->fail( '/client/board is not registered' );
	}

	/**
	 * It exists, and it is a read.
	 */
	public function test_the_board_route_is_a_read(): void {
		$this->assertSame( 'GET', $this->route()['args']['methods'] );
	}

	/**
	 * A machine calls this, so the guard is the signature, never a capability.
	 * A capability check here would be asking a logged-in person for permission
	 * on a request no person makes.
	 */
	public function test_the_board_route_is_guarded_by_the_client_site_signature(): void {
		$this->assertSame(
			array( Permissions::class, 'client_site' ),
			$this->route()['args']['permission_callback']
		);
	}

	/**
	 * Outside the tenancy boundary for the same written reason the other client
	 * routes are: the signature names the caller, so the signature is the
	 * boundary (ARCH-6).
	 */
	public function test_the_board_route_is_open_with_its_reason_written_down(): void {
		$scope = $this->route()['args']['scope'];

		$this->assertSame( Boundary::SCOPE_OPEN, $scope['kind'] );
		$this->assertNotSame( '', trim( (string) $scope['reason'] ) );
	}

	/**
	 * The one that matters. No parameter names a site or a client, because the
	 * only site this can answer for is the one that signed it — and a parameter
	 * that could name another is a parameter somebody will try (D-2).
	 */
	public function test_the_board_route_takes_no_parameter_naming_a_client_or_site(): void {
		$args = (array) ( $this->route()['args']['args'] ?? array() );

		$naming = array_values(
			array_filter(
				array_keys( $args ),
				static fn( $name ): bool => str_contains( (string) $name, 'client' )
					|| str_contains( (string) $name, 'site' )
			)
		);

		$this->assertSame( array(), $naming );
	}
}
