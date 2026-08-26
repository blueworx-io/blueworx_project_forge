<?php
/**
 * Tests for the routes behind the studio's request review queue.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Rest\Boundary;
use Blueworx\Forge\Rest\Server;
use PHPUnit\Framework\TestCase;

/**
 * #131. The queue's two routes: read the queue, write the studio's answer.
 *
 * What matters at this level is the declaration rather than the behaviour —
 * that the read declares itself a list scope, so the boundary knows the
 * callback is responsible for filtering by reach, and that the write is guarded
 * by the capability rather than by being signed in. Both are the kind of
 * mistake that reviews miss and tests do not.
 */
final class WorkQueueRouteTest extends TestCase {

	/**
	 * Clears the recorded routes.
	 */
	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_routes'] = array();
	}

	/**
	 * The endpoint on a route answering one method.
	 *
	 * @param string $route  Route path.
	 * @param string $method GET or PATCH.
	 * @return array<string, mixed>
	 */
	private function endpoint( string $route, string $method ): array {
		Server::register_routes();

		foreach ( $GLOBALS['bwx_forge_test_routes'] as $registered ) {
			if ( $route === $registered['route'] && $method === ( $registered['args']['methods'] ?? '' ) ) {
				return $registered;
			}
		}

		$this->fail( sprintf( '%s has no %s endpoint', $route, $method ) );
	}

	/**
	 * The queue can be read.
	 */
	public function test_the_queue_route_answers_a_read(): void {
		$this->assertIsArray( $this->endpoint( '/submissions', 'GET' ) );
	}

	/**
	 * The read declares itself a list, which is what tells the boundary the
	 * callback filters by reach rather than the boundary checking one named
	 * record. A queue declared site-scoped would be asking the boundary to
	 * check a site the request never names.
	 */
	public function test_the_read_is_declared_a_list_scope(): void {
		$endpoint = $this->endpoint( '/submissions', 'GET' );

		$this->assertSame( Boundary::SCOPE_LIST, $endpoint['args']['scope']['kind'] ?? '' );
	}

	/**
	 * The studio's answer can be written.
	 */
	public function test_one_submission_can_be_answered(): void {
		$this->assertIsArray( $this->endpoint( '/submissions/(?P<submission_id>[A-Za-z0-9_\-]+)', 'PATCH' ) );
	}

	/**
	 * Every endpoint here has a permission callback. Server::register_route()
	 * refuses one without, so this is belt and braces — but this is the first
	 * route that writes to a record the client authored, and it is worth one
	 * test that says so out loud.
	 */
	public function test_both_endpoints_declare_who_may_call_them(): void {
		foreach ( $this->endpoints() as $one ) {
			$endpoint = $this->endpoint( $one[0], $one[1] );

			$this->assertIsCallable( $endpoint['args']['permission_callback'] ?? null );
		}
	}

	/**
	 * A request can be turned into work (#132).
	 */
	public function test_a_request_can_be_converted(): void {
		$this->assertIsArray( $this->endpoint( self::CONVERSION, 'POST' ) );
	}

	/**
	 * The conversion route names no client and no site, and that absence is
	 * D-40 rather than a tidy signature.
	 *
	 * The pipeline the work lands in comes off the submission, which got it
	 * from the signature that carried it (#129). A route with a `client_site_id`
	 * argument would make "never into another client's pipeline" a validation
	 * rule somebody has to keep getting right; a route with no such argument
	 * cannot get it wrong.
	 */
	public function test_the_conversion_route_takes_no_parameter_naming_a_client_or_site(): void {
		$args = (array) ( $this->endpoint( self::CONVERSION, 'POST' )['args']['args'] ?? array() );

		$naming = array_values(
			array_filter(
				array_keys( $args ),
				static fn( $name ): bool => str_contains( (string) $name, 'client' )
					|| str_contains( (string) $name, 'site' )
			)
		);

		$this->assertSame( array(), $naming );
	}

	/**
	 * It is a list scope with its reason written down, for the same reason the
	 * triage write is: the submission has to be read before anybody can know
	 * whose it is, so the callback does the reach check rather than the
	 * boundary.
	 */
	public function test_the_conversion_route_says_how_it_is_scoped(): void {
		$scope = $this->endpoint( self::CONVERSION, 'POST' )['args']['scope'];

		$this->assertSame( Boundary::SCOPE_LIST, $scope['kind'] );
		$this->assertNotSame( '', trim( (string) $scope['reason'] ) );
	}

	/**
	 * The path this suite's three endpoints live at.
	 *
	 * @return array<int, array<int, string>>
	 */
	private function endpoints(): array {
		return array(
			array( '/submissions', 'GET' ),
			array( '/submissions/(?P<submission_id>[A-Za-z0-9_\-]+)', 'PATCH' ),
			array( self::CONVERSION, 'POST' ),
		);
	}

	/**
	 * The conversion route's path.
	 */
	private const CONVERSION = '/submissions/(?P<submission_id>[A-Za-z0-9_\-]+)/conversion';
}
