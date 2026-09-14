<?php
/**
 * When the studio's own client needs making.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\Studio;
use PHPUnit\Framework\TestCase;

/**
 * The decision runs on every request, so it has to be right about "nothing
 * to do" — that is the case that costs a table read if it is wrong — and
 * right about a client that has gone, or the studio ends up with no site to
 * put its own work on and nothing telling anyone.
 */
final class StudioTest extends TestCase {

	public function test_nothing_recorded_means_create(): void {
		self::assertTrue( Studio::needs_creating( false, null ) );
	}

	public function test_recorded_and_present_means_nothing_to_do(): void {
		self::assertFalse(
			Studio::needs_creating(
				array(
					'client_id' => 'cli_1',
					'site_id'   => 'cst_1',
				),
				array( 'id' => 'cli_1' )
			)
		);
	}

	public function test_recorded_but_gone_means_create_again(): void {
		self::assertTrue(
			Studio::needs_creating(
				array(
					'client_id' => 'cli_1',
					'site_id'   => 'cst_1',
				),
				null
			)
		);
	}

	public function test_half_recorded_means_create(): void {
		self::assertTrue( Studio::needs_creating( array( 'client_id' => 'cli_1' ), array( 'id' => 'cli_1' ) ) );
	}

	public function test_malformed_option_means_create(): void {
		self::assertTrue( Studio::needs_creating( 'garbage', null ) );
	}
}
