<?php
/**
 * Time in meetings is time unavailable for work.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Capacity\Allocations;
use Blueworx\Forge\Meetings\Load;
use Blueworx\Forge\Meetings\Occurrence;
use PHPUnit\Framework\TestCase;

/**
 * #155, MEET-1. What a meeting takes out of somebody's week.
 *
 * **A standing meeting is the easiest commitment in the studio to forget.** It
 * never appears on a board, nobody moves it through a workflow, and it is
 * exactly the kind of time that makes a week that looked comfortable turn out
 * not to be. Two hours a fortnight is a working day a quarter, and a capacity
 * figure that ignores it is confidently wrong in the one direction that hurts:
 * it says there is room.
 *
 * So a meeting becomes an ordinary allocation, in the same shape work makes,
 * and everything downstream — the capacity screen, the gate at Up Next, the
 * client's room-or-not answer — counts it without being told about meetings at
 * all.
 *
 * Only the host. MEET-1 gives the schedule to the site's Point of Contact, and
 * they are the one person on it Forge holds a record for; attendees are a note,
 * not accounts, and inventing capacity effects for people we do not have is
 * worse than counting nobody.
 */
final class MeetingLoadTest extends TestCase {

	private const HOST = 'usr_host';

	/**
	 * One merged meeting.
	 *
	 * @param array<string, mixed> $overrides Anything to say differently.
	 * @return array<string, mixed>
	 */
	private function meeting( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'            => 'mto_one',
				'series_id'     => 'mts_one',
				'on'            => '2026-03-23',
				'at'            => '10:00',
				'status'        => Occurrence::SCHEDULED,
				'planned_hours' => 2.0,
			),
			$overrides
		);
	}

	/* ------------------------------------------------ what becomes a commitment */

	public function test_a_booked_meeting_becomes_an_allocation_against_its_host(): void {
		$allocations = Load::allocations( array( $this->meeting() ), self::HOST, 'cst_one', 'cli_one' );

		$this->assertCount( 1, $allocations );
		$this->assertSame( self::HOST, $allocations[0]['user_id'] );
		$this->assertSame( 2.0, $allocations[0]['hours'] );
	}

	public function test_a_meeting_lands_on_the_day_it_happens_and_not_across_a_week(): void {
		/*
		 * From and to are the same day. Work spreads across its planned dates
		 * because it is done over them; a meeting happens once, and spreading
		 * two hours across a fortnight would hide the afternoon it actually
		 * takes.
		 */
		$allocations = Load::allocations( array( $this->meeting() ), self::HOST, 'cst_one', 'cli_one' );

		$this->assertSame( '2026-03-23', $allocations[0]['from'] );
		$this->assertSame( '2026-03-23', $allocations[0]['to'] );
	}

	public function test_a_held_meeting_still_took_the_time(): void {
		// It happened. Leaving it out would make the week it was in look freer
		// in hindsight than it was, which is the week somebody is reporting on.
		$allocations = Load::allocations(
			array( $this->meeting( array( 'status' => Occurrence::HELD ) ) ),
			self::HOST,
			'cst_one',
			'cli_one'
		);

		$this->assertCount( 1, $allocations );
	}

	public function test_a_meeting_that_is_not_happening_takes_no_time(): void {
		foreach ( array( Occurrence::CANCELLED, Occurrence::NO_SHOW ) as $status ) {
			$this->assertSame(
				array(),
				Load::allocations( array( $this->meeting( array( 'status' => $status ) ) ), self::HOST, 'cst_one', 'cli_one' ),
				$status . ' still took somebody\'s time'
			);
		}
	}

	public function test_a_meeting_with_no_host_commits_nobody(): void {
		/*
		 * Rather than committing a blank. An allocation against nobody would
		 * either be dropped downstream or, worse, counted against whichever
		 * person an empty id happens to match.
		 */
		$this->assertSame( array(), Load::allocations( array( $this->meeting() ), '', 'cst_one', 'cli_one' ) );
	}

	public function test_a_meeting_costing_nothing_commits_nothing(): void {
		$this->assertSame(
			array(),
			Load::allocations( array( $this->meeting( array( 'planned_hours' => 0 ) ) ), self::HOST, 'cst_one', 'cli_one' )
		);
	}

	/* --------------------------------------- and it has to look like work does */

	public function test_a_meeting_allocation_is_the_same_shape_work_makes(): void {
		/*
		 * The point of the whole issue. Everything downstream — the capacity
		 * screen, the gate at Up Next, the client's room-or-not answer — reads
		 * allocations, and none of them should have to learn what a meeting is.
		 * A different shape here would mean teaching all three.
		 */
		$allocation = Load::allocations( array( $this->meeting() ), self::HOST, 'cst_one', 'cli_one' )[0];

		foreach ( array( 'user_id', 'client_id', 'client_site_id', 'from', 'to', 'hours', 'role', 'item_id', 'title' ) as $field ) {
			$this->assertArrayHasKey( $field, $allocation, $field . ' is missing from a meeting allocation' );
		}
	}

	public function test_a_meeting_spreads_onto_its_own_day(): void {
		// Proved through the same spreader work goes through, because "the same
		// shape" is only true if it survives the thing that consumes it.
		$allocation = Load::allocations( array( $this->meeting() ), self::HOST, 'cst_one', 'cli_one' )[0];

		$days = array(
			array(
				'date'  => '2026-03-23',
				'hours' => 8.0,
			),
			array(
				'date'  => '2026-03-24',
				'hours' => 8.0,
			),
		);

		$this->assertSame( array( '2026-03-23' => 2.0 ), Allocations::spread( $allocation, $days ) );
	}

	public function test_a_meeting_says_which_client_it_belongs_to(): void {
		// The capacity read spans clients on purpose, and every figure in it has
		// to be able to say whose it is — otherwise the drill-down that proves
		// the total cannot name what it is made of.
		$allocation = Load::allocations( array( $this->meeting() ), self::HOST, 'cst_two', 'cli_two' )[0];

		$this->assertSame( 'cst_two', $allocation['client_site_id'] );
		$this->assertSame( 'cli_two', $allocation['client_id'] );
	}

	public function test_several_meetings_each_take_their_own_time(): void {
		$allocations = Load::allocations(
			array(
				$this->meeting(),
				$this->meeting( array( 'id' => 'mto_two', 'on' => '2026-03-30', 'planned_hours' => 1.0 ) ),
			),
			self::HOST,
			'cst_one',
			'cli_one'
		);

		$this->assertSame( array( 2.0, 1.0 ), array_column( $allocations, 'hours' ) );
	}
}
