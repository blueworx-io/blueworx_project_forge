<?php
/**
 * Tests for the route a client site reads its own submissions back from.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Rest\Boundary;
use Blueworx\Forge\Rest\Permissions;
use Blueworx\Forge\Rest\Server;
use PHPUnit\Framework\TestCase;

/**
 * #130. Reading back what this site asked for.
 *
 * The read sits on the same route as the write, because they are the same
 * collection seen from two directions. That makes one thing worth proving that
 * a route on its own would not need: adding the read must not have taken the
 * write away.
 */
final class ClientSubmissionStatusRouteTest extends TestCase {

	/**
	 * Every endpoint registered on the submissions route.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function endpoints(): array {
		Server::register_routes();

		return array_values(
			array_filter(
				$GLOBALS['bwx_forge_test_routes'],
				static fn( array $route ): bool => '/client/submissions' === $route['route']
			)
		);
	}

	/**
	 * The endpoint answering one method.
	 *
	 * @param string $method GET or POST.
	 * @return array<string, mixed>
	 */
	private function endpoint( string $method ): array {
		foreach ( $this->endpoints() as $route ) {
			if ( $method === ( $route['args']['methods'] ?? '' ) ) {
				return $route;
			}
		}

		$this->fail( sprintf( '/client/submissions has no %s endpoint', $method ) );
	}

	/**
	 * A client site can read its submissions back.
	 */
	public function test_the_submissions_route_answers_a_read(): void {
		$this->assertIsArray( $this->endpoint( 'GET' ) );
	}

	/**
	 * The regression that sharing a route invites: the send must still be there.
	 */
	public function test_the_send_survives_the_read_being_added(): void {
		$this->assertIsArray( $this->endpoint( 'POST' ) );
	}

	/**
	 * A machine calls this, so the guard is the signature, never a capability.
	 */
	public function test_the_read_is_guarded_by_the_client_site_signature(): void {
		$this->assertSame(
			array( Permissions::class, 'client_site' ),
			$this->endpoint( 'GET' )['args']['permission_callback']
		);
	}

	/**
	 * Outside the tenancy boundary for the same written reason as its
	 * neighbours: the signature names the caller, so the signature is the
	 * boundary (ARCH-6).
	 */
	public function test_the_read_is_open_with_its_reason_written_down(): void {
		$scope = $this->endpoint( 'GET' )['args']['scope'];

		$this->assertSame( Boundary::SCOPE_OPEN, $scope['kind'] );
		$this->assertNotSame( '', trim( (string) $scope['reason'] ) );
	}

	/**
	 * The one that matters. Nothing on this read names a site or a client, so
	 * there is nothing on it to edit into somebody else's (D-2).
	 */
	public function test_the_read_takes_no_parameter_naming_a_client_or_site(): void {
		$args = (array) ( $this->endpoint( 'GET' )['args']['args'] ?? array() );

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
