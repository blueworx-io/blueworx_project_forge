<?php
/**
 * A task's start, set by Forge from its dates.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\StartDate;
use PHPUnit\Framework\TestCase;

/**
 * #513. Nobody enters a start any more: it is the earliest of the four dates,
 * kept so the Gantt and the client timelines still have somewhere to begin.
 */
final class WorkStartDateTest extends TestCase {

	public function test_the_start_is_the_earliest_date(): void {
		$settled = StartDate::settle(
			array(
				'design_target' => '2026-10-14',
				'planned_due'   => '2026-10-16',
			)
		);

		$this->assertSame( '2026-10-14', $settled['planned_start'] );
	}

	public function test_a_date_already_on_the_task_counts(): void {
		$settled = StartDate::settle(
			array( 'planned_due' => '2026-10-16' ),
			array( 'design_target' => '2026-10-13' )
		);

		$this->assertSame( '2026-10-13', $settled['planned_start'] );
	}

	public function test_deliver_by_alone_is_the_start(): void {
		$this->assertSame( '2026-10-16', StartDate::settle( array( 'planned_due' => '2026-10-16' ) )['planned_start'] );
	}

	public function test_a_start_given_outright_is_kept(): void {
		$settled = StartDate::settle(
			array(
				'planned_start' => '2026-10-01',
				'planned_due'   => '2026-10-16',
			)
		);

		$this->assertSame( '2026-10-01', $settled['planned_start'] );
	}

	public function test_a_recurring_chore_keeps_its_own_start(): void {
		$changes = array( 'planned_due' => '2026-10-16' );

		$this->assertSame( $changes, StartDate::settle( $changes, array( 'assignees' => array( 'usr_one' ) ) ) );
		$this->assertSame( $changes, StartDate::settle( $changes, array( 'assignees' => '["usr_one"]' ) ) );
	}

	public function test_an_edit_without_dates_is_left_alone(): void {
		$changes = array( 'title' => 'Renamed' );

		$this->assertSame( $changes, StartDate::settle( $changes, array( 'planned_due' => '2026-10-16' ) ) );
	}
}
