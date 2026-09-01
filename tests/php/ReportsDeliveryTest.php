<?php
/**
 * The delivery numbers, and the edges where the arithmetic lies.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Reports\Delivery;
use PHPUnit\Framework\TestCase;

/**
 * #176. Seven reports computed from the changelog, and nothing stored.
 *
 * The tests that matter here are not "does it add up" — they are the six ways
 * the arithmetic is quietly wrong if nobody thinks about it, each of which
 * produces a plausible number rather than an error: unfinished work counted as
 * a cycle of zero, a stage entered twice counted once, a stay clipped by the
 * window reported as longer than the window, blocked time counted both as
 * itself and as a stage, and an empty window drawn as a row of zeroes.
 *
 * Every one of those looks right on a screen. That is why they are here rather
 * than left to a spec against a real database, where the log would have to be
 * manufactured by walking items up a board and the failure would read as a
 * timeout.
 */
final class ReportsDeliveryTest extends TestCase {

	/**
	 * The window every test below uses, unless it is about the window.
	 */
	private const FROM = 1788566400; // 2026-09-05 00:00:00 UTC.
	private const TO   = 1789430400; // 2026-09-15 00:00:00 UTC.

	private const HOUR = 3600;
	private const DAY  = 86400;

	/**
	 * One move.
	 *
	 * @param string $item    Item id.
	 * @param string $from    Stage moved from.
	 * @param string $to      Stage moved to.
	 * @param int    $at      When.
	 * @param string $action  Which kind of event.
	 * @return array<string, mixed>
	 */
	private function moved( string $item, string $from, string $to, int $at, string $action = 'moved' ): array {
		return array(
			'item_id'     => $item,
			'action'      => $action,
			'from_stage'  => $from,
			'to_stage'    => $to,
			'occurred_at' => $at,
		);
	}

	/**
	 * One piece of work as it stands now.
	 *
	 * @param string               $id        Item id.
	 * @param string               $stage     Where it is.
	 * @param array<string, mixed> $overrides Anything else.
	 * @return array<string, mixed>
	 */
	private function item( string $id, string $stage, array $overrides = array() ): array {
		return array_merge(
			array(
				'id'           => $id,
				'stage'        => $stage,
				'planned_due'  => '',
				'title'        => 'Something',
			),
			$overrides
		);
	}

	// ---- Where work is sitting -------------------------------------------

	public function test_open_work_is_counted_by_the_stage_it_is_in(): void {
		$report = Delivery::compute(
			array(
				$this->item( 'a', 'in-development' ),
				$this->item( 'b', 'in-development' ),
				$this->item( 'c', 'in-review' ),
			),
			array(),
			self::FROM,
			self::TO
		);

		$this->assertSame( 2, $report['stage_distribution']['in-development'] );
		$this->assertSame( 1, $report['stage_distribution']['in-review'] );
	}

	public function test_a_stage_nothing_is_in_is_reported_as_none_rather_than_left_out(): void {
		$report = Delivery::compute( array( $this->item( 'a', 'triage' ) ), array(), self::FROM, self::TO );

		$this->assertArrayHasKey( 'in-development', $report['stage_distribution'] );
		$this->assertSame( 0, $report['stage_distribution']['in-development'] );
	}

	// ---- Time in stage ---------------------------------------------------

	public function test_time_in_a_stage_is_the_gap_between_arriving_and_leaving(): void {
		$report = Delivery::compute(
			array( $this->item( 'a', 'in-review' ) ),
			array(
				$this->moved( 'a', 'triage', 'in-development', self::FROM + self::DAY ),
				$this->moved( 'a', 'in-development', 'in-review', self::FROM + ( 3 * self::DAY ) ),
			),
			self::FROM,
			self::TO
		);

		$this->assertSame( 48.0, $report['time_in_stage']['in-development']['median_hours'] );
		$this->assertSame( 1, $report['time_in_stage']['in-development']['count'] );
	}

	public function test_a_stage_entered_twice_counts_both_visits(): void {
		// Sent back, then done again. Half a day, then a day and a half.
		$report = Delivery::compute(
			array( $this->item( 'a', 'in-review' ) ),
			array(
				$this->moved( 'a', 'triage', 'in-development', self::FROM ),
				$this->moved( 'a', 'in-development', 'in-review', self::FROM + ( 12 * self::HOUR ) ),
				$this->moved( 'a', 'in-review', 'in-development', self::FROM + ( 24 * self::HOUR ) ),
				$this->moved( 'a', 'in-development', 'in-review', self::FROM + ( 60 * self::HOUR ) ),
			),
			self::FROM,
			self::TO
		);

		// Two stays, not one: a visit that is averaged away is a return nobody
		// can see the cost of.
		$this->assertSame( 2, $report['time_in_stage']['in-development']['count'] );
		$this->assertSame( 24.0, $report['time_in_stage']['in-development']['median_hours'] );
	}

	public function test_a_stay_that_began_before_the_window_counts_from_the_window(): void {
		$report = Delivery::compute(
			array( $this->item( 'a', 'in-review' ) ),
			array(
				$this->moved( 'a', 'triage', 'in-development', self::FROM - ( 30 * self::DAY ) ),
				$this->moved( 'a', 'in-development', 'in-review', self::FROM + ( 6 * self::HOUR ) ),
			),
			self::FROM,
			self::TO
		);

		// Six hours, not thirty days and six hours. A window that reports
		// durations longer than itself is a window nobody can reason about.
		$this->assertSame( 6.0, $report['time_in_stage']['in-development']['median_hours'] );
	}

	public function test_work_still_sitting_in_a_stage_is_not_counted_as_having_left_it(): void {
		$report = Delivery::compute(
			array( $this->item( 'a', 'in-development' ) ),
			array( $this->moved( 'a', 'triage', 'in-development', self::FROM + self::DAY ) ),
			self::FROM,
			self::TO
		);

		$this->assertSame( 0, $report['time_in_stage']['in-development']['count'] );
	}

	// ---- Cycle time ------------------------------------------------------

	public function test_cycle_time_runs_from_the_first_move_to_release(): void {
		$report = Delivery::compute(
			array( $this->item( 'a', 'released' ) ),
			array(
				$this->moved( 'a', 'future-idea', 'triage', self::FROM ),
				$this->moved( 'a', 'triage', 'in-development', self::FROM + self::DAY ),
				$this->moved( 'a', 'completed', 'released', self::FROM + ( 4 * self::DAY ) ),
			),
			self::FROM,
			self::TO
		);

		$this->assertSame( 96.0, $report['cycle_time']['median_hours'] );
		$this->assertSame( 1, $report['cycle_time']['count'] );
	}

	public function test_unfinished_work_has_no_cycle_time_at_all(): void {
		$report = Delivery::compute(
			array( $this->item( 'a', 'in-development' ) ),
			array( $this->moved( 'a', 'future-idea', 'triage', self::FROM ) ),
			self::FROM,
			self::TO
		);

		// Not zero. An unfinished item reported as a cycle of nothing drags
		// every average towards a number nobody achieved.
		$this->assertSame( 0, $report['cycle_time']['count'] );
		$this->assertNull( $report['cycle_time']['median_hours'] );
	}

	public function test_work_released_twice_is_measured_to_its_last_release(): void {
		$report = Delivery::compute(
			array( $this->item( 'a', 'released' ) ),
			array(
				$this->moved( 'a', 'future-idea', 'triage', self::FROM ),
				$this->moved( 'a', 'completed', 'released', self::FROM + self::DAY ),
				$this->moved( 'a', 'released', 'in-development', self::FROM + ( 2 * self::DAY ) ),
				$this->moved( 'a', 'completed', 'released', self::FROM + ( 5 * self::DAY ) ),
			),
			self::FROM,
			self::TO
		);

		$this->assertSame( 120.0, $report['cycle_time']['median_hours'] );
	}

	// ---- Blocked time ----------------------------------------------------

	public function test_blocked_time_comes_from_the_blocking_and_not_from_the_stage(): void {
		$report = Delivery::compute(
			array( $this->item( 'a', 'in-development' ) ),
			array(
				$this->moved( 'a', 'in-development', 'blocked', self::FROM, 'blocked' ),
				$this->moved( 'a', 'blocked', 'in-development', self::FROM + ( 18 * self::HOUR ), 'unblocked' ),
			),
			self::FROM,
			self::TO
		);

		$this->assertSame( 18.0, $report['blocked_time']['median_hours'] );
		$this->assertSame( 1, $report['blocked_time']['count'] );

		// And not a second time as a stage. Blocked has a prior stage, so a
		// naive pass over the moves counts the same hours twice.
		$this->assertSame( 0, $report['time_in_stage']['blocked']['count'] );
	}

	public function test_work_still_blocked_is_not_counted_as_having_come_back(): void {
		$report = Delivery::compute(
			array( $this->item( 'a', 'blocked' ) ),
			array( $this->moved( 'a', 'in-development', 'blocked', self::FROM, 'blocked' ) ),
			self::FROM,
			self::TO
		);

		$this->assertSame( 0, $report['blocked_time']['count'] );
	}

	// ---- Review turnaround -----------------------------------------------

	public function test_review_turnaround_is_measured_to_the_decision_either_way(): void {
		$report = Delivery::compute(
			array( $this->item( 'a', 'completed' ), $this->item( 'b', 'in-development' ) ),
			array(
				// Approved after four hours.
				$this->moved( 'a', 'in-development', 'in-review', self::FROM ),
				$this->moved( 'a', 'in-review', 'completed', self::FROM + ( 4 * self::HOUR ) ),
				// Sent back after eight. A refusal is a decision and takes time
				// to reach; leaving it out reports reviews as faster than they
				// are, and only ever in the direction that flatters.
				$this->moved( 'b', 'in-development', 'in-review', self::FROM ),
				$this->moved( 'b', 'in-review', 'in-development', self::FROM + ( 8 * self::HOUR ), 'returned' ),
			),
			self::FROM,
			self::TO
		);

		$this->assertSame( 2, $report['review_turnaround']['count'] );
		$this->assertSame( 6.0, $report['review_turnaround']['median_hours'] );
	}

	// ---- Planned against actual ------------------------------------------

	public function test_work_is_compared_against_the_date_it_was_promised(): void {
		$report = Delivery::compute(
			array(
				$this->item( 'a', 'released', array( 'planned_due' => '2026-09-08' ) ),
				$this->item( 'b', 'released', array( 'planned_due' => '2026-09-13' ) ),
			),
			array(
				// Two days late.
				$this->moved( 'a', 'completed', 'released', 1789084800 ), // 2026-09-11.
				// On the day.
				$this->moved( 'b', 'completed', 'released', 1789257600 ), // 2026-09-13.
			),
			self::FROM,
			self::TO
		);

		$this->assertSame( 2, $report['planned_vs_actual']['count'] );
		$this->assertSame( 1, $report['planned_vs_actual']['on_time'] );
		$this->assertSame( 1, $report['planned_vs_actual']['late'] );
	}

	public function test_work_with_no_promised_date_is_left_out_rather_than_called_on_time(): void {
		$report = Delivery::compute(
			array( $this->item( 'a', 'released' ) ),
			array( $this->moved( 'a', 'completed', 'released', self::FROM + self::DAY ) ),
			self::FROM,
			self::TO
		);

		// Nothing was promised, so nothing was met. Counting it as on time is
		// how a team with no dates reports a perfect record.
		$this->assertSame( 0, $report['planned_vs_actual']['count'] );
	}

	// ---- Throughput ------------------------------------------------------

	public function test_throughput_counts_releases_in_the_week_they_happened(): void {
		$report = Delivery::compute(
			array( $this->item( 'a', 'released' ), $this->item( 'b', 'released' ) ),
			array(
				$this->moved( 'a', 'completed', 'released', self::FROM + self::DAY ),
				$this->moved( 'b', 'completed', 'released', self::FROM + ( 8 * self::DAY ) ),
			),
			self::FROM,
			self::TO
		);

		$weeks = $report['throughput']['weeks'];

		$this->assertSame( 2, array_sum( array_column( $weeks, 'released' ) ) );
		$this->assertGreaterThan( 1, count( $weeks ), 'a window spanning two weeks reports two' );
	}

	public function test_a_release_outside_the_window_is_not_counted(): void {
		$report = Delivery::compute(
			array( $this->item( 'a', 'released' ) ),
			array( $this->moved( 'a', 'completed', 'released', self::TO + self::DAY ) ),
			self::FROM,
			self::TO
		);

		$this->assertSame( 0, array_sum( array_column( $report['throughput']['weeks'], 'released' ) ) );
		$this->assertSame( 0, $report['cycle_time']['count'] );
	}

	// ---- Nothing to report -----------------------------------------------

	public function test_an_empty_window_says_so_rather_than_reporting_zeroes(): void {
		$report = Delivery::compute( array(), array(), self::FROM, self::TO );

		$this->assertTrue( $report['empty'] );
	}

	public function test_a_window_with_work_in_it_is_not_empty(): void {
		$report = Delivery::compute( array( $this->item( 'a', 'triage' ) ), array(), self::FROM, self::TO );

		$this->assertFalse( $report['empty'] );
	}
}
