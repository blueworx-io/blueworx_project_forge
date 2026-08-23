<?php
/**
 * The route a client site asks for things through.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Rest\Boundary;
use Blueworx\Forge\Rest\Permissions;
use Blueworx\Forge\Rest\Server;
use PHPUnit\Framework\TestCase;

/**
 * #129. A client can ask for something, and what they sent cannot be changed
 * afterwards.
 *
 * The immutability test here is the one that matters, and it is written as an
 * absence rather than a rule. A rule saying "do not edit submissions" is a rule
 * somebody writes a reasonable-looking exception to eighteen months from now.
 * A route that does not exist cannot be called.
 */
final class ClientSubmissionRouteTest extends TestCase {

	/**
	 * Every registered route.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function routes(): array {
		Server::register_routes();

		return $GLOBALS['bwx_forge_test_routes'];
	}

	/**
	 * One route by path and method.
	 *
	 * @param string $path   The route path.
	 * @param string $method The HTTP method.
	 * @return array<string, mixed>|null
	 */
	private function route( string $path, string $method ): ?array {
		foreach ( $this->routes() as $route ) {
			if ( $path === $route['route'] && $method === ( $route['args']['methods'] ?? '' ) ) {
				return $route;
			}
		}

		return null;
	}

	// -----------------------------------------------------------------------
	// Asking.
	// -----------------------------------------------------------------------

	/**
	 * A client site can send a submission.
	 */
	public function test_a_client_site_can_send_a_submission(): void {
		$this->assertNotNull( $this->route( '/client/submissions', 'POST' ) );
	}

	/**
	 * Guarded by the signature, like every other route a machine calls. A
	 * capability check here would be asking a logged-in person for permission
	 * on a request no person makes.
	 */
	public function test_the_submission_route_is_guarded_by_the_client_site_signature(): void {
		$route = $this->route( '/client/submissions', 'POST' );

		$this->assertSame(
			array( Permissions::class, 'client_site' ),
			$route['args']['permission_callback']
		);
	}

	/**
	 * Outside the tenancy boundary with its reason written down, for the same
	 * reason the other client routes are (ARCH-6).
	 */
	public function test_the_submission_route_is_open_with_its_reason_written_down(): void {
		$scope = $this->route( '/client/submissions', 'POST' )['args']['scope'];

		$this->assertSame( Boundary::SCOPE_OPEN, $scope['kind'] );
		$this->assertNotSame( '', trim( (string) $scope['reason'] ) );
	}

	// -----------------------------------------------------------------------
	// And never un-asking.
	// -----------------------------------------------------------------------

	/**
	 * REQ-1: the submission is fixed at submission. Nothing anywhere in the API
	 * updates or deletes one, so a client cannot quietly rewrite what they
	 * asked for once they have seen the answer — and neither can anybody else.
	 */
	public function test_no_route_anywhere_edits_or_deletes_a_submission(): void {
		$writes = array();

		foreach ( $this->routes() as $route ) {
			$path   = (string) $route['route'];
			$method = (string) ( $route['args']['methods'] ?? '' );

			if ( ! str_contains( $path, 'submission' ) ) {
				continue;
			}

			if ( in_array( $method, array( 'PUT', 'PATCH', 'DELETE' ), true ) ) {
				$writes[] = $method . ' ' . $path;
			}
		}

		$this->assertSame( array(), $writes );
	}

	/**
	 * And nothing on the way in names a client or a site. The site that signed
	 * the request is the only site a submission can be from (D-2).
	 */
	public function test_the_submission_route_takes_no_parameter_naming_a_client_or_site(): void {
		$args = (array) ( $this->route( '/client/submissions', 'POST' )['args']['args'] ?? array() );

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
