<?php
/**
 * How far through a client is, and whether they can go live.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Onboarding\Progress;
use Blueworx\Forge\Onboarding\Statuses;
use PHPUnit\Framework\TestCase;

/**
 * ONB-1 and #164. Completion is the share of required steps approved, and
 * launch readiness is a separate question over the launch-critical ones —
 * because a site at 95% with an unapproved DNS step is not nearly ready.
 *
 * Both are worked out from the steps. Neither is stored, so neither can be
 * written to directly, which is what #164 asks for.
 */
final class OnboardingProgressTest extends TestCase {

	/**
	 * One step, as the site holds it.
	 *
	 * @param string               $status    Where it is.
	 * @param array<string, mixed> $overrides optional, launch_critical.
	 * @return array<string, mixed>
	 */
	private function step( string $status, array $overrides = array() ): array {
		return array_merge(
			array(
				'status'          => $status,
				'optional'        => 0,
				'launch_critical' => 0,
			),
			$overrides
		);
	}

	public function test_nothing_done_is_nothing_done(): void {
		$progress = Progress::of(
			array(
				$this->step( Statuses::NOT_STARTED ),
				$this->step( Statuses::IN_PROGRESS ),
			)
		);

		$this->assertSame( 0, $progress['approved'] );
		$this->assertSame( 2, $progress['required'] );
		$this->assertSame( 0.0, $progress['completion'] );
	}

	public function test_everything_approved_is_finished(): void {
		$progress = Progress::of(
			array(
				$this->step( Statuses::APPROVED ),
				$this->step( Statuses::APPROVED ),
			)
		);

		$this->assertSame( 100.0, $progress['completion'] );
	}

	public function test_not_applicable_counts_as_settled(): void {
		/*
		 * A step that does not apply to this client is not work anybody is
		 * waiting for. Counting it as outstanding would hold a client at 80%
		 * for ever over something nobody is going to do.
		 */
		$progress = Progress::of(
			array(
				$this->step( Statuses::APPROVED ),
				$this->step( Statuses::NOT_APPLICABLE ),
			)
		);

		$this->assertSame( 100.0, $progress['completion'] );
	}

	public function test_submitted_is_not_approved(): void {
		// Handed in is not signed off (ONB-2). Only a reviewer ends a step.
		$progress = Progress::of(
			array(
				$this->step( Statuses::APPROVED ),
				$this->step( Statuses::SUBMITTED ),
			)
		);

		$this->assertSame( 50.0, $progress['completion'] );
	}

	public function test_optional_steps_are_not_counted_against_anybody(): void {
		// ONB-1: completion is over *required* steps.
		$progress = Progress::of(
			array(
				$this->step( Statuses::APPROVED ),
				$this->step( Statuses::NOT_STARTED, array( 'optional' => 1 ) ),
			)
		);

		$this->assertSame( 1, $progress['required'] );
		$this->assertSame( 100.0, $progress['completion'] );
	}

	public function test_a_client_with_no_steps_is_not_secretly_finished(): void {
		/*
		 * Nought out of nought is not a hundred per cent. A checklist nobody
		 * has been given yet reads as nothing done, not as complete — the
		 * difference decides whether anybody goes looking.
		 */
		$progress = Progress::of( array() );

		$this->assertSame( 0.0, $progress['completion'] );
		$this->assertFalse( $progress['launch_ready'] );
	}

	public function test_launch_readiness_is_not_the_same_question_as_completion(): void {
		/*
		 * The case ONB-1 exists to catch: nearly everything done, and the one
		 * thing that gates a launch still outstanding.
		 */
		$steps = array(
			$this->step( Statuses::APPROVED ),
			$this->step( Statuses::APPROVED ),
			$this->step( Statuses::APPROVED ),
			$this->step( Statuses::IN_PROGRESS, array( 'launch_critical' => 1 ) ),
		);

		$progress = Progress::of( $steps );

		$this->assertSame( 75.0, $progress['completion'] );
		$this->assertFalse( $progress['launch_ready'] );
	}

	public function test_a_site_is_ready_when_every_launch_critical_step_is_settled(): void {
		$progress = Progress::of(
			array(
				$this->step( Statuses::APPROVED, array( 'launch_critical' => 1 ) ),
				$this->step( Statuses::NOT_APPLICABLE, array( 'launch_critical' => 1 ) ),
				$this->step( Statuses::IN_PROGRESS ),
			)
		);

		$this->assertTrue( $progress['launch_ready'] );
		$this->assertLessThan( 100.0, $progress['completion'] );
	}

	public function test_the_outstanding_launch_steps_are_named(): void {
		// #166 refuses a launch and says which steps are in the way, so the
		// figure has to carry them rather than only a yes or no.
		$progress = Progress::of(
			array(
				$this->step( Statuses::APPROVED, array( 'launch_critical' => 1 ) ),
				array_merge(
					$this->step( Statuses::IN_PROGRESS, array( 'launch_critical' => 1 ) ),
					array(
						'id'    => 'obs_dns',
						'title' => 'Delegate the registrar',
					)
				),
			)
		);

		$this->assertCount( 1, $progress['blocking'] );
		$this->assertSame( 'obs_dns', $progress['blocking'][0]['id'] );
		$this->assertSame( 'Delegate the registrar', $progress['blocking'][0]['title'] );
	}

	public function test_an_optional_launch_critical_step_still_gates_the_launch(): void {
		/*
		 * Optional means it does not count towards the percentage, not that a
		 * site may go live without it. If a step genuinely need not happen
		 * before launch, it is not launch-critical — and a template that says
		 * both is saying something contradictory, which is resolved in favour
		 * of the stricter reading.
		 */
		$progress = Progress::of(
			array(
				$this->step(
					Statuses::IN_PROGRESS,
					array(
						'optional'        => 1,
						'launch_critical' => 1,
					)
				),
			)
		);

		$this->assertFalse( $progress['launch_ready'] );
	}

	public function test_a_checklist_that_gates_nothing_never_reads_as_ready(): void {
		/*
		 * A template naming no launch-critical step at all is misconfigured —
		 * ONB-1 names five categories that gate a launch, so a real one always
		 * has some. Of the two ways to be wrong here, refusing every launch is
		 * noticed and corrected within the hour; declaring a site ready when
		 * nothing was ever checked is noticed after it is live.
		 */
		$progress = Progress::of(
			array(
				$this->step( Statuses::APPROVED ),
				$this->step( Statuses::APPROVED ),
			)
		);

		$this->assertSame( 100.0, $progress['completion'] );
		$this->assertFalse( $progress['launch_ready'] );
		$this->assertSame( array(), $progress['blocking'] );
	}

	public function test_completion_is_rounded_to_something_a_person_would_say(): void {
		// One of three. 33.3, not 33.33333333333333.
		$progress = Progress::of(
			array(
				$this->step( Statuses::APPROVED ),
				$this->step( Statuses::NOT_STARTED ),
				$this->step( Statuses::NOT_STARTED ),
			)
		);

		$this->assertSame( 33.3, $progress['completion'] );
	}
}
