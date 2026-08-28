<?php
/**
 * Time against commitment, and what to call the result.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Capacity\Periods;
use Blueworx\Forge\Capacity\Position;
use PHPUnit\Framework\TestCase;

/**
 * The two sides of a capacity figure, in one place (#139).
 *
 * "They had no time" and "their time was already spoken for" are different
 * facts, and a view that showed both as a red cell would have people chasing
 * the wrong thing. So would a person nobody has set up reading as a person with
 * no room.
 */
final class CapacityPositionTest extends TestCase {

	public function test_room_to_spare_reads_as_clear(): void {
		$position = Position::calculate( 40.0, 10.0, true );

		$this->assertSame( 30.0, $position['remaining'] );
		$this->assertSame( Position::CLEAR, $position['band'] );
	}

	public function test_nearly_full_reads_as_tight(): void {
		$this->assertSame( Position::TIGHT, Position::calculate( 40.0, 32.0, true )['band'] );
		$this->assertSame( Position::TIGHT, Position::calculate( 40.0, 40.0, true )['band'] );
	}

	public function test_more_committed_than_available_reads_as_over(): void {
		$position = Position::calculate( 40.0, 44.0, true );

		$this->assertSame( -4.0, $position['remaining'] );
		$this->assertSame( Position::OVER, $position['band'] );
	}

	public function test_a_person_nobody_has_set_up_is_not_a_person_with_no_room(): void {
		$position = Position::calculate( 0.0, 0.0, false );

		$this->assertSame( Position::UNRECORDED, $position['band'] );
	}

	public function test_a_full_week_of_leave_is_not_over_committed(): void {
		$this->assertSame( Position::CLEAR, Position::calculate( 0.0, 0.0, true )['band'] );
		$this->assertSame( Position::OVER, Position::calculate( 0.0, 3.0, true )['band'] );
	}

	public function test_a_range_splits_into_weeks_starting_monday(): void {
		$weeks = Periods::weeks( '2026-09-09', '2026-09-22' );

		$this->assertSame( '2026-09-09', $weeks[0]['from'], 'the first week starts where the range does' );
		$this->assertSame( '2026-09-13', $weeks[0]['to'], 'and ends on the Sunday' );
		$this->assertSame( '2026-09-14', $weeks[1]['from'] );
		$this->assertSame( '2026-09-20', $weeks[1]['to'] );
		$this->assertSame( '2026-09-22', $weeks[2]['to'], 'the last week stops where the range does' );
		$this->assertCount( 3, $weeks );
	}

	public function test_a_backwards_range_has_no_weeks(): void {
		$this->assertSame( array(), Periods::weeks( '2026-09-22', '2026-09-09' ) );
	}

	public function test_a_range_starting_on_a_monday_gives_whole_weeks(): void {
		$weeks = Periods::weeks( '2026-09-07', '2026-09-20' );

		$this->assertCount( 2, $weeks );
		$this->assertSame( '2026-09-13', $weeks[0]['to'] );
		$this->assertSame( '2026-09-14', $weeks[1]['from'] );
	}

	public function test_one_day_is_one_week(): void {
		$weeks = Periods::weeks( '2026-09-09', '2026-09-09' );

		$this->assertCount( 1, $weeks );
		$this->assertSame( '2026-09-09', $weeks[0]['to'] );
	}
}
