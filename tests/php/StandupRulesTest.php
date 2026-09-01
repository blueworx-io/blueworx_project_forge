<?php
/**
 * What needs attention today.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Standup\Rules;
use PHPUnit\Framework\TestCase;

/**
 * #169. The twelve inclusion rules, and the property the whole board rests on.
 *
 * **A card leaves only when the condition actually resolves.** Every test here
 * is really a test of that, from one side or the other: nothing is stored, so
 * the same records always produce the same list, and changing the record is the
 * only thing that changes it. There is deliberately no test of "dismissing a
 * card" anywhere in this file, because there is nothing here that could hide
 * one — which is the point.
 *
 * The pairs matter more than the singles. Finished work is never overdue;
 * settled steps are never overdue; a request that has been answered is not
 * waiting. Each of those is a rule that, got wrong, leaves a board full of
 * things nobody can clear — and a board people stop reading is worse than no
 * board.
 */
final class StandupRulesTest extends TestCase {

	private const TODAY = '2026-03-10';

	/**
	 * A piece of work.
	 *
	 * @param array<string, mixed> $overrides Anything else.
	 * @return array<string, mixed>
	 */
	private function item( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'             => 'wrk_one',
				'title'          => 'The checkout page',
				'stage'          => 'in-development',
				'client_id'      => 'cli_one',
				'client_site_id' => 'cst_one',
				'planned_due'    => '',
			),
			$overrides
		);
	}

	/**
	 * Which rules a set of cards names.
	 *
	 * @param array<int, array<string, mixed>> $cards Cards.
	 * @return array<int, string>
	 */
	private function rules( array $cards ): array {
		return array_map( static fn( array $card ): string => (string) $card['rule'], $cards );
	}

	/* ---------------------------------------------------------------- the work */

	public function test_work_past_its_date_is_overdue(): void {
		$this->assertSame(
			array( Rules::OVERDUE ),
			$this->rules( Rules::for_item( $this->item( array( 'planned_due' => '2026-03-01' ) ), self::TODAY ) )
		);
	}

	public function test_work_due_today_is_not_yet_late(): void {
		// Somebody with something due today still has today to do it in, and
		// being told it is late is being told something untrue.
		$this->assertSame(
			array( Rules::DUE_TODAY ),
			$this->rules( Rules::for_item( $this->item( array( 'planned_due' => self::TODAY ) ), self::TODAY ) )
		);
	}

	public function test_work_due_later_is_not_on_the_list(): void {
		$this->assertSame(
			array(),
			Rules::for_item( $this->item( array( 'planned_due' => '2026-04-01' ) ), self::TODAY )
		);
	}

	public function test_released_work_is_never_late_however_long_it_took(): void {
		/*
		 * The rule that decides whether the board is usable. Without it every
		 * item ever delivered a day late stays on the list for ever, and within
		 * a month nobody opens it.
		 */
		$this->assertSame(
			array(),
			Rules::for_item(
				$this->item(
					array(
						'stage'       => 'released',
						'planned_due' => '2026-01-01',
					)
				),
				self::TODAY
			)
		);
	}

	public function test_work_with_no_date_is_not_late(): void {
		$this->assertSame( array(), Rules::for_item( $this->item(), self::TODAY ) );
	}

	public function test_blocked_work_is_on_the_list(): void {
		$this->assertSame(
			array( Rules::BLOCKED ),
			$this->rules( Rules::for_item( $this->item( array( 'stage' => 'blocked' ) ), self::TODAY ) )
		);
	}

	public function test_blocked_work_that_is_also_late_is_both(): void {
		/*
		 * Two different conversations — one with whoever can unblock it, one
		 * with whoever is waiting — so both are reported and the board decides
		 * how to show them. Picking one would hide the other.
		 */
		$this->assertSame(
			array( Rules::OVERDUE, Rules::BLOCKED ),
			$this->rules(
				Rules::for_item(
					$this->item(
						array(
							'stage'       => 'blocked',
							'planned_due' => '2026-03-01',
						)
					),
					self::TODAY
				)
			)
		);
	}

	public function test_work_waiting_to_be_reviewed_names_who_it_waits_on(): void {
		$cards = Rules::for_item(
			$this->item(
				array(
					'stage'       => 'in-review',
					'reviewer_id' => 'usr_reviewer',
				)
			),
			self::TODAY
		);

		$this->assertSame( array( Rules::AWAITING_REVIEW ), $this->rules( $cards ) );
		$this->assertSame( 'usr_reviewer', $cards[0]['detail']['waiting_on'] );
	}

	public function test_work_ready_and_not_released_is_waiting_on_somebody(): void {
		$cards = Rules::for_item(
			$this->item(
				array(
					'stage'         => 'completed',
					'deliverer_id'  => 'usr_deliverer',
				)
			),
			self::TODAY
		);

		$this->assertSame( array( Rules::AWAITING_RELEASE ), $this->rules( $cards ) );
		$this->assertSame( 'usr_deliverer', $cards[0]['detail']['waiting_on'] );
	}

	public function test_work_sent_back_is_somebody_to_pick_it_up(): void {
		$this->assertSame(
			array( Rules::RETURNED ),
			$this->rules( Rules::for_item( $this->item( array( 'was_returned' => true ) ), self::TODAY ) )
		);
	}

	public function test_work_sent_back_and_then_finished_is_off_the_list(): void {
		$this->assertSame(
			array(),
			Rules::for_item(
				$this->item(
					array(
						'was_returned' => true,
						'stage'        => 'released',
					)
				),
				self::TODAY
			)
		);
	}

	public function test_a_gate_nobody_has_satisfied_is_on_the_list(): void {
		$cards = Rules::for_item(
			$this->item( array( 'unmet' => array( array( 'id' => 'scope-agreed' ) ) ) ),
			self::TODAY
		);

		$this->assertSame( array( Rules::GATE_UNMET ), $this->rules( $cards ) );
		$this->assertCount( 1, $cards[0]['detail']['unmet'] );
	}

	public function test_a_gate_on_finished_work_is_not_a_problem(): void {
		$this->assertSame(
			array(),
			Rules::for_item(
				$this->item(
					array(
						'stage' => 'released',
						'unmet' => array( array( 'id' => 'scope-agreed' ) ),
					)
				),
				self::TODAY
			)
		);
	}

	/* ------------------------------------------- and only when it is worth saying */

	/*
	 * #251. The rule as first written matched 171 of 204 cards on a real board,
	 * which is the same as matching nothing: the list stopped being readable and
	 * took every other rule down with it. What follows is the narrowing, and the
	 * last two tests are the ones that matter most — a narrowing that quietly
	 * drops work somebody does need to look at is worse than the flood.
	 */

	public function test_an_idea_nobody_has_committed_to_is_not_stuck(): void {
		// Nothing has been agreed for it, so everything it is missing is missing
		// on purpose.
		$this->assertSame(
			array(),
			Rules::for_item(
				$this->item(
					array(
						'stage' => 'triage',
						'unmet' => array( array( 'id' => 'scope-agreed' ) ),
					)
				),
				self::TODAY
			)
		);
	}

	public function test_no_early_stage_reports_a_requirement(): void {
		$early = array( 'future-idea', 'triage', 'bug-tracking', 'documentation-period', 'technical-audit', 'design-process' );

		foreach ( $early as $stage ) {
			$cards = Rules::for_item(
				$this->item(
					array(
						'stage' => $stage,
						'unmet' => array( array( 'id' => 'scope-agreed' ) ),
					)
				),
				self::TODAY
			);

			$this->assertNotContains( Rules::GATE_UNMET, $this->rules( $cards ), $stage );
		}
	}

	public function test_work_that_has_been_scheduled_does_report_one(): void {
		$this->assertSame(
			array( Rules::GATE_UNMET ),
			$this->rules(
				Rules::for_item(
					$this->item(
						array(
							'stage' => 'up-next',
							'unmet' => array( array( 'id' => 'scope-agreed' ) ),
						)
					),
					self::TODAY
				)
			)
		);
	}

	public function test_blocked_work_is_not_also_reported_as_stuck(): void {
		// It already has a card saying what is holding it up. A second one adds
		// nothing and costs a line of a list that has to fit on a screen.
		$this->assertSame(
			array( Rules::BLOCKED ),
			$this->rules(
				Rules::for_item(
					$this->item(
						array(
							'stage' => 'blocked',
							'unmet' => array( array( 'id' => 'scope-agreed' ) ),
						)
					),
					self::TODAY
				)
			)
		);
	}

	public function test_work_with_a_reviewer_is_not_also_reported_as_stuck(): void {
		$this->assertSame(
			array( Rules::AWAITING_REVIEW ),
			$this->rules(
				Rules::for_item(
					$this->item(
						array(
							'stage' => 'in-review',
							'unmet' => array( array( 'id' => 'scope-agreed' ) ),
						)
					),
					self::TODAY
				)
			)
		);
	}

	public function test_work_waiting_to_go_out_is_not_also_reported_as_stuck(): void {
		$this->assertSame(
			array( Rules::AWAITING_RELEASE ),
			$this->rules(
				Rules::for_item(
					$this->item(
						array(
							'stage' => 'completed',
							'unmet' => array( array( 'id' => 'scope-agreed' ) ),
						)
					),
					self::TODAY
				)
			)
		);
	}

	public function test_work_handed_back_is_not_also_reported_as_stuck(): void {
		$this->assertSame(
			array( Rules::RETURNED ),
			$this->rules(
				Rules::for_item(
					$this->item(
						array(
							'was_returned' => 1,
							'unmet'        => array( array( 'id' => 'scope-agreed' ) ),
						)
					),
					self::TODAY
				)
			)
		);
	}

	public function test_late_work_still_says_what_is_holding_it_up(): void {
		/*
		 * The one the narrowing must not break. Work that is late *and* stuck is
		 * the most urgent thing on the board, and the date alone does not tell
		 * whoever picks it up what to do about it.
		 */
		$this->assertSame(
			array( Rules::OVERDUE, Rules::GATE_UNMET ),
			$this->rules(
				Rules::for_item(
					$this->item(
						array(
							'planned_due' => '2026-03-01',
							'unmet'       => array( array( 'id' => 'scope-agreed' ) ),
						)
					),
					self::TODAY
				)
			)
		);
	}

	public function test_the_narrowing_never_hides_a_card_of_another_rule(): void {
		// Whatever else changes, the other eleven rules answer exactly as they
		// did. A gate is allowed to go quiet; nothing else is.
		$cards = Rules::for_item(
			$this->item(
				array(
					'stage'        => 'blocked',
					'planned_due'  => '2026-03-01',
					'was_returned' => 1,
					'unmet'        => array( array( 'id' => 'scope-agreed' ) ),
				)
			),
			self::TODAY
		);

		$this->assertSame( array( Rules::OVERDUE, Rules::BLOCKED, Rules::RETURNED ), $this->rules( $cards ) );
	}

	/* ------------------------------------------------------- what clients want */

	public function test_an_unanswered_request_is_waiting_on_us(): void {
		$card = Rules::for_submission(
			array(
				'id'           => 'sub_one',
				'title'        => 'A booking form',
				'intake_state' => 'received',
			)
		);

		$this->assertSame( Rules::REQUEST_WAITING, $card['rule'] );
		$this->assertSame( 'sub_one', $card['subject_id'] );
	}

	public function test_a_request_being_looked_at_is_still_ours(): void {
		$this->assertSame(
			Rules::REQUEST_WAITING,
			Rules::for_submission( array( 'id' => 'sub_one', 'intake_state' => 'in-review' ) )['rule']
		);
	}

	public function test_an_answered_request_is_not_work(): void {
		foreach ( array( 'accepted', 'declined', 'converted' ) as $state ) {
			$this->assertSame(
				array(),
				Rules::for_submission( array( 'id' => 'sub_one', 'intake_state' => $state ) ),
				$state . ' has been answered'
			);
		}
	}

	public function test_a_step_handed_to_us_is_waiting_on_us(): void {
		$this->assertSame(
			array( Rules::ONBOARDING_WAITING ),
			$this->rules(
				Rules::for_step( array( 'id' => 'obs_one', 'status' => 'submitted' ), self::TODAY )
			)
		);
	}

	public function test_a_step_past_its_date_is_overdue(): void {
		$this->assertSame(
			array( Rules::ONBOARDING_OVERDUE ),
			$this->rules(
				Rules::for_step(
					array( 'id' => 'obs_one', 'status' => 'in-progress', 'due_on' => '2026-03-01' ),
					self::TODAY
				)
			)
		);
	}

	public function test_a_finished_step_is_never_overdue(): void {
		foreach ( array( 'approved', 'not-applicable' ) as $status ) {
			$this->assertSame(
				array(),
				Rules::for_step(
					array( 'id' => 'obs_one', 'status' => $status, 'due_on' => '2026-01-01' ),
					self::TODAY
				),
				$status . ' is finished with'
			);
		}
	}

	public function test_a_step_can_be_both_ours_and_late(): void {
		$this->assertSame(
			array( Rules::ONBOARDING_WAITING, Rules::ONBOARDING_OVERDUE ),
			$this->rules(
				Rules::for_step(
					array( 'id' => 'obs_one', 'status' => 'submitted', 'due_on' => '2026-03-01' ),
					self::TODAY
				)
			)
		);
	}

	/* ------------------------------------------------------------- the studio */

	public function test_somebody_over_their_hours_is_on_the_list(): void {
		$card = Rules::for_capacity(
			array( 'user_id' => 'usr_one', 'display_name' => 'Sam', 'band' => 'over', 'committed' => 45, 'available' => 37.5 )
		);

		$this->assertSame( Rules::OVER_COMMITTED, $card['rule'] );
		$this->assertSame( 'Sam', $card['detail']['display_name'] );
	}

	public function test_somebody_merely_busy_is_not(): void {
		/*
		 * Tight is a planning signal and belongs on the capacity screen. A daily
		 * list of everybody who is busy is a daily list of the whole studio.
		 */
		foreach ( array( 'tight', 'clear', 'unrecorded' ) as $band ) {
			$this->assertSame( array(), Rules::for_capacity( array( 'user_id' => 'usr_one', 'band' => $band ) ), $band );
		}
	}

	/* ----------------------------------------------------------- the whole list */

	public function test_the_list_is_everything_that_is_true(): void {
		$cards = Rules::evaluate(
			array(
				'items'            => array(
					$this->item( array( 'id' => 'wrk_late', 'planned_due' => '2026-03-01' ) ),
					$this->item( array( 'id' => 'wrk_stuck', 'stage' => 'blocked' ) ),
				),
				'submissions'      => array( array( 'id' => 'sub_one', 'intake_state' => 'received' ) ),
				'onboarding_steps' => array( array( 'id' => 'obs_one', 'status' => 'submitted' ) ),
				'capacity'         => array( array( 'user_id' => 'usr_one', 'band' => 'over' ) ),
				'interventions'    => array( array( 'id' => 'nev_one', 'subject_type' => 'notification' ) ),
			),
			self::TODAY
		);

		$this->assertSame(
			array(
				Rules::OVERDUE,
				Rules::BLOCKED,
				Rules::REQUEST_WAITING,
				Rules::ONBOARDING_WAITING,
				Rules::OVER_COMMITTED,
				Rules::NEEDS_INTERVENTION,
			),
			$this->rules( $cards )
		);
	}

	public function test_the_same_records_always_give_the_same_list(): void {
		/*
		 * Nothing is stored, so nothing can drift — and the order is fixed as
		 * well as the contents, because a list that reshuffles between two page
		 * loads is one nobody can work down.
		 */
		$state = array(
			'items' => array(
				$this->item( array( 'id' => 'wrk_b', 'stage' => 'blocked' ) ),
				$this->item( array( 'id' => 'wrk_a', 'planned_due' => '2026-03-01' ) ),
			),
		);

		$this->assertSame( Rules::evaluate( $state, self::TODAY ), Rules::evaluate( $state, self::TODAY ) );
	}

	public function test_an_empty_studio_has_an_empty_list(): void {
		// And says so by being empty, rather than by inventing a card.
		$this->assertSame( array(), Rules::evaluate( array(), self::TODAY ) );
	}

	public function test_a_card_names_the_record_it_is_about(): void {
		// So the board can open the thing rather than describing it.
		foreach ( Rules::evaluate( array( 'items' => array( $this->item( array( 'stage' => 'blocked' ) ) ) ), self::TODAY ) as $card ) {
			$this->assertSame( 'work_item', $card['subject_type'] );
			$this->assertSame( 'wrk_one', $card['subject_id'] );
			$this->assertTrue( Rules::exists( $card['rule'] ) );
		}
	}

	public function test_there_are_twelve_of_them(): void {
		$this->assertCount( 12, Rules::ALL );
		$this->assertSame( Rules::ALL, array_unique( Rules::ALL ) );
		$this->assertFalse( Rules::exists( 'looks-a-bit-quiet' ) );
	}
}
