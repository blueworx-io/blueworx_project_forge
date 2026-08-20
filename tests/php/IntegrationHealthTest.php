<?php
/**
 * How a connection's health is decided.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\Health;
use PHPUnit\Framework\TestCase;

/**
 * Issue #89's acceptance criterion is one distinction: a broken connection has
 * to be tellable from an idle one. Both are sites that have not called
 * recently; only one of them is a fault, and a studio that shows them the same
 * way either sends somebody to fix a site nobody is using, or leaves a site
 * that stopped working looking quiet.
 */
final class IntegrationHealthTest extends TestCase {

	/**
	 * Puts the clock somewhere fixed so "recently" means something in a test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['bwx_forge_test_now'] = 1000000;
	}

	/**
	 * Clears it again, so a later test does not inherit this one's clock.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['bwx_forge_test_now'] );

		parent::tearDown();
	}

	/**
	 * A record with the given differences from a never-touched one.
	 *
	 * @param array<string, mixed> $values Fields to set.
	 * @return array<string, mixed>
	 */
	private function record( array $values = array() ): array {
		return array_merge(
			array(
				'key_state'       => 'unissued',
				'key_issued_at'   => 0,
				'key_revoked_at'  => 0,
				'last_seen_at'    => 0,
				'last_error_at'   => 0,
				'last_error_code' => '',
			),
			$values
		);
	}

	/**
	 * Nobody has connected it yet.
	 */
	public function test_a_site_with_no_key_is_unconfigured(): void {
		$this->assertSame( Health::UNCONFIGURED, Health::state( $this->record() ) );
	}

	/**
	 * Cut off on purpose. Not a fault, and it must not read as one.
	 */
	public function test_a_revoked_key_is_revoked_not_broken(): void {
		$state = Health::state(
			$this->record(
				array(
					'key_state'      => 'revoked',
					'key_issued_at'  => 900000,
					'key_revoked_at' => 950000,
					'last_seen_at'   => 940000,
				)
			)
		);

		$this->assertSame( Health::REVOKED, $state );
	}

	/**
	 * Installed at our end, never at theirs.
	 */
	public function test_a_key_that_has_never_been_used_is_never_connected(): void {
		$state = Health::state(
			$this->record(
				array(
					'key_state'     => 'active',
					'key_issued_at' => 999000,
				)
			)
		);

		$this->assertSame( Health::NEVER_CONNECTED, $state );
	}

	/**
	 * Called recently, nothing has failed since.
	 */
	public function test_a_recent_success_is_connected(): void {
		$state = Health::state(
			$this->record(
				array(
					'key_state'     => 'active',
					'key_issued_at' => 900000,
					'last_seen_at'  => 999000,
				)
			)
		);

		$this->assertSame( Health::CONNECTED, $state );
	}

	/**
	 * The distinction the issue exists for, half one: it tried and failed.
	 */
	public function test_a_failure_after_the_last_success_is_broken(): void {
		$state = Health::state(
			$this->record(
				array(
					'key_state'       => 'active',
					'key_issued_at'   => 900000,
					'last_seen_at'    => 990000,
					'last_error_at'   => 999000,
					'last_error_code' => 'bad_signature',
				)
			)
		);

		$this->assertSame( Health::BROKEN, $state );
	}

	/**
	 * Half two: it has not called for a while, and nothing failed. A quiet site
	 * is not a broken one.
	 */
	public function test_an_old_success_with_no_failure_since_is_idle(): void {
		$state = Health::state(
			$this->record(
				array(
					'key_state'     => 'active',
					'key_issued_at' => 500000,
					'last_seen_at'  => 800000,
				)
			)
		);

		$this->assertSame( Health::IDLE, $state );
	}

	/**
	 * An old failure that a later success followed is history, not a fault.
	 * Without this, one bad signature would leave a working site red forever.
	 */
	public function test_a_failure_the_site_recovered_from_is_not_broken(): void {
		$state = Health::state(
			$this->record(
				array(
					'key_state'       => 'active',
					'key_issued_at'   => 900000,
					'last_error_at'   => 990000,
					'last_error_code' => 'stale_request',
					'last_seen_at'    => 999000,
				)
			)
		);

		$this->assertSame( Health::CONNECTED, $state );
	}

	/**
	 * A site that has never succeeded and has failed is broken, not merely
	 * never connected: somebody typed the key in wrong and needs telling.
	 */
	public function test_a_site_that_has_only_ever_failed_is_broken(): void {
		$state = Health::state(
			$this->record(
				array(
					'key_state'       => 'active',
					'key_issued_at'   => 900000,
					'last_error_at'   => 999000,
					'last_error_code' => 'bad_signature',
				)
			)
		);

		$this->assertSame( Health::BROKEN, $state );
	}

	/**
	 * A failure recorded in the same second as a success is not treated as
	 * having come after it. Second-resolution timestamps make ties ordinary,
	 * and a tie resolved the other way would flick a healthy site red on every
	 * busy request.
	 */
	public function test_a_failure_in_the_same_second_as_a_success_is_not_broken(): void {
		$state = Health::state(
			$this->record(
				array(
					'key_state'       => 'active',
					'key_issued_at'   => 900000,
					'last_seen_at'    => 999000,
					'last_error_at'   => 999000,
					'last_error_code' => 'replayed_request',
				)
			)
		);

		$this->assertSame( Health::CONNECTED, $state );
	}

	/**
	 * Every state a record can be in has a sentence a human can read.
	 */
	public function test_every_state_has_a_label(): void {
		foreach ( Health::STATES as $state ) {
			$this->assertNotSame( '', Health::label( $state ) );
		}
	}
}
