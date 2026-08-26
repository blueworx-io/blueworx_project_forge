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
	 * REQ-1: what the client wrote is fixed at submission.
	 *
	 * This was written for #129 as an absence — no route anywhere updates or
	 * deletes a submission — because a route that does not exist cannot be
	 * called, and that is a stronger guarantee than a rule somebody writes a
	 * reasonable-looking exception to.
	 *
	 * #131 is that exception, and it is the one the brief always intended: the
	 * studio has to be able to answer. So the absence has narrowed rather than
	 * gone. Nothing replaces a submission wholesale and nothing deletes one —
	 * both still tested here, as absences. What a triage write may touch is an
	 * allowlist of the studio's own two columns, tested in
	 * WorkSubmissionTriageTest, and the client's words are not on it.
	 */
	public function test_nothing_replaces_or_deletes_a_submission(): void {
		$writes = array();

		foreach ( $this->routes() as $route ) {
			$path   = (string) $route['route'];
			$method = (string) ( $route['args']['methods'] ?? '' );

			if ( ! str_contains( $path, 'submission' ) ) {
				continue;
			}

			if ( in_array( $method, array( 'PUT', 'DELETE' ), true ) ) {
				$writes[] = $method . ' ' . $path;
			}
		}

		$this->assertSame( array(), $writes );
	}

	/**
	 * And the one write that does exist is the studio's, not the client's.
	 *
	 * The client interface reaches Forge through `/client/…` routes and only
	 * those. A PATCH appearing under that prefix would mean a client site could
	 * reach the triage write — which is the shape REQ-1 is actually protecting
	 * against, now that a triage write exists at all.
	 */
	public function test_no_client_route_writes_to_a_submission(): void {
		$writes = array();

		foreach ( $this->routes() as $route ) {
			$path   = (string) $route['route'];
			$method = (string) ( $route['args']['methods'] ?? '' );

			if ( ! str_starts_with( $path, '/client/' ) || ! str_contains( $path, 'submission' ) ) {
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
