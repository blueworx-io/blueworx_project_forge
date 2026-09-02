<?php
/**
 * Meetings draw from the same pool as work, and only when they happen.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Commerce\Entries;
use Blueworx\Forge\Meetings\MeetingHours;
use Blueworx\Forge\Meetings\Occurrence;
use PHPUnit\Framework\TestCase;

/**
 * #154, MEET-4 and MEET-5. What a meeting costs, and when.
 *
 * **Only a held meeting draws hours.** MEET-5 settled that: no
 * late-cancellation charge, no no-show charge, and clients are not required to
 * cancel at all. So a meeting nobody turned up to, one called off an hour
 * before, and one everybody simply forgot all leave the balance exactly where
 * it was — and the last of those is the one an implementation gets wrong,
 * because nothing happens to trigger it.
 *
 * That is why this decides from the meeting's position rather than from an
 * event. There is no scheduled job in this plugin, and a release that depends
 * on a cron having fired is a balance that is wrong whenever it did not. A
 * meeting that has been and gone unheld is *already* released as far as this
 * class is concerned, and the entry that says so is written the next time
 * anybody looks.
 *
 * The same design as work hours (#149), for the same reason: reserving,
 * converting and releasing hang off where the thing is now, so the paths nobody
 * wrote a branch for behave like the ones they did.
 */
final class MeetingHoursTest extends TestCase {

	private const TODAY = '2026-03-02';

	/**
	 * A scheduled meeting, three weeks out, costing two hours.
	 *
	 * @param array<string, mixed> $overrides Anything to say differently.
	 * @return array<string, mixed>
	 */
	private function meeting( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'            => 'mto_one',
				'on'            => '2026-03-23',
				'status'        => Occurrence::SCHEDULED,
				'planned_hours' => 2.0,
			),
			$overrides
		);
	}

	/**
	 * A ledger entry against this meeting, as the ledger stores one.
	 *
	 * @param string $type  Event type.
	 * @param float  $hours Magnitude.
	 * @return array<string, mixed>
	 */
	private function entry( string $type, float $hours ): array {
		return array(
			'event_type' => $type,
			'hours'      => Entries::signed( $type, $hours ),
		);
	}

	/**
	 * The plan, flattened to pairs.
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

	/* ----------------------------------------------- where a meeting stands */

	public function test_a_meeting_inside_the_horizon_holds_its_hours(): void {
		$this->assertSame(
			MeetingHours::RESERVED,
			MeetingHours::state_of( $this->meeting(), self::TODAY, '' )
		);
	}

	public function test_a_meeting_beyond_twelve_weeks_is_only_a_forecast(): void {
		/*
		 * MEET-4. Reserving the whole term at once locks up a balance against
		 * meetings a year away, and a client looking at their hours would see
		 * most of them spoken for by things that have not happened and might
		 * not.
		 */
		$this->assertSame(
			MeetingHours::FORECAST,
			MeetingHours::state_of( $this->meeting( array( 'on' => '2026-08-01' ) ), self::TODAY, '' )
		);
	}

	public function test_the_horizon_is_twelve_weeks_and_the_edge_is_inside_it(): void {
		$last = '2026-05-25';

		$this->assertSame( $last, MeetingHours::horizon_end( self::TODAY ) );
		$this->assertSame( MeetingHours::RESERVED, MeetingHours::state_of( $this->meeting( array( 'on' => $last ) ), self::TODAY, '' ) );
		$this->assertSame( MeetingHours::FORECAST, MeetingHours::state_of( $this->meeting( array( 'on' => '2026-05-26' ) ), self::TODAY, '' ) );
	}

	public function test_a_meeting_past_the_end_of_the_term_is_only_a_forecast(): void {
		/*
		 * Even inside the twelve weeks. The hours it would reserve belong to a
		 * package that has not been renewed, and holding them against a term
		 * that ends first is reserving hours the client has not bought.
		 */
		$this->assertSame(
			MeetingHours::FORECAST,
			MeetingHours::state_of( $this->meeting(), self::TODAY, '2026-03-15' )
		);
	}

	public function test_a_held_meeting_has_spent_its_hours(): void {
		$this->assertSame(
			MeetingHours::USED,
			MeetingHours::state_of( $this->meeting( array( 'status' => Occurrence::HELD ) ), self::TODAY, '' )
		);
	}

	public function test_a_meeting_that_did_not_happen_holds_nothing(): void {
		foreach ( array( Occurrence::CANCELLED, Occurrence::NO_SHOW ) as $status ) {
			$this->assertSame(
				MeetingHours::RELEASED,
				MeetingHours::state_of( $this->meeting( array( 'status' => $status ) ), self::TODAY, '' ),
				$status . ' was still holding hours'
			);
		}
	}

	public function test_a_meeting_that_has_been_and_gone_unheld_holds_nothing(): void {
		/*
		 * The one nothing triggers. Nobody cancelled it, nobody marked it held,
		 * and there is no scheduled job here to notice — so it is decided by
		 * the date having passed rather than by an event, and the entry that
		 * says so is written the next time anybody looks.
		 */
		$this->assertSame(
			MeetingHours::RELEASED,
			MeetingHours::state_of( $this->meeting( array( 'on' => '2026-02-23' ) ), self::TODAY, '' )
		);
	}

	public function test_a_meeting_today_still_holds_its_hours(): void {
		// It has not been and gone until the day has. Releasing at midnight
		// would free the hours of a meeting happening that afternoon.
		$this->assertSame(
			MeetingHours::RESERVED,
			MeetingHours::state_of( $this->meeting( array( 'on' => self::TODAY ) ), self::TODAY, '' )
		);
	}

	/* ------------------------------------------------- and what that writes */

	public function test_a_scheduled_meeting_reserves_its_hours_once(): void {
		$plan = MeetingHours::plan( $this->meeting(), array(), self::TODAY, '' );

		$this->assertSame( array( array( Entries::MEETING_RESERVATION, 2.0 ) ), $this->pairs( $plan ) );

		// And asking again, with that entry on the record, writes nothing.
		$this->assertSame(
			array(),
			MeetingHours::plan( $this->meeting(), array( $this->entry( Entries::MEETING_RESERVATION, 2 ) ), self::TODAY, '' )
		);
	}

	public function test_marking_a_meeting_held_releases_the_reservation_as_it_books_the_usage(): void {
		// Both, and in that order. Booking the usage alone charges the client
		// twice for one meeting.
		$plan = MeetingHours::plan(
			$this->meeting( array( 'status' => Occurrence::HELD ) ),
			array( $this->entry( Entries::MEETING_RESERVATION, 2 ) ),
			self::TODAY,
			''
		);

		$this->assertSame(
			array(
				array( Entries::MEETING_RELEASE, 2.0 ),
				array( Entries::MEETING_USAGE, 2.0 ),
			),
			$this->pairs( $plan )
		);
	}

	public function test_a_cancelled_meeting_gives_its_hours_back(): void {
		$plan = MeetingHours::plan(
			$this->meeting( array( 'status' => Occurrence::CANCELLED ) ),
			array( $this->entry( Entries::MEETING_RESERVATION, 2 ) ),
			self::TODAY,
			''
		);

		$this->assertSame( array( array( Entries::MEETING_RELEASE, 2.0 ) ), $this->pairs( $plan ) );
	}

	public function test_a_meeting_moved_beyond_the_horizon_gives_its_hours_back(): void {
		// MEET-4's transfer on reschedule, which falls out of deciding from
		// position: the meeting is now too far away to hold anything.
		$plan = MeetingHours::plan(
			$this->meeting( array( 'on' => '2026-09-01' ) ),
			array( $this->entry( Entries::MEETING_RESERVATION, 2 ) ),
			self::TODAY,
			''
		);

		$this->assertSame( array( array( Entries::MEETING_RELEASE, 2.0 ) ), $this->pairs( $plan ) );
	}

	public function test_a_meeting_moved_back_inside_the_horizon_takes_its_hours_again(): void {
		$plan = MeetingHours::plan(
			$this->meeting(),
			array(
				$this->entry( Entries::MEETING_RESERVATION, 2 ),
				$this->entry( Entries::MEETING_RELEASE, 2 ),
			),
			self::TODAY,
			''
		);

		$this->assertSame( array( array( Entries::MEETING_RESERVATION, 2.0 ) ), $this->pairs( $plan ) );
	}

	public function test_a_meeting_whose_length_changed_adjusts_by_the_difference(): void {
		// COMM-3: nothing written is ever changed, so a longer meeting is
		// another entry for the gap rather than a rewrite of the first.
		$plan = MeetingHours::plan(
			$this->meeting( array( 'planned_hours' => 3.0 ) ),
			array( $this->entry( Entries::MEETING_RESERVATION, 2 ) ),
			self::TODAY,
			''
		);

		$this->assertSame( array( array( Entries::MEETING_RESERVATION, 1.0 ) ), $this->pairs( $plan ) );
	}

	public function test_hours_spent_on_a_meeting_that_happened_are_never_given_back(): void {
		/*
		 * Cancelling a meeting after marking it held does not un-hold it. The
		 * time was spent; writing it off is somebody's decision with a reason
		 * on it, and that is an adjustment rather than arithmetic.
		 */
		$plan = MeetingHours::plan(
			$this->meeting( array( 'status' => Occurrence::CANCELLED ) ),
			array(
				$this->entry( Entries::MEETING_RESERVATION, 2 ),
				$this->entry( Entries::MEETING_RELEASE, 2 ),
				$this->entry( Entries::MEETING_USAGE, 2 ),
			),
			self::TODAY,
			''
		);

		$this->assertSame( array(), $this->pairs( $plan ) );
	}

	public function test_a_meeting_beyond_the_horizon_writes_nothing_at_all(): void {
		// A forecast is a thing to show, not a thing to record. Writing a
		// nought-hour entry for every meeting a year out fills the record
		// somebody reads to settle a bill.
		$this->assertSame( array(), MeetingHours::plan( $this->meeting( array( 'on' => '2026-09-01' ) ), array(), self::TODAY, '' ) );
	}

	public function test_a_meeting_planned_at_no_hours_writes_nothing(): void {
		$this->assertSame( array(), MeetingHours::plan( $this->meeting( array( 'planned_hours' => 0 ) ), array(), self::TODAY, '' ) );
	}

	/* ------------------------------------------------- the acceptance itself */

	public function test_only_a_held_meeting_ever_costs_the_client_anything(): void {
		/*
		 * #154's criterion, walked as four whole lives rather than asserted
		 * once. Each meeting is reserved first, because that is what really
		 * happens, and then finishes in one of the four ways — and only one of
		 * them leaves the client's balance lower than it started.
		 */
		$endings = array(
			'held'      => array( 'status' => Occurrence::HELD ),
			'cancelled' => array( 'status' => Occurrence::CANCELLED ),
			'no show'   => array( 'status' => Occurrence::NO_SHOW ),
			'ignored'   => array( 'on' => '2026-02-23' ),
		);

		foreach ( $endings as $name => $ending ) {
			$entries = array();
			$balance = 0.0;

			// Reserved while it was still coming up.
			foreach ( MeetingHours::plan( $this->meeting(), $entries, self::TODAY, '' ) as $entry ) {
				$entries[] = $this->entry( (string) $entry['event_type'], (float) $entry['hours'] );
			}

			// And then whatever became of it.
			foreach ( MeetingHours::plan( $this->meeting( $ending ), $entries, self::TODAY, '' ) as $entry ) {
				$entries[] = $this->entry( (string) $entry['event_type'], (float) $entry['hours'] );
			}

			foreach ( $entries as $entry ) {
				$balance += (float) $entry['hours'];
			}

			$expected = 'held' === $name ? -2.0 : 0.0;

			$this->assertSame( $expected, round( $balance, 2 ), $name . ' left the balance wrong' );
		}
	}

	public function test_there_is_no_such_thing_as_a_late_cancellation_charge(): void {
		// MEET-5, asserted as an absence. A meeting cancelled the morning of
		// costs exactly what one cancelled a month before costs: nothing.
		$plan = MeetingHours::plan(
			$this->meeting( array( 'on' => self::TODAY, 'status' => Occurrence::CANCELLED ) ),
			array( $this->entry( Entries::MEETING_RESERVATION, 2 ) ),
			self::TODAY,
			''
		);

		$this->assertSame( array( array( Entries::MEETING_RELEASE, 2.0 ) ), $this->pairs( $plan ) );
	}
}
