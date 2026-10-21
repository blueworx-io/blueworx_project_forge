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
	/**
	 * Ten hours over the working week, as a recurring chore's allocation is:
	 * the one kind still spread across days (#513).
	 *
	 * @return array<string, mixed>
	 */
	private function across_the_week(): array {
		return array(
			'user_id' => 'usr_a',
			'role'    => 'assignee',
			'hours'   => 10.0,
			'from'    => '2026-09-07',
			'to'      => '2026-09-11',
		);
	}

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

	public function test_a_designer_with_hours_is_committed_too(): void {
		// #421. The designer's time counts on their capacity like everyone's.
		$allocations = Allocations::from_item(
			$this->item(
				array(
					'designer_id'    => 'usr_d',
					'hours_designer' => 3.0,
				)
			)
		);

		$design = array_values( array_filter( $allocations, static fn( array $one ): bool => Allocations::DESIGN === $one['role'] ) );

		$this->assertCount( 1, $design );
		$this->assertSame( 'usr_d', $design[0]['user_id'] );
		$this->assertSame( 3.0, $design[0]['hours'] );
	}

	public function test_an_idea_commits_nothing(): void {
		$this->assertSame( array(), Allocations::from_item( $this->item( array( 'stage' => 'triage' ) ) ) );
	}

	public function test_finished_and_abandoned_work_commits_nothing(): void {
		$this->assertSame( array(), Allocations::from_item( $this->item( array( 'stage' => 'released' ) ) ) );
		$this->assertSame( array(), Allocations::from_item( $this->item( array( 'terminal_outcome' => 'cancelled' ) ) ) );
		$this->assertSame( array(), Allocations::from_item( $this->item( array( 'archived' => 1 ) ) ) );
	}

	/*
	 * #384. Finished work is time that was used, so it stays on its days — as
	 * finished, never as still to do.
	 */
	public function test_finished_work_is_read_as_time_used(): void {
		foreach ( array( 'completed', 'released' ) as $stage ) {
			$finished = Allocations::finished( $this->item( array( 'stage' => $stage ) ) );

			$this->assertCount( 2, $finished, $stage );
			$this->assertSame( Allocations::DONE, $finished[0]['status'] );
			$this->assertSame( 10.0, $finished[0]['hours'] );
		}

		$this->assertSame( array(), Allocations::finished( $this->item() ), 'work still to do is not finished' );
		$this->assertSame(
			array(),
			Allocations::finished(
				$this->item(
					array(
						'stage'            => 'released',
						'terminal_outcome' => 'cancelled',
					)
				)
			)
		);
		$this->assertSame(
			array(),
			Allocations::finished(
				$this->item(
					array(
						'stage'    => 'released',
						'archived' => 1,
					)
				)
			)
		);
	}

	public function test_work_still_to_do_says_so(): void {
		$this->assertSame( Allocations::TO_DO, Allocations::from_item( $this->item() )[0]['status'] );
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

	/*
	 * #513. Every assignment is done in a day: each person's hours sit on
	 * their own date, and nowhere else.
	 */
	public function test_each_seat_sits_on_its_own_date(): void {
		$allocations = Allocations::from_item(
			$this->item(
				array(
					'designer_id'    => 'usr_d',
					'hours_designer' => 2.0,
					'deliverer_id'   => 'usr_c',
					'hours_delivery' => 1.0,
					'design_target'  => '2026-09-07',
					'planned_due'    => '2026-09-08',
					'review_target'  => '2026-09-09',
					'release_target' => '2026-09-10',
				)
			)
		);

		$days = array();

		foreach ( $allocations as $allocation ) {
			$this->assertSame( $allocation['from'], $allocation['to'], $allocation['role'] . ' is one day' );
			$days[ $allocation['role'] ] = $allocation['from'];
		}

		$this->assertSame(
			array(
				Allocations::PRIMARY  => '2026-09-08',
				Allocations::DESIGN   => '2026-09-07',
				Allocations::REVIEW   => '2026-09-09',
				Allocations::DELIVERY => '2026-09-10',
			),
			$days
		);
	}

	public function test_a_seat_with_no_date_of_its_own_sits_on_deliver_by(): void {
		$allocations = Allocations::from_item( $this->item() );

		foreach ( $allocations as $allocation ) {
			$this->assertSame( '2026-09-11', $allocation['from'] );
			$this->assertSame( '2026-09-11', $allocation['to'] );
		}
	}

	public function test_a_seat_with_hours_all_lands_on_its_day(): void {
		$allocation = Allocations::from_item( $this->item() )[0];

		$this->assertSame( array( '2026-09-11' => 10.0 ), Allocations::spread( $allocation, $this->week() ) );
	}

	public function test_a_recurring_chore_keeps_its_window(): void {
		$allocations = Allocations::from_item(
			$this->item(
				array(
					'assignees'  => '["usr_a"]',
					'hours_each' => 1.0,
				)
			)
		);

		$this->assertSame( 'assignee', $allocations[0]['role'] );
		$this->assertSame( '2026-09-07', $allocations[0]['from'] );
		$this->assertSame( '2026-09-11', $allocations[0]['to'] );
	}

	public function test_a_reminders_time_sits_on_its_first_day_for_each_person(): void {
		$allocations = Allocations::from_item(
			$this->item(
				array(
					'recurring_id'  => 'rem_1',
					'assignees'     => '["usr_a","usr_b"]',
					'hours_each'    => 0.5,
					'hours_primary' => 0.0,
					'hours_review'  => 0.0,
				)
			)
		);

		$this->assertSame( array( 'usr_a', 'usr_b' ), array_column( $allocations, 'user_id' ) );

		foreach ( $allocations as $allocation ) {
			$this->assertSame( 'assignee', $allocation['role'] );
			$this->assertSame( 0.5, $allocation['hours'] );
			$this->assertSame( '2026-09-07', $allocation['from'] );
			$this->assertSame( '2026-09-07', $allocation['to'] );
		}
	}

	public function test_a_reminder_with_no_time_commits_nothing(): void {
		$this->assertSame(
			array(),
			Allocations::from_item(
				$this->item(
					array(
						'recurring_id'  => 'rem_1',
						'assignees'     => '["usr_a"]',
						'hours_each'    => 0.0,
						'hours_primary' => 0.0,
						'hours_review'  => 0.0,
					)
				)
			)
		);
	}

	public function test_hours_spread_evenly_across_working_days(): void {
		$allocation = $this->across_the_week();

		$spread = Allocations::spread( $allocation, $this->week() );

		$this->assertSame( 2.0, $spread['2026-09-07'] );
		$this->assertSame( 10.0, round( array_sum( $spread ), 2 ) );
	}

	public function test_a_day_off_carries_none_of_it(): void {
		$allocation = $this->across_the_week();

		$spread = Allocations::spread( $allocation, $this->week( array( '2026-09-09' => 0.0 ) ) );

		$this->assertArrayNotHasKey( '2026-09-09', $spread, 'a day they are away carries no work' );
		$this->assertSame( 2.5, $spread['2026-09-07'] );
		$this->assertSame( 10.0, round( array_sum( $spread ), 2 ) );
	}

	public function test_hours_that_do_not_divide_still_add_up(): void {
		$allocation = $this->across_the_week();

		$spread = Allocations::spread( $allocation, $this->week( array( '2026-09-11' => 0.0 ) ) );

		$this->assertSame( 10.0, round( array_sum( $spread ), 2 ), 'the total reconciles whatever the rounding does' );
	}

	public function test_a_window_with_no_working_days_keeps_its_hours(): void {
		$allocation = $this->across_the_week();

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
		$allocation = array_merge(
			$this->across_the_week(),
			array(
				'from' => '2026-09-08',
				'to'   => '2026-09-09',
			)
		);

		$spread = Allocations::spread( $allocation, $this->week() );

		$this->assertSame( array( '2026-09-08', '2026-09-09' ), array_keys( $spread ) );
	}

	/*
	 * #497. While the client reviews it, the studio is not carrying the work:
	 * the doer's hours are time used, and delivery is not booked at all
	 * until the client approves (#518).
	 */
	public function test_work_with_the_client_is_not_carried_by_the_studio(): void {
		$allocations = Allocations::from_item(
			$this->item(
				array(
					'stage'          => 'in-review',
					'reviewer_id'    => 'client',
					'hours_review'   => 0.0,
					'deliverer_id'   => 'usr_c',
					'hours_delivery' => 1.0,
				)
			)
		);

		$this->assertSame( array( 'primary' => Allocations::WITH_CLIENT ), array_column( $allocations, 'status', 'role' ) );
	}

	public function test_a_studio_review_is_still_carried(): void {
		$allocations = Allocations::from_item( $this->item( array( 'stage' => 'in-review' ) ) );

		$this->assertSame( Allocations::TO_DO, array_column( $allocations, 'status', 'role' )[ Allocations::REVIEW ] );
	}

	/*
	 * A person's part is done once the work has moved past it: the doer's at
	 * In Review, the designer's once the build starts. Until the whole item
	 * is finished their hours would otherwise sit as still to do, and land on
	 * today as overdue, for work only somebody else can move on.
	 */
	public function test_the_doers_part_is_done_once_it_is_in_review(): void {
		$allocations = Allocations::from_item( $this->item( array( 'stage' => 'in-review' ) ) );

		$this->assertSame(
			array(
				Allocations::PRIMARY => Allocations::DONE,
				Allocations::REVIEW  => Allocations::TO_DO,
			),
			array_column( $allocations, 'status', 'role' )
		);
	}

	public function test_the_designers_part_is_done_once_the_build_starts(): void {
		$designed = array(
			'designer_id'    => 'usr_d',
			'hours_designer' => 3.0,
		);

		$designing = Allocations::from_item( $this->item( $designed + array( 'stage' => 'design-process' ) ) );
		$building  = Allocations::from_item( $this->item( $designed + array( 'stage' => 'in-development' ) ) );

		$this->assertSame( Allocations::TO_DO, array_column( $designing, 'status', 'role' )[ Allocations::DESIGN ] );
		$this->assertSame(
			array(
				Allocations::PRIMARY => Allocations::TO_DO,
				Allocations::DESIGN  => Allocations::DONE,
				Allocations::REVIEW  => Allocations::TO_DO,
			),
			array_column( $building, 'status', 'role' )
		);
	}

	public function test_blocked_in_review_keeps_the_doers_part_done(): void {
		$allocations = Allocations::from_item(
			$this->item(
				array(
					'stage'       => 'blocked',
					'prior_stage' => 'in-review',
				)
			)
		);

		$this->assertSame( Allocations::DONE, array_column( $allocations, 'status', 'role' )[ Allocations::PRIMARY ] );
	}

	public function test_work_sent_back_from_review_is_the_doers_again(): void {
		$allocations = Allocations::from_item( $this->item( array( 'stage' => 'in-development' ) ) );

		$this->assertSame( Allocations::TO_DO, array_column( $allocations, 'status', 'role' )[ Allocations::PRIMARY ] );
	}

	public function test_work_sent_back_by_the_client_is_carried_again(): void {
		$allocations = Allocations::from_item( $this->item( array( 'stage' => 'in-development', 'reviewer_id' => 'client', 'hours_review' => 0.0 ) ) );

		$this->assertSame( array( Allocations::TO_DO ), array_values( array_unique( array_column( $allocations, 'status' ) ) ) );
	}
}
