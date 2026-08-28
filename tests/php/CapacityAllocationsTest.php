<?php
/**
 * What a work item commits, and when.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Capacity\Allocations;
use PHPUnit\Framework\TestCase;

/**
 * CAP-2's per-role hours read as dated commitments (#137).
 *
 * The cases below are the ones that would be wrong quietly: an idea counted as
 * a commitment, a substitute's work charged to the person they are covering,
 * and a fortnight's hours landing in one day because nobody checked which days
 * the person actually works.
 */
final class CapacityAllocationsTest extends TestCase {

	/**
	 * A work item row, with only what these rules read.
	 *
	 * @param array<string, mixed> $values Overrides.
	 * @return array<string, mixed>
	 */
	private function item( array $values = array() ): array {
		return array_merge(
			array(
				'id'                      => 'itm_1',
				'title'                   => 'A thing',
				'client_id'               => 'cli_1',
				'client_site_id'          => 'cst_1',
				'stage'                   => 'up-next',
				'prior_stage'             => '',
				'archived'                => 0,
				'terminal_outcome'        => '',
				'planned_start'           => '2026-09-07',
				'planned_due'             => '2026-09-11',
				'primary_user_id'         => 'usr_a',
				'reviewer_id'             => 'usr_b',
				'deliverer_id'            => '',
				'reviewer_substitute_id'  => '',
				'deliverer_substitute_id' => '',
				'hours_primary'           => 10.0,
				'hours_review'            => 2.0,
				'hours_delivery'          => 0.0,
			),
			$values
		);
	}

	/**
	 * Five weekdays a person works, as Availability reports them.
	 *
	 * @param array<string, float> $hours Date to hours, overriding the default 8.
	 * @return array<int, array<string, mixed>>
	 */
	private function week( array $hours = array() ): array {
		$days = array();

		foreach ( array( '2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11' ) as $date ) {
			$days[] = array(
				'date'       => $date,
				'hours'      => array_key_exists( $date, $hours ) ? $hours[ $date ] : 8.0,
				'base_hours' => 8.0,
				'reason'     => '',
			);
		}

		return $days;
	}

	public function test_an_item_at_up_next_commits_its_filled_seats(): void {
		$allocations = Allocations::from_item( $this->item() );

		$this->assertCount( 2, $allocations, 'the empty deliverer seat is not a commitment' );
		$this->assertSame( Allocations::PRIMARY, $allocations[0]['role'] );
		$this->assertSame( 'usr_a', $allocations[0]['user_id'] );
		$this->assertSame( 10.0, $allocations[0]['hours'] );
		$this->assertSame( 'usr_b', $allocations[1]['user_id'] );
	}

	public function test_an_idea_commits_nothing(): void {
		$this->assertSame( array(), Allocations::from_item( $this->item( array( 'stage' => 'triage' ) ) ) );
	}

	public function test_finished_and_abandoned_work_commits_nothing(): void {
		$this->assertSame( array(), Allocations::from_item( $this->item( array( 'stage' => 'released' ) ) ) );
		$this->assertSame( array(), Allocations::from_item( $this->item( array( 'terminal_outcome' => 'cancelled' ) ) ) );
		$this->assertSame( array(), Allocations::from_item( $this->item( array( 'archived' => 1 ) ) ) );
	}

	public function test_blocked_work_still_counts_when_it_was_already_committed(): void {
		$blocked = $this->item(
			array(
				'stage'       => 'blocked',
				'prior_stage' => 'in-development',
			)
		);

		$this->assertCount( 2, Allocations::from_item( $blocked ) );

		$never_committed = $this->item(
			array(
				'stage'       => 'blocked',
				'prior_stage' => 'triage',
			)
		);

		$this->assertSame( array(), Allocations::from_item( $never_committed ) );
	}

	public function test_a_substitute_carries_the_commitment(): void {
		$allocations = Allocations::from_item( $this->item( array( 'reviewer_substitute_id' => 'usr_c' ) ) );

		$review = $allocations[1];

		$this->assertSame( 'usr_c', $review['user_id'], 'the substitute is the one doing the work' );
		$this->assertSame( 'usr_b', $review['covering'], 'and the record says who they are covering' );
	}

	public function test_a_seat_with_no_hours_is_not_a_commitment(): void {
		$allocations = Allocations::from_item( $this->item( array( 'hours_review' => 0.0 ) ) );

		$this->assertCount( 1, $allocations );
	}

	public function test_work_with_no_dates_commits_nothing_yet(): void {
		$undated = $this->item(
			array(
				'planned_start' => '',
				'planned_due'   => '',
			)
		);

		$this->assertSame( array(), Allocations::from_item( $undated ) );
	}

	public function test_hours_spread_evenly_across_working_days(): void {
		$allocation = Allocations::from_item( $this->item() )[0];

		$spread = Allocations::spread( $allocation, $this->week() );

		$this->assertSame( 2.0, $spread['2026-09-07'] );
		$this->assertSame( 10.0, round( array_sum( $spread ), 2 ) );
	}

	public function test_a_day_off_carries_none_of_it(): void {
		$allocation = Allocations::from_item( $this->item() )[0];

		$spread = Allocations::spread( $allocation, $this->week( array( '2026-09-09' => 0.0 ) ) );

		$this->assertArrayNotHasKey( '2026-09-09', $spread, 'a day they are away carries no work' );
		$this->assertSame( 2.5, $spread['2026-09-07'] );
		$this->assertSame( 10.0, round( array_sum( $spread ), 2 ) );
	}

	public function test_hours_that_do_not_divide_still_add_up(): void {
		$allocation = Allocations::from_item( $this->item( array( 'hours_primary' => 10.0 ) ) )[0];

		$spread = Allocations::spread( $allocation, $this->week( array( '2026-09-11' => 0.0 ) ) );

		$this->assertSame( 10.0, round( array_sum( $spread ), 2 ), 'the total reconciles whatever the rounding does' );
	}

	public function test_a_window_with_no_working_days_keeps_its_hours(): void {
		$allocation = Allocations::from_item( $this->item() )[0];

		$spread = Allocations::spread(
			$allocation,
			$this->week(
				array(
					'2026-09-07' => 0.0,
					'2026-09-08' => 0.0,
					'2026-09-09' => 0.0,
					'2026-09-10' => 0.0,
					'2026-09-11' => 0.0,
				)
			)
		);

		$this->assertSame( 10.0, round( array_sum( $spread ), 2 ), 'committed hours are never silently dropped' );
		$this->assertSame( 10.0, $spread['2026-09-07'] );
	}

	public function test_days_outside_the_window_carry_nothing(): void {
		$allocation = Allocations::from_item(
			$this->item(
				array(
					'planned_start' => '2026-09-08',
					'planned_due'   => '2026-09-09',
				)
			)
		)[0];

		$spread = Allocations::spread( $allocation, $this->week() );

		$this->assertSame( array( '2026-09-08', '2026-09-09' ), array_keys( $spread ) );
	}
}
