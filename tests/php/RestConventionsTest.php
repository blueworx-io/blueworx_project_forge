<?php
/**
 * Tests for the REST conventions every later endpoint inherits.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Rest\Errors;
use Blueworx\Forge\Rest\Idempotency;
use Blueworx\Forge\Rest\Server;
use Blueworx\Forge\Rest\Versioning;
use PHPUnit\Framework\TestCase;

/**
 * These conventions are the reason a missing permission callback is one bug
 * rather than a class of bug: every route goes through Server::register_route(),
 * so the rules are enforced at the door instead of remembered per route.
 */
final class RestConventionsTest extends TestCase {

	/**
	 * Clears the recorded routes and the fake transient store.
	 */
	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_routes']     = array();
		$GLOBALS['bwx_forge_test_transients'] = array();
		$GLOBALS['bwx_forge_test_can']        = array();
	}

	// -----------------------------------------------------------------------
	// Every route declares who may call it.
	// -----------------------------------------------------------------------

	/**
	 * The door refuses a route with no permission callback. This is the test the
	 * issue asks for by name: a route registered without one fails, here, rather
	 * than shipping open.
	 */
	public function test_a_route_without_a_permission_callback_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		Server::register_route(
			'blueworx-forge/v1',
			'/unguarded',
			array(
				'methods'  => 'GET',
				'callback' => '__return_true',
			)
		);
	}

	/**
	 * A null permission callback is the same mistake spelled differently, and
	 * WordPress treats it as "public" with only a notice. Refuse it too.
	 */
	public function test_a_null_permission_callback_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		Server::register_route(
			'blueworx-forge/v1',
			'/unguarded',
			array(
				'methods'             => 'GET',
				'callback'            => '__return_true',
				'permission_callback' => null,
			)
		);
	}

	/**
	 * A route that declares one registers normally.
	 */
	public function test_a_guarded_route_registers(): void {
		Server::register_route(
			'blueworx-forge/v1',
			'/guarded',
			array(
				'methods'             => 'GET',
				'callback'            => '__return_true',
				'permission_callback' => '__return_true',
				'scope'               => array(
					'kind'   => \Blueworx\Forge\Rest\Boundary::SCOPE_OPEN,
					'reason' => 'A fixture. The scope rule is its own test.',
				),
			)
		);

		$this->assertCount( 1, $GLOBALS['bwx_forge_test_routes'] );
	}

	/**
	 * The plugin's own routes are checked as a set, not one at a time: this walks
	 * everything Server::register_routes() actually registers, so a new
	 * controller added later cannot slip a bare route past the suite.
	 */
	public function test_every_registered_route_declares_a_permission_callback(): void {
		Server::register_routes();

		$this->assertNotEmpty( $GLOBALS['bwx_forge_test_routes'], 'no routes were registered at all' );

		foreach ( $GLOBALS['bwx_forge_test_routes'] as $route ) {
			$this->assertArrayHasKey(
				'permission_callback',
				$route['args'],
				sprintf( '%s has no permission callback', $route['route'] )
			);
			$this->assertNotNull( $route['args']['permission_callback'] );
		}
	}

	// -----------------------------------------------------------------------
	// One error envelope.
	// -----------------------------------------------------------------------

	/**
	 * Errors carry the plugin's prefix and an HTTP status, so a client can tell
	 * our refusal from WordPress core's.
	 */
	public function test_the_error_envelope_is_prefixed_and_carries_a_status(): void {
		$error = Errors::rest( 'not_allowed', 'Nope.', 403 );

		$this->assertSame( 'bwx_forge_not_allowed', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] );
	}

	// -----------------------------------------------------------------------
	// The gate-failure contract.
	// -----------------------------------------------------------------------

	/**
	 * Every unmet requirement comes back, not the first one found. Returning one
	 * at a time makes a person fix, resubmit, and be refused again.
	 *
	 * Shape fixed by docs/architecture/workflow-state-machine.md, "Gate-failure
	 * contract".
	 */
	public function test_a_gate_failure_returns_every_unmet_requirement(): void {
		$unmet = array(
			array(
				'id'           => 'G-UP-NEXT-4',
				'label'        => 'Planned hours per role',
				'satisfied_by' => 'Enter planned hours for Primary User, Reviewer and Deliverer',
			),
			array(
				'id'           => 'G-UP-NEXT-8',
				'label'        => 'Capacity',
				'satisfied_by' => 'Free capacity in the target window',
			),
		);

		$response = Errors::gate_failure( 'item-1', 'up-next', 'in-development', $unmet );
		$body     = $response->get_data();

		$this->assertFalse( $body['ok'] );
		$this->assertSame( 'item-1', $body['item_id'] );
		$this->assertSame( 'up-next', $body['stage'], 'the stage must come back unchanged' );
		$this->assertSame( 'in-development', $body['attempted'] );
		$this->assertCount( 2, $body['unmet'] );
		$this->assertSame( 'G-UP-NEXT-4', $body['unmet'][0]['id'] );
		$this->assertSame( 'G-UP-NEXT-8', $body['unmet'][1]['id'] );
	}

	/**
	 * A gate failure changes nothing, so it is a refusal and must not read as a
	 * success to anything that only looks at the status code.
	 */
	public function test_a_gate_failure_is_not_a_2xx(): void {
		$response = Errors::gate_failure( 'item-1', 'up-next', 'in-development', array() );

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
	}

	// -----------------------------------------------------------------------
	// Request versioning — ARCH-5.
	// -----------------------------------------------------------------------

	/**
	 * A write made against the current version goes ahead.
	 */
	public function test_a_current_version_write_is_allowed(): void {
		$this->assertNull( Versioning::check( 7, 7 ) );
	}

	/**
	 * A write made against an older version is rejected and the current state
	 * comes back with it, so the person who made it can see what moved.
	 * ARCH-5: rejected and returned, never merged.
	 */
	public function test_a_stale_write_is_rejected_with_the_current_state(): void {
		$error = Versioning::check( 6, 7, array( 'title' => 'The current title' ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'bwx_forge_stale_write', $error->get_error_code() );

		$data = $error->get_error_data();

		$this->assertSame( 409, $data['status'] );
		$this->assertSame( 6, $data['sent_version'] );
		$this->assertSame( 7, $data['current_version'] );
		$this->assertSame( 'The current title', $data['current']['title'] );
	}

	/**
	 * A write claiming a version that does not exist yet is stale too — it was
	 * made against something this server never issued.
	 */
	public function test_a_write_from_the_future_is_rejected(): void {
		$this->assertInstanceOf( WP_Error::class, Versioning::check( 9, 7 ) );
	}

	/**
	 * A write that sends no version at all cannot be checked, so it is refused
	 * rather than let through. ARCH-5 says every write carries its version.
	 */
	public function test_a_write_with_no_version_is_refused(): void {
		$error = Versioning::check( null, 7 );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'bwx_forge_missing_version', $error->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// Idempotency keys.
	// -----------------------------------------------------------------------

	/**
	 * The first write under a key runs and is remembered.
	 */
	public function test_a_first_write_under_a_key_runs(): void {
		$this->assertNull( Idempotency::replay( 'echo', 'key-1' ) );

		Idempotency::remember( 'echo', 'key-1', array( 'message' => 'hello' ) );

		$this->assertSame( array( 'message' => 'hello' ), Idempotency::replay( 'echo', 'key-1' ) );
	}

	/**
	 * A replay returns the first answer rather than doing the work twice. This is
	 * the property that stops a retried request creating two records.
	 */
	public function test_a_replayed_key_produces_one_record(): void {
		$runs = 0;

		$write = static function () use ( &$runs ): array {
			++$runs;
			return array( 'id' => 'record-1' );
		};

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$replayed = Idempotency::replay( 'create', 'key-2' );

			if ( null === $replayed ) {
				Idempotency::remember( 'create', 'key-2', $write() );
			}
		}

		$this->assertSame( 1, $runs, 'the write ran more than once under one key' );
		$this->assertSame( array( 'id' => 'record-1' ), Idempotency::replay( 'create', 'key-2' ) );
	}

	/**
	 * Keys are scoped per operation. Two different writes that happen to reuse a
	 * key must not answer each other's replays.
	 */
	public function test_keys_do_not_leak_between_operations(): void {
		Idempotency::remember( 'create', 'shared-key', array( 'id' => 'a' ) );

		$this->assertNull( Idempotency::replay( 'delete', 'shared-key' ) );
	}

	/**
	 * A key long enough to be a denial-of-service, or shaped like a path, is
	 * refused rather than stored.
	 */
	public function test_an_unusable_key_is_refused(): void {
		$this->assertFalse( Idempotency::is_valid_key( str_repeat( 'a', 256 ) ) );
		$this->assertFalse( Idempotency::is_valid_key( '' ) );
		$this->assertFalse( Idempotency::is_valid_key( '../../etc/passwd' ) );
		$this->assertTrue( Idempotency::is_valid_key( 'a2f1c0de-1234-4567-89ab-cdef01234567' ) );
	}
}
