<?php
/**
 * The date arithmetic behind the client timeline and calendar.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

use Blueworx\Forge\Client\Layout;
use PHPUnit\Framework\TestCase;

/**
 * #128. The client draws a timeline and a calendar without a build step, so the
 * arithmetic that places a bar or fills a grid is PHP and is tested here rather
 * than inferred from a screenshot.
 *
 * This is the third place in the project that has had to answer "where does
 * this date go" — the studio Gantt and Calendar are the other two — so the
 * cases that caught them are repeated deliberately: a single-day span, work
 * with no dates at all, and a month whose first day is not a Monday.
 */
final class ClientLayoutTest extends TestCase {

	/**
	 * An item, in the shape the board route sends.
	 *
	 * @param array<string, mixed> $also Fields to add or override.
	 * @return array<string, mixed>
	 */
	private function item( array $also = array() ): array {
		return array_merge(
			array(
				'id'             => 'wrk_1',
				'title'          => 'Rebuild the booking form',
				'stage'          => 'in-development',
				'planned_start'  => '2026-09-01',
				'planned_due'    => '2026-09-10',
				'review_target'  => '',
				'release_target' => '',
			),
			$also
		);
	}

	// -----------------------------------------------------------------------
	// The timeline axis.
	// -----------------------------------------------------------------------

	/**
	 * The axis spans the earliest and latest dates anything uses.
	 */
	public function test_the_axis_covers_every_dated_item(): void {
		$axis = Layout::axis(
			array(
				$this->item( array( 'planned_start' => '2026-09-05', 'planned_due' => '2026-09-10' ) ),
				$this->item( array( 'planned_start' => '2026-09-01', 'planned_due' => '2026-09-20' ) ),
			)
		);

		$this->assertSame( '2026-09-01', $axis['from'] );
		$this->assertSame( '2026-09-20', $axis['to'] );
		$this->assertSame( 20, $axis['days'] );
	}

	/**
	 * Nothing dated means no axis to draw. The screen shows the undated list
	 * instead, rather than an empty chart implying a range nobody set.
	 */
	public function test_an_undated_board_has_no_axis(): void {
		$axis = Layout::axis( array( $this->item( array( 'planned_start' => '', 'planned_due' => '' ) ) ) );

		$this->assertSame( array(), $axis );
	}

	/**
	 * A bar sits where its dates say, as a percentage of the axis.
	 */
	public function test_a_bar_is_placed_across_the_axis(): void {
		$axis  = Layout::axis( array( $this->item( array( 'planned_start' => '2026-09-01', 'planned_due' => '2026-09-10' ) ) ) );
		$place = Layout::place( $this->item( array( 'planned_start' => '2026-09-01', 'planned_due' => '2026-09-10' ) ), $axis );

		$this->assertSame( 0.0, $place['left'] );
		$this->assertSame( 100.0, $place['width'] );
	}

	/**
	 * A single day is still visible. Zero width is a bar nobody can see, which
	 * is the same as work that is not on the chart.
	 */
	public function test_a_single_day_span_still_has_width(): void {
		$axis  = Layout::axis( array( $this->item( array( 'planned_start' => '2026-09-01', 'planned_due' => '2026-09-10' ) ) ) );
		$place = Layout::place( $this->item( array( 'planned_start' => '2026-09-05', 'planned_due' => '2026-09-05' ) ), $axis );

		$this->assertGreaterThan( 0.0, $place['width'] );
	}

	/**
	 * Work with a start and no due date is still placed, running to the end of
	 * what it knows rather than being dropped off the chart.
	 */
	public function test_a_start_with_no_due_date_is_still_placed(): void {
		$axis  = Layout::axis( array( $this->item( array( 'planned_start' => '2026-09-01', 'planned_due' => '2026-09-10' ) ) ) );
		$place = Layout::place( $this->item( array( 'planned_start' => '2026-09-03', 'planned_due' => '' ) ), $axis );

		$this->assertGreaterThan( 0.0, $place['width'] );
	}

	/**
	 * Work with no dates has nowhere to go, and says so rather than landing on
	 * day one.
	 */
	public function test_an_undated_item_is_not_placed(): void {
		$axis  = Layout::axis( array( $this->item() ) );
		$place = Layout::place( $this->item( array( 'planned_start' => '', 'planned_due' => '' ) ), $axis );

		$this->assertSame( array(), $place );
	}

	// -----------------------------------------------------------------------
	// What has no dates at all.
	// -----------------------------------------------------------------------

	/**
	 * Undated work is kept and listed, never silently dropped (#120).
	 */
	public function test_undated_work_is_kept_separately(): void {
		$undated = Layout::undated(
			array(
				$this->item( array( 'id' => 'wrk_dated' ) ),
				$this->item( array( 'id' => 'wrk_undated', 'planned_start' => '', 'planned_due' => '' ) ),
			)
		);

		$this->assertSame( array( 'wrk_undated' ), array_column( $undated, 'id' ) );
	}

	// -----------------------------------------------------------------------
	// The calendar grid.
	// -----------------------------------------------------------------------

	/**
	 * A month grid is whole weeks, starting Monday, covering the whole month.
	 * September 2026 starts on a Tuesday, so the grid opens on 31 August.
	 */
	public function test_a_month_grid_is_whole_weeks_from_monday(): void {
		$days = Layout::month( '2026-09-15' );

		$this->assertSame( '2026-08-31', $days[0] );
		$this->assertSame( 0, count( $days ) % 7 );
		$this->assertContains( '2026-09-30', $days );
	}

	/**
	 * Every date an item carries becomes an entry on its own day, so a due date
	 * and a release target do not collapse into one mark.
	 */
	public function test_each_date_an_item_carries_is_its_own_entry(): void {
		$entries = Layout::entries(
			array(
				$this->item(
					array(
						'planned_start'  => '2026-09-01',
						'planned_due'    => '2026-09-10',
						'release_target' => '2026-09-12',
					)
				),
			)
		);

		$this->assertSame( array( 'starts' ), array_column( $entries['2026-09-01'], 'kind' ) );
		$this->assertSame( array( 'due' ), array_column( $entries['2026-09-10'], 'kind' ) );
		$this->assertSame( array( 'release' ), array_column( $entries['2026-09-12'], 'kind' ) );
	}

	/**
	 * A day nothing happens on has no entries, rather than an empty structure
	 * every caller has to know to check.
	 */
	public function test_a_day_with_nothing_on_it_is_absent(): void {
		$entries = Layout::entries( array( $this->item() ) );

		$this->assertArrayNotHasKey( '2026-09-02', $entries );
	}
}
