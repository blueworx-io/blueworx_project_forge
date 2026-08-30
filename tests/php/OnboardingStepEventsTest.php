<?php
/**
 * Who a step's history says did something.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Onboarding\StepEvents;
use PHPUnit\Framework\TestCase;

/**
 * #161, #162. An entry is attributable, and a client site can be the one it is
 * attributed to.
 *
 * The original rule was "no actor, no entry", and the reason still holds: a
 * change nobody is recorded as having made proves nothing later, which is
 * exactly when anybody reads it. What changed with the client checklist page is
 * that a client site is not a person here — it holds a key, not an account —
 * and so it could satisfy no version of that rule.
 *
 * Work\Comments met this first and answered it with a second column. This is
 * the same answer, deliberately, rather than a second way of saying it: an
 * entry names a person **or** a site, never both and never neither.
 */
final class OnboardingStepEventsTest extends TestCase {

	/**
	 * A complete entry, made by a member of staff.
	 *
	 * @return array<string, mixed>
	 */
	private function entry(): array {
		return array(
			'step_id'        => 'obs_abc',
			'client_site_id' => 'cst_abc',
			'action'         => StepEvents::ANSWERED,
			'actor'          => 7,
		);
	}

	public function test_an_entry_from_nobody_at_all_is_refused(): void {
		$without = $this->entry();
		unset( $without['actor'] );

		$this->assertSame( array(), StepEvents::row_from( $without ) );
	}

	public function test_a_client_site_may_be_the_one_that_did_it(): void {
		$row = StepEvents::row_from(
			array_merge(
				$this->entry(),
				array(
					'actor'      => 0,
					'actor_site' => 'st_clientsite',
				)
			)
		);

		$this->assertNotSame( array(), $row );
		$this->assertSame( 'st_clientsite', $row['actor_site'] );
		$this->assertSame( 0, $row['actor'] );
	}

	public function test_an_entry_cannot_be_both_a_person_and_a_site(): void {
		/*
		 * Two different stories could be told about such a row, and the point
		 * of a history is that only one can.
		 */
		$this->assertSame(
			array(),
			StepEvents::row_from( array_merge( $this->entry(), array( 'actor_site' => 'st_clientsite' ) ) )
		);
	}

	public function test_an_action_outside_the_list_is_refused(): void {
		$this->assertSame(
			array(),
			StepEvents::row_from( array_merge( $this->entry(), array( 'action' => 'deleted' ) ) )
		);
	}

	public function test_an_entry_about_no_step_is_refused(): void {
		$this->assertSame(
			array(),
			StepEvents::row_from( array_merge( $this->entry(), array( 'step_id' => '' ) ) )
		);
	}

	public function test_a_reason_is_kept_but_bounded(): void {
		$row = StepEvents::row_from(
			array_merge( $this->entry(), array( 'reason' => str_repeat( 'r', StepEvents::MAX_REASON + 100 ) ) )
		);

		$this->assertSame( StepEvents::MAX_REASON, mb_strlen( (string) $row['reason'] ) );
	}

	public function test_the_review_actions_are_recordable(): void {
		// #163 approves, returns and marks not applicable; each is a move, and
		// each has to be able to carry the reason that goes with it.
		foreach ( array( StepEvents::MOVED, StepEvents::ANSWERED, StepEvents::REASSIGNED, StepEvents::CREATED ) as $action ) {
			$this->assertNotSame(
				array(),
				StepEvents::row_from( array_merge( $this->entry(), array( 'action' => $action ) ) )
			);
		}
	}
}
