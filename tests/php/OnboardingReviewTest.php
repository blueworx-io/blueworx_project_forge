<?php
/**
 * The studio deciding whether a step is done.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Onboarding\Review;
use Blueworx\Forge\Onboarding\StepEvents;
use Blueworx\Forge\Onboarding\Statuses;
use PHPUnit\Framework\TestCase;

/**
 * #163, ONB-2. Approve, return with feedback, or record a reasoned Not
 * Applicable where the template permits it.
 *
 * The feedback on a returned step is **worked out from the history rather than
 * stored on the step**, and that is the decision this file is mostly about. A
 * `feedback` column would have to be cleared when the step moves on, by
 * whichever caller happens to move it, and the first one that forgets leaves a
 * client reading last month's complaint against work that has since been
 * approved. The history already says what was asked and when; asking it is one
 * query and cannot go stale.
 */
final class OnboardingReviewTest extends TestCase {

	/**
	 * A step waiting to be looked at.
	 *
	 * @param array<string, mixed> $overrides Anything to change.
	 * @return array<string, mixed>
	 */
	private function step( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                    => 'obs_one',
				'client_site_id'        => 'cst_one',
				'status'                => Statuses::SUBMITTED,
				'allows_not_applicable' => 0,
				'record_version'        => 1,
			),
			$overrides
		);
	}

	/**
	 * An entry, as the history holds one.
	 *
	 * @param string $to     Status moved to.
	 * @param string $reason The reason given.
	 * @param int    $at     When.
	 * @return array<string, mixed>
	 */
	private function event( string $to, string $reason = '', int $at = 1000 ): array {
		return array(
			'action'      => StepEvents::MOVED,
			'to_status'   => $to,
			'reason'      => $reason,
			'occurred_at' => $at,
		);
	}

	/* ------------------------------------------------------------- what may */

	public function test_the_three_decisions_are_approve_return_and_not_applicable(): void {
		$this->assertSame(
			array( 'approve', 'return', 'not-applicable' ),
			Review::DECISIONS
		);
	}

	public function test_a_decision_outside_the_three_is_refused(): void {
		$this->assertNotSame( '', Review::refusal( 'reject', $this->step(), 'because' ) );
		$this->assertNotSame( '', Review::refusal( '', $this->step(), 'because' ) );
	}

	public function test_approving_needs_no_reason(): void {
		$this->assertSame( '', Review::refusal( 'approve', $this->step(), '' ) );
	}

	public function test_returning_without_saying_why_is_refused(): void {
		/*
		 * A step sent back with no feedback is an instruction with no content,
		 * and the client emails to ask what we meant — which is the thing this
		 * whole screen exists to avoid.
		 */
		$this->assertNotSame( '', Review::refusal( 'return', $this->step(), '' ) );
		$this->assertNotSame( '', Review::refusal( 'return', $this->step(), '   ' ) );
		$this->assertSame( '', Review::refusal( 'return', $this->step(), 'The invitation has not arrived.' ) );
	}

	public function test_not_applicable_needs_a_reason_too(): void {
		$permitted = $this->step( array( 'allows_not_applicable' => 1 ) );

		$this->assertNotSame( '', Review::refusal( 'not-applicable', $permitted, '' ) );
		$this->assertSame( '', Review::refusal( 'not-applicable', $permitted, 'They have no separate mail provider.' ) );
	}

	public function test_not_applicable_is_refused_where_the_template_forbids_it(): void {
		/*
		 * ONB-1 lets a template mark which steps may be waived. A step that may
		 * not be is one somebody has to actually do, and a reason is not a way
		 * round it.
		 */
		$this->assertNotSame(
			'',
			Review::refusal( 'not-applicable', $this->step(), 'It does not apply.' )
		);
	}

	public function test_a_step_already_settled_is_not_reviewed_again(): void {
		// Approving an approved step writes a second approval into the history
		// and tells nobody anything.
		foreach ( array( Statuses::APPROVED, Statuses::NOT_APPLICABLE ) as $status ) {
			$this->assertNotSame(
				'',
				Review::refusal( 'approve', $this->step( array( 'status' => $status ) ), '' ),
				$status . ' must not be reviewable again'
			);
		}
	}

	public function test_a_step_nobody_has_handed_over_can_still_be_waived(): void {
		/*
		 * Not applicable is a decision about whether the step is needed at all,
		 * so it does not wait for somebody to submit something they are never
		 * going to submit.
		 */
		$this->assertSame(
			'',
			Review::refusal(
				'not-applicable',
				$this->step(
					array(
						'status'                => Statuses::NOT_STARTED,
						'allows_not_applicable' => 1,
					)
				),
				'They are staying on their current host.'
			)
		);
	}

	public function test_a_step_nobody_has_handed_over_cannot_be_approved(): void {
		$this->assertNotSame(
			'',
			Review::refusal( 'approve', $this->step( array( 'status' => Statuses::NOT_STARTED ) ), '' )
		);
	}

	/* ------------------------------------------------------- where it lands */

	public function test_each_decision_lands_the_step_somewhere_definite(): void {
		$this->assertSame( Statuses::APPROVED, Review::status_for( 'approve' ) );
		$this->assertSame( Statuses::RETURNED, Review::status_for( 'return' ) );
		$this->assertSame( Statuses::NOT_APPLICABLE, Review::status_for( 'not-applicable' ) );
		$this->assertSame( '', Review::status_for( 'reject' ) );
	}

	/* ---------------------------------------------------------- the feedback */

	public function test_a_returned_step_shows_the_reason_it_was_returned(): void {
		$this->assertSame(
			'The invitation has not arrived.',
			Review::feedback_from(
				Statuses::RETURNED,
				array( $this->event( Statuses::RETURNED, 'The invitation has not arrived.', 100 ) )
			)
		);
	}

	public function test_the_most_recent_return_is_the_one_shown(): void {
		$this->assertSame(
			'Still nothing from the registrar.',
			Review::feedback_from(
				Statuses::RETURNED,
				array(
					$this->event( Statuses::RETURNED, 'The invitation has not arrived.', 100 ),
					$this->event( Statuses::SUBMITTED, '', 200 ),
					$this->event( Statuses::RETURNED, 'Still nothing from the registrar.', 300 ),
				)
			)
		);
	}

	public function test_a_step_that_has_moved_on_shows_no_feedback(): void {
		/*
		 * The reason the feedback is not a column. A client reading last
		 * month's complaint against work that has since been approved is worse
		 * than showing nothing at all.
		 */
		$this->assertSame(
			'',
			Review::feedback_from(
				Statuses::APPROVED,
				array(
					$this->event( Statuses::RETURNED, 'The invitation has not arrived.', 100 ),
					$this->event( Statuses::APPROVED, '', 200 ),
				)
			)
		);
	}

	public function test_a_step_never_returned_shows_no_feedback(): void {
		$this->assertSame( '', Review::feedback_from( Statuses::SUBMITTED, array() ) );
	}

	public function test_history_out_of_order_still_finds_the_latest(): void {
		// The history is read oldest first everywhere else, but nothing should
		// depend on the caller having done that.
		$this->assertSame(
			'Later',
			Review::feedback_from(
				Statuses::RETURNED,
				array(
					$this->event( Statuses::RETURNED, 'Later', 300 ),
					$this->event( Statuses::RETURNED, 'Earlier', 100 ),
				)
			)
		);
	}
}
