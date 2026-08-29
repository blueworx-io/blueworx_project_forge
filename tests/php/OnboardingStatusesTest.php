<?php
/**
 * What state an onboarding step is in, and what only looks like one.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Onboarding\Statuses;
use PHPUnit\Framework\TestCase;

/**
 * #161. Seven statuses somebody records, and overdue — which nobody records,
 * because it is a fact about today rather than about the step.
 */
final class OnboardingStatusesTest extends TestCase {

	public function test_seven_statuses_are_recorded(): void {
		$this->assertCount( 7, Statuses::ALL );
		$this->assertNotContains( 'overdue', Statuses::ALL );
	}

	public function test_a_status_outside_the_list_does_not_exist(): void {
		$this->assertTrue( Statuses::exists( Statuses::SUBMITTED ) );
		$this->assertFalse( Statuses::exists( 'overdue' ) );
		$this->assertFalse( Statuses::exists( '' ) );
	}

	public function test_the_two_that_finish_a_step_are_approved_and_not_applicable(): void {
		$this->assertSame(
			array( Statuses::APPROVED, Statuses::NOT_APPLICABLE ),
			Statuses::SETTLED
		);
	}

	public function test_a_step_past_its_date_is_overdue(): void {
		$this->assertTrue( Statuses::is_overdue( Statuses::IN_PROGRESS, '2026-09-01', '2026-09-02' ) );
	}

	public function test_a_step_on_its_date_is_not_yet_overdue(): void {
		// The day it is due is a day somebody still has.
		$this->assertFalse( Statuses::is_overdue( Statuses::IN_PROGRESS, '2026-09-01', '2026-09-01' ) );
	}

	public function test_finished_work_is_never_overdue(): void {
		/*
		 * Approved late is late, but it is done, and a board that keeps
		 * shouting about it is a board people stop reading.
		 */
		$this->assertFalse( Statuses::is_overdue( Statuses::APPROVED, '2026-09-01', '2026-12-01' ) );
		$this->assertFalse( Statuses::is_overdue( Statuses::NOT_APPLICABLE, '2026-09-01', '2026-12-01' ) );
	}

	public function test_a_step_with_no_date_is_never_overdue(): void {
		$this->assertFalse( Statuses::is_overdue( Statuses::IN_PROGRESS, '', '2026-12-01' ) );
	}

	public function test_blocked_work_can_still_be_overdue(): void {
		/*
		 * Blocked says why it is not moving. It does not stop the date passing,
		 * and a blocker nobody has cleared is exactly what should surface.
		 */
		$this->assertTrue( Statuses::is_overdue( Statuses::BLOCKED, '2026-09-01', '2026-09-02' ) );
	}
}
