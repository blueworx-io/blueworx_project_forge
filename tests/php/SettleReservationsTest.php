<?php
/**
 * Which work items the #568 settle step picks.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Commerce\Entries;
use Blueworx\Forge\Commerce\SettleReservations;
use PHPUnit\Framework\TestCase;

/**
 * #568. The one-off settle reconciles only work that has spent its hours and
 * still holds a reservation — the doubled hold the fix stops making.
 */
final class SettleReservationsTest extends TestCase {

	/**
	 * One ledger entry against an item.
	 *
	 * @param string $id    The item.
	 * @param string $type  Event type.
	 * @param float  $hours Magnitude.
	 * @return array<string, mixed>
	 */
	private function entry( string $id, string $type, float $hours ): array {
		return array(
			'source_id'  => $id,
			'event_type' => $type,
			'hours'      => Entries::signed( $type, $hours ),
		);
	}

	public function test_only_work_that_spent_its_hours_and_still_holds_some_is_picked(): void {
		$entries = array(
			// Sent back after it started: spent, and held again. Picked.
			$this->entry( 'wrk_doubled', Entries::WORK_RESERVATION, 2.25 ),
			$this->entry( 'wrk_doubled', Entries::WORK_RELEASE, 2.25 ),
			$this->entry( 'wrk_doubled', Entries::WORK_USAGE, 2.25 ),
			$this->entry( 'wrk_doubled', Entries::WORK_RESERVATION, 2.25 ),

			// Planned and waiting, nothing spent: its hold is right.
			$this->entry( 'wrk_planned', Entries::WORK_RESERVATION, 4 ),

			// Started and settled: nothing held.
			$this->entry( 'wrk_started', Entries::WORK_RESERVATION, 3 ),
			$this->entry( 'wrk_started', Entries::WORK_RELEASE, 3 ),
			$this->entry( 'wrk_started', Entries::WORK_USAGE, 3 ),

			// Sent back and started again: the second hold already given back.
			$this->entry( 'wrk_round_trip', Entries::WORK_RESERVATION, 1 ),
			$this->entry( 'wrk_round_trip', Entries::WORK_RELEASE, 1 ),
			$this->entry( 'wrk_round_trip', Entries::WORK_USAGE, 1 ),
			$this->entry( 'wrk_round_trip', Entries::WORK_RESERVATION, 1 ),
			$this->entry( 'wrk_round_trip', Entries::WORK_RELEASE, 1 ),
		);

		$this->assertSame( array( 'wrk_doubled' ), SettleReservations::sources( $entries ) );
	}

	public function test_nothing_to_settle_picks_nothing(): void {
		$this->assertSame( array(), SettleReservations::sources( array() ) );
	}
}
