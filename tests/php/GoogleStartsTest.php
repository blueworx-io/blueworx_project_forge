<?php
/**
 * A person's Google start date.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Google\Starts;
use PHPUnit\Framework\TestCase;

/**
 * #657. The day a person first connects is their start date: it is kept
 * through a disconnect, and nothing before it is read from Google.
 */
final class GoogleStartsTest extends TestCase {

	public function test_a_first_day_is_kept(): void {
		$this->assertSame( array( 'usr_a' => '2026-10-26' ), Starts::with( array(), 'usr_a', '2026-10-26' ) );
	}

	public function test_a_later_day_never_moves_it(): void {
		$this->assertSame( '2026-10-26', Starts::with( array( 'usr_a' => '2026-10-26' ), 'usr_a', '2026-11-03' )['usr_a'] );
	}

	public function test_the_window_starts_at_the_later_of_the_two(): void {
		$this->assertSame( '2026-10-26', Starts::window_from( '2026-09-28', '2026-10-26' ) );
		$this->assertSame( '2026-09-28', Starts::window_from( '2026-09-28', '' ) );
		$this->assertSame( '2026-11-01', Starts::window_from( '2026-11-01', '2026-10-26' ) );
	}
}
