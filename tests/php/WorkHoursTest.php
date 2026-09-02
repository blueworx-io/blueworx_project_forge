<?php
/**
 * Hours reserved when work is planned, spent when it starts, given back when it stops.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Commerce\Entries;
use Blueworx\Forge\Commerce\WorkHours;
use Blueworx\Forge\Work\Outcomes;
use Blueworx\Forge\Work\Stages;
use PHPUnit\Framework\TestCase;

/**
 * #149, COMM-3. The work half of the hour lifecycle.
 *
 * The rule the whole class exists to keep is one sentence: **what the ledger
 * holds for a piece of work is decided by where that work is, and never by how
 * it got there.** A move does not "do a reservation"; it asks what this item
 * should be holding now, compares that with what the ledger already shows, and
 * appends the difference. Nothing else.
 *
 * That is what makes drift impossible rather than unlikely. The obvious
 * implementation — reserve on the way into Up Next, convert on the way into In
 * Development, release on cancel — is right for the three moves somebody
 * thought of and wrong for every other path through the board. Sent back out of
 * Up Next, blocked and unblocked, cancelled from Blocked, re-planned twice
 * before it starts: each is a sequence nobody wrote a branch for, and each one
 * leaves hours held against work that is not happening.
 *
 * So the tests below are mostly not about the three obvious moves. They are
 * about the sequences, and about the two things that must never happen however
 * you get there: the same hours taken twice, and spent hours handed back.
 */
final class WorkHoursTest extends TestCase {

	/**
	 * An item, with only the fields the hour rules read.
	 *
	 * @param array<string, mixed> $overrides Anything to say differently.
	 * @return array<string, mixed>
	 */
	private function item( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'               => 'wrk_one',
				'client_site_id'   => 'cst_one',
				'stage'            => Stages::FIRST,
				'prior_stage'      => '',
				'terminal_outcome' => '',
				'commercial_class' => 'chargeable',
				'hours_primary'    => 10.0,
				'hours_review'     => 2.0,
				'hours_delivery'   => 1.0,
			),
			$overrides
		);
	}

	/**
	 * A ledger entry as the ledger stores one: signed, and against this item.
	 *
	 * @param string $type  Event type.
	 * @param float  $hours Magnitude, however offered.
	 * @return array<string, mixed>
	 */
	private function entry( string $type, float $hours ): array {
		return array(
			'event_type'  => $type,
			'hours'       => Entries::signed( $type, $hours ),
			'source_type' => WorkHours::SOURCE,
			'source_id'   => 'wrk_one',
		);
	}

	/**
	 * The plan, flattened to pairs, so a test can say what it expects in a line.
	 *
	 * @param array<int, array<string, mixed>> $plan What plan() returned.
	 * @return array<int, array{0: string, 1: float}>
	 */
	private function pairs( array $plan ): array {
		return array_map(
			static fn( array $entry ): array => array( (string) $entry['event_type'], round( (float) $entry['hours'], 2 ) ),
			$plan
		);
	}

	/* ------------------------------------------------ what the work is worth */

	public function test_planned_hours_are_the_three_seats_added_up(): void {
		// Not the estimate, and not the Primary User's figure alone. Three
		// people are committed and the client is paying for all three.
		$this->assertSame( 13.0, WorkHours::planned( $this->item() ) );
	}

	public function test_a_seat_with_no_hours_contributes_nothing_rather_than_a_default(): void {
		// CAP-2 lets somebody decide a piece of work needs no review time, and
		// a figure invented here would quietly re-decide it — and bill for it.
		$this->assertSame( 10.0, WorkHours::planned( $this->item( array( 'hours_review' => 0, 'hours_delivery' => 0 ) ) ) );
	}

	/* ------------------------------------------------- what the ledger holds */

	public function test_the_position_is_read_from_the_entries_and_nowhere_else(): void {
		$position = WorkHours::position(
			array(
				$this->entry( Entries::WORK_RESERVATION, 13 ),
				$this->entry( Entries::WORK_RELEASE, 13 ),
				$this->entry( Entries::WORK_USAGE, 13 ),
			)
		);

		$this->assertSame( 0.0, $position['reserved'] );
		$this->assertSame( 13.0, $position['used'] );
	}

	public function test_entries_belonging_to_other_things_are_not_this_item_s_position(): void {
		/*
		 * The service hands in one source's entries, but a mistake there would
		 * charge a site's whole ledger to one work item and nothing would
		 * notice, because the arithmetic would still add up.
		 */
		$position = WorkHours::position(
			array(
				$this->entry( Entries::WORK_RESERVATION, 13 ),
				array(
					'event_type'  => Entries::MEETING_RESERVATION,
					'hours'       => -4.0,
					'source_type' => 'meeting-occurrence',
					'source_id'   => 'mto_one',
				),
			)
		);

		$this->assertSame( 13.0, $position['reserved'] );
		$this->assertSame( 0.0, $position['used'] );
	}

	/* --------------------------------------------------- reserving and spending */

	public function test_reaching_up_next_reserves_the_planned_hours(): void {
		$plan = WorkHours::plan( $this->item( array( 'stage' => 'up-next' ) ), array() );

		$this->assertSame( array( array( Entries::WORK_RESERVATION, 13.0 ) ), $this->pairs( $plan ) );
	}

	public function test_asking_again_at_up_next_plans_nothing(): void {
		/*
		 * The property that makes this safe to run on every move rather than on
		 * the three somebody remembered. A second evaluation of an unchanged
		 * item has to be silent, or a block and an unblock cost the client
		 * their hours twice.
		 */
		$plan = WorkHours::plan(
			$this->item( array( 'stage' => 'up-next' ) ),
			array( $this->entry( Entries::WORK_RESERVATION, 13 ) )
		);

		$this->assertSame( array(), $plan );
	}

	public function test_re_planning_upwards_appends_the_difference_and_not_the_total(): void {
		// COMM-3: nothing written is ever changed, so a bigger plan is another
		// entry for the gap. Re-reserving the whole figure would hold 26 hours
		// against a 20-hour job.
		$plan = WorkHours::plan(
			$this->item( array( 'stage' => 'up-next', 'hours_primary' => 17.0 ) ),
			array( $this->entry( Entries::WORK_RESERVATION, 13 ) )
		);

		$this->assertSame( array( array( Entries::WORK_RESERVATION, 7.0 ) ), $this->pairs( $plan ) );
	}

	public function test_re_planning_downwards_gives_the_difference_back(): void {
		$plan = WorkHours::plan(
			$this->item( array( 'stage' => 'up-next', 'hours_primary' => 5.0 ) ),
			array( $this->entry( Entries::WORK_RESERVATION, 13 ) )
		);

		$this->assertSame( array( array( Entries::WORK_RELEASE, 5.0 ) ), $this->pairs( $plan ) );
	}

	public function test_starting_work_releases_the_reservation_as_it_books_the_usage(): void {
		/*
		 * Both, and in this order. Booking the usage without the release is the
		 * obvious implementation and it bills every client double; doing it the
		 * other way round dips the balance through a figure the site never
		 * actually owed, which a gate somewhere else will one day refuse.
		 */
		$plan = WorkHours::plan(
			$this->item( array( 'stage' => 'in-development' ) ),
			array( $this->entry( Entries::WORK_RESERVATION, 13 ) )
		);

		$this->assertSame(
			array(
				array( Entries::WORK_RELEASE, 13.0 ),
				array( Entries::WORK_USAGE, 13.0 ),
			),
			$this->pairs( $plan )
		);
	}

	public function test_work_that_has_started_and_not_changed_plans_nothing(): void {
		$plan = WorkHours::plan(
			$this->item( array( 'stage' => 'in-review' ) ),
			array(
				$this->entry( Entries::WORK_RESERVATION, 13 ),
				$this->entry( Entries::WORK_RELEASE, 13 ),
				$this->entry( Entries::WORK_USAGE, 13 ),
			)
		);

		$this->assertSame( array(), $plan );
	}

	public function test_hours_added_after_work_started_are_charged_as_they_are_added(): void {
		$plan = WorkHours::plan(
			$this->item( array( 'stage' => 'in-review', 'hours_primary' => 14.0 ) ),
			array(
				$this->entry( Entries::WORK_RESERVATION, 13 ),
				$this->entry( Entries::WORK_RELEASE, 13 ),
				$this->entry( Entries::WORK_USAGE, 13 ),
			)
		);

		$this->assertSame( array( array( Entries::WORK_USAGE, 4.0 ) ), $this->pairs( $plan ) );
	}

	public function test_spent_hours_are_never_handed_back(): void {
		/*
		 * The one asymmetry in the class, and the reason it is not a single
		 * "make the ledger match the plan" sum. Time somebody worked was worked.
		 * Cutting the plan afterwards is a write-off, which COMM-3 says is an
		 * adjustment with a reason on it — a decision, not arithmetic.
		 */
		$plan = WorkHours::plan(
			$this->item( array( 'stage' => 'in-review', 'hours_primary' => 2.0 ) ),
			array(
				$this->entry( Entries::WORK_RESERVATION, 13 ),
				$this->entry( Entries::WORK_RELEASE, 13 ),
				$this->entry( Entries::WORK_USAGE, 13 ),
			)
		);

		$this->assertSame( array(), $plan );
	}

	/* ------------------------------------------------------- and giving back */

	public function test_cancelling_before_the_work_starts_gives_every_hour_back(): void {
		$plan = WorkHours::plan(
			$this->item(
				array(
					'stage'            => 'up-next',
					'terminal_outcome' => Outcomes::CANCELLED,
				)
			),
			array( $this->entry( Entries::WORK_RESERVATION, 13 ) )
		);

		$this->assertSame( array( array( Entries::WORK_RELEASE, 13.0 ) ), $this->pairs( $plan ) );
	}

	public function test_cancelling_after_the_work_starts_gives_back_only_what_was_still_held(): void {
		$plan = WorkHours::plan(
			$this->item(
				array(
					'stage'            => 'in-development',
					'terminal_outcome' => Outcomes::CANCELLED,
				)
			),
			array(
				$this->entry( Entries::WORK_RESERVATION, 13 ),
				$this->entry( Entries::WORK_RELEASE, 13 ),
				$this->entry( Entries::WORK_USAGE, 13 ),
			)
		);

		$this->assertSame( array(), $plan );
	}

	public function test_every_closing_outcome_releases_and_not_only_cancellation(): void {
		/*
		 * Rejected and duplicate end work exactly as dead as cancelled does.
		 * Naming only the one in the issue title is how the other two keep a
		 * client's hours held against work nobody will ever do.
		 */
		foreach ( Outcomes::CLOSING as $outcome ) {
			$plan = WorkHours::plan(
				$this->item( array( 'stage' => 'up-next', 'terminal_outcome' => $outcome ) ),
				array( $this->entry( Entries::WORK_RESERVATION, 13 ) )
			);

			$this->assertSame(
				array( array( Entries::WORK_RELEASE, 13.0 ) ),
				$this->pairs( $plan ),
				$outcome . ' left the hours held'
			);
		}
	}

	public function test_being_sent_back_out_of_up_next_gives_the_hours_back(): void {
		// It is no longer planned work, so it is no longer committed hours. The
		// board lets an item go back to Triage and nothing about that move says
		// "hours" — which is exactly why it is decided by where the item is.
		$plan = WorkHours::plan(
			$this->item( array( 'stage' => 'triage' ) ),
			array( $this->entry( Entries::WORK_RESERVATION, 13 ) )
		);

		$this->assertSame( array( array( Entries::WORK_RELEASE, 13.0 ) ), $this->pairs( $plan ) );
	}

	/* ------------------------------------------------------------- blocked */

	public function test_blocking_planned_work_keeps_its_hours_held(): void {
		// Blocked is where the item is standing, not where it is in the flow.
		// Releasing here would hand the hours back and re-take them on unblock,
		// leaving two entries in the client's record for nothing happening.
		$plan = WorkHours::plan(
			$this->item( array( 'stage' => Stages::BLOCKED, 'prior_stage' => 'up-next' ) ),
			array( $this->entry( Entries::WORK_RESERVATION, 13 ) )
		);

		$this->assertSame( array(), $plan );
	}

	public function test_blocking_work_that_had_not_reached_up_next_reserves_nothing(): void {
		$plan = WorkHours::plan(
			$this->item( array( 'stage' => Stages::BLOCKED, 'prior_stage' => 'technical-audit' ) ),
			array()
		);

		$this->assertSame( array(), $plan );
	}

	public function test_blocking_work_in_development_leaves_the_spend_alone(): void {
		$plan = WorkHours::plan(
			$this->item( array( 'stage' => Stages::BLOCKED, 'prior_stage' => 'in-development' ) ),
			array(
				$this->entry( Entries::WORK_RESERVATION, 13 ),
				$this->entry( Entries::WORK_RELEASE, 13 ),
				$this->entry( Entries::WORK_USAGE, 13 ),
			)
		);

		$this->assertSame( array(), $plan );
	}

	public function test_cancelling_from_blocked_gives_back_what_blocked_was_holding(): void {
		$plan = WorkHours::plan(
			$this->item(
				array(
					'stage'            => Stages::BLOCKED,
					'prior_stage'      => 'up-next',
					'terminal_outcome' => Outcomes::CANCELLED,
				)
			),
			array( $this->entry( Entries::WORK_RESERVATION, 13 ) )
		);

		$this->assertSame( array( array( Entries::WORK_RELEASE, 13.0 ) ), $this->pairs( $plan ) );
	}

	/* ---------------------------------------------------- what never charges */

	public function test_a_free_bug_never_touches_the_ledger(): void {
		// COMM-5. A bug Forge delivered is fixed at nobody's cost, and a
		// reservation against it is a bill for our own mistake.
		$plan = WorkHours::plan(
			$this->item( array( 'stage' => 'up-next', 'commercial_class' => 'free-bug' ) ),
			array()
		);

		$this->assertSame( array(), $plan );
	}

	public function test_unclassified_work_never_touches_the_ledger(): void {
		/*
		 * Nobody has said whether this is chargeable, and the safe reading of
		 * silence is not "charge them". G-TRIAGE-8 makes the classification a
		 * condition of leaving Triage, so work reaching Up Next unclassified is
		 * a bug somewhere else — and this must not turn it into a bill.
		 */
		$plan = WorkHours::plan(
			$this->item( array( 'stage' => 'up-next', 'commercial_class' => 'unclassified' ) ),
			array()
		);

		$this->assertSame( array(), $plan );
	}

	public function test_a_free_bug_still_gives_back_hours_taken_while_it_was_chargeable(): void {
		/*
		 * Reclassified after it was planned. Skipping the whole class for a
		 * free bug would be the simple reading and it would leave the earlier
		 * reservation held for ever, with nothing afterwards to notice.
		 */
		$plan = WorkHours::plan(
			$this->item( array( 'stage' => 'up-next', 'commercial_class' => 'free-bug' ) ),
			array( $this->entry( Entries::WORK_RESERVATION, 13 ) )
		);

		$this->assertSame( array( array( Entries::WORK_RELEASE, 13.0 ) ), $this->pairs( $plan ) );
	}

	public function test_work_planned_at_no_hours_writes_no_entry(): void {
		// A nought-hour entry says nothing and adds a row to a record people
		// read.
		$plan = WorkHours::plan(
			$this->item( array( 'stage' => 'up-next', 'hours_primary' => 0, 'hours_review' => 0, 'hours_delivery' => 0 ) ),
			array()
		);

		$this->assertSame( array(), $plan );
	}

	/* ------------------------------------------- and the thing it is all for */

	public function test_no_sequence_of_moves_leaves_the_ledger_and_the_item_disagreeing(): void {
		/*
		 * #149's acceptance criterion, run rather than argued. Every path the
		 * board allows, walked, applying the plan at each step and checking the
		 * two invariants after every one of them:
		 *
		 * - what the ledger holds is what the item's stage says it should,
		 * - and spend only ever goes up.
		 *
		 * The paths include the ones nobody writes a branch for: blocked and
		 * back, sent back out of Up Next and forward again, cancelled from
		 * three different places.
		 */
		$paths = array(
			'straight through'      => array( 'triage', 'up-next', 'in-development', 'in-review', 'completed', 'released' ),
			'blocked and back'      => array( 'up-next', 'blocked:up-next', 'up-next', 'in-development', 'blocked:in-development', 'in-development' ),
			'sent back and forward' => array( 'up-next', 'triage', 'up-next', 'in-development' ),
			'planned twice'         => array( 'up-next', 'up-next', 'up-next' ),
			'cancelled unplanned'   => array( 'triage', 'cancelled:triage' ),
			'cancelled planned'     => array( 'up-next', 'cancelled:up-next' ),
			'cancelled in flight'   => array( 'up-next', 'in-development', 'cancelled:in-development' ),
			'cancelled from block'  => array( 'up-next', 'blocked:up-next', 'cancelled:blocked' ),
		);

		foreach ( $paths as $name => $path ) {
			$entries = array();
			$spent   = 0.0;

			foreach ( $path as $step ) {
				$item     = $this->item( $this->step_to_item( $step ) );
				$plan     = WorkHours::plan( $item, $entries );
				$entries  = array_merge( $entries, $this->as_entries( $plan ) );
				$position = WorkHours::position( $entries );

				$this->assertSame(
					WorkHours::holds( $item ),
					$position['reserved'],
					$name . ' held the wrong hours at ' . $step
				);

				$this->assertGreaterThanOrEqual( $spent, $position['used'], $name . ' gave spent hours back at ' . $step );
				$spent = $position['used'];

				// And the plan is settled: running it again changes nothing.
				$this->assertSame( array(), WorkHours::plan( $item, $entries ), $name . ' was not settled at ' . $step );
			}
		}
	}

	/**
	 * One step of a path, as the fields an item would be carrying there.
	 *
	 * `blocked:x` is blocked from x; `cancelled:x` is cancelled at x.
	 *
	 * @param string $step The step.
	 * @return array<string, mixed>
	 */
	private function step_to_item( string $step ): array {
		if ( 0 === strpos( $step, 'blocked:' ) ) {
			return array(
				'stage'       => Stages::BLOCKED,
				'prior_stage' => substr( $step, strlen( 'blocked:' ) ),
			);
		}

		if ( 0 === strpos( $step, 'cancelled:' ) ) {
			$at = substr( $step, strlen( 'cancelled:' ) );

			return array(
				'stage'            => Stages::BLOCKED === $at ? Stages::BLOCKED : $at,
				'prior_stage'      => Stages::BLOCKED === $at ? 'up-next' : '',
				'terminal_outcome' => Outcomes::CANCELLED,
			);
		}

		return array( 'stage' => $step );
	}

	/**
	 * A plan, as the entries the ledger would then be holding.
	 *
	 * @param array<int, array<string, mixed>> $plan What plan() returned.
	 * @return array<int, array<string, mixed>>
	 */
	private function as_entries( array $plan ): array {
		return array_map(
			fn( array $entry ): array => $this->entry( (string) $entry['event_type'], (float) $entry['hours'] ),
			$plan
		);
	}
}
