<?php
/**
 * The runner's routes, as registered, and the comment it leaves.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Rest\Boundary;
use Blueworx\Forge\Rest\Permissions;
use Blueworx\Forge\Rest\Server;
use Blueworx\Forge\Work\Comments;
use PHPUnit\Framework\TestCase;

/**
 * Walks everything Server::register_routes() registers, so the key can only
 * ever reach the five routes written for it, and so the routes that manage
 * the key stay behind the door, for administrators.
 */
final class RunnerRoutesTest extends TestCase {

	/**
	 * Registers every route afresh.
	 */
	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_routes'] = array();
		$GLOBALS['bwx_forge_test_can']    = array();
		$GLOBALS['bwx_forge_test_inserts'] = array();

		Server::register_routes();
	}

	/**
	 * Method and path of every route whose permission is the runner key.
	 *
	 * @return array<int, string>
	 */
	private function keyed(): array {
		$keyed = array();

		foreach ( $GLOBALS['bwx_forge_test_routes'] as $route ) {
			if ( array( Permissions::class, 'runner' ) === $route['args']['permission_callback'] ) {
				$keyed[] = $route['args']['methods'] . ' ' . $route['route'];
			}
		}

		sort( $keyed );

		return $keyed;
	}

	/**
	 * Five routes take the key, and they are these five.
	 */
	public function test_the_key_reaches_five_routes_and_no_others(): void {
		$id = '(?P<item_id>[A-Za-z0-9_\-]+)';

		$this->assertSame(
			array(
				'GET /runner/cards',
				"GET /runner/cards/{$id}",
				"POST /runner/cards/{$id}/claim",
				"POST /runner/cards/{$id}/comments",
				"POST /runner/cards/{$id}/hand-over",
			),
			$this->keyed()
		);
	}

	/**
	 * Every one of them says, in words, why it is outside the tenant boundary.
	 */
	public function test_each_keyed_route_says_why_it_is_open(): void {
		foreach ( $GLOBALS['bwx_forge_test_routes'] as $route ) {
			if ( array( Permissions::class, 'runner' ) !== $route['args']['permission_callback'] ) {
				continue;
			}

			$this->assertSame( Boundary::SCOPE_OPEN, $route['args']['scope']['kind'], $route['route'] );
			$this->assertStringContainsString( 'Agent', (string) $route['args']['scope']['reason'], $route['route'] );
		}
	}

	/**
	 * Managing the key is behind the door, and for administrators: with no
	 * login at all, each of the four is refused.
	 */
	public function test_managing_the_key_is_for_administrators_only(): void {
		$methods = array();

		foreach ( $GLOBALS['bwx_forge_test_routes'] as $route ) {
			if ( '/runner/key' !== $route['route'] ) {
				continue;
			}

			$methods[] = $route['args']['methods'];
			$door      = $route['args']['permission_callback'];

			$this->assertInstanceOf( Closure::class, $door, 'wrapped by the door' );
			$this->assertFalse( $door( new WP_REST_Request() ), $route['args']['methods'] . ' with no login' );

			// A Manager is past the door, and still not an administrator.
			$GLOBALS['bwx_forge_test_can'] = array( 'bwx_forge_use' );
			$this->assertFalse( $door( new WP_REST_Request() ), $route['args']['methods'] . ' as a Manager' );

			$GLOBALS['bwx_forge_test_can'] = array( 'bwx_forge_use', 'manage_options' );
			$this->assertTrue( $door( new WP_REST_Request() ), $route['args']['methods'] . ' as an administrator' );

			$GLOBALS['bwx_forge_test_can'] = array();
		}

		sort( $methods );

		$this->assertSame( array( 'DELETE', 'GET', 'PATCH', 'POST' ), $methods );
	}

	/**
	 * A runner comment is written as the Agent, internal whatever was asked,
	 * with no WordPress user behind it and marked as the runner's.
	 */
	public function test_a_runner_comment_is_the_agents_and_internal(): void {
		Comments::add(
			array(
				'item_id'        => 'wrk_1',
				'client_site_id' => 'cs_1',
				'client_id'      => 'cl_1',
				'kind'           => Comments::COMMENT,
				'visibility'     => Comments::CLIENT,
				'body'           => 'Half done.',
				'runner'         => true,
			)
		);

		$this->assertCount( 1, $GLOBALS['bwx_forge_test_inserts'] );

		$row = $GLOBALS['bwx_forge_test_inserts'][0][1];

		$this->assertSame( 0, $row['author'] );
		$this->assertSame( '', $row['author_site'] );
		$this->assertSame( 'Agent', $row['author_name'] );
		$this->assertSame( Comments::INTERNAL, $row['visibility'] );
		$this->assertSame( Comments::COMMENT, $row['kind'] );
	}

	/**
	 * Without the runner's mark, a comment with nobody behind it is still
	 * refused, as it always was.
	 */
	public function test_a_comment_with_nobody_behind_it_is_still_refused(): void {
		$this->assertNull(
			Comments::add(
				array(
					'item_id'        => 'wrk_1',
					'client_site_id' => 'cs_1',
					'body'           => 'Who wrote this?',
				)
			)
		);

		$this->assertSame( array(), $GLOBALS['bwx_forge_test_inserts'] );
	}

	/**
	 * The runner's mark never sits beside a person or a client site, and never
	 * writes anything but an ordinary comment.
	 */
	public function test_the_runner_mark_is_alone(): void {
		foreach ( array( array( 'author' => 3 ), array( 'author_site' => 'cs_1' ), array( 'kind' => Comments::QUESTION ), array( 'kind' => Comments::EVIDENCE, 'url' => 'https://example.test' ) ) as $extra ) {
			$this->assertNull(
				Comments::add(
					array_merge(
						array(
							'item_id'        => 'wrk_1',
							'client_site_id' => 'cs_1',
							'body'           => 'Half done.',
							'runner'         => true,
						),
						$extra
					)
				),
				(string) wp_json_encode( $extra )
			);
		}

		$this->assertSame( array(), $GLOBALS['bwx_forge_test_inserts'] );
	}
}
