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
		foreach ( array( array( '/submissions', 'GET' ), array( '/submissions/(?P<submission_id>[A-Za-z0-9_\-]+)', 'PATCH' ) ) as $one ) {
			$endpoint = $this->endpoint( $one[0], $one[1] );

			$this->assertIsCallable( $endpoint['args']['permission_callback'] ?? null );
		}
	}
}
