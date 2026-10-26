<?php
/**
 * What a Google Calendar event becomes in Forge.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Google\Events;
use PHPUnit\Framework\TestCase;

/**
 * #606. One event from one person's calendar, in Forge's words: which day,
 * how long, whether it counts against their time, and the key that lets the
 * same invitation on two calendars be one meeting.
 */
final class GoogleEventsTest extends TestCase {

	/**
	 * A timed event, as Google sends it.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 * @return array<string, mixed>
	 */
	private function timed( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'        => 'evt1',
				'iCalUID'   => 'evt1@google.com',
				'status'    => 'confirmed',
				'summary'   => 'Kick-off',
				'start'     => array( 'dateTime' => '2026-10-26T10:00:00+00:00' ),
				'end'       => array( 'dateTime' => '2026-10-26T11:10:00+00:00' ),
				'attendees' => array(
					array(
						'email'          => 'me@studio.test',
						'self'           => true,
						'responseStatus' => 'accepted',
					),
					array(
						'email'       => 'client@example.test',
						'displayName' => 'A Client',
					),
				),
			),
			$overrides
		);
	}

	private function zone(): DateTimeZone {
		return new DateTimeZone( 'Europe/London' );
	}

	public function test_a_timed_event_counts_and_is_its_length_rounded_up_to_the_half_hour(): void {
		$event = Events::normalise( $this->timed(), $this->zone() );

		$this->assertSame( 'evt1', $event['event_id'] );
		$this->assertSame( 'Kick-off', $event['title'] );
		$this->assertSame( '2026-10-26', $event['on'] );
		$this->assertSame( '10:00', $event['at'] );
		$this->assertSame( 1.5, $event['planned_hours'] );
		$this->assertTrue( $event['counts'] );
		$this->assertFalse( $event['all_day'] );
		$this->assertFalse( $event['cancelled'] );
		$this->assertSame( 'accepted', $event['response'] );
		$this->assertSame( strtotime( '2026-10-26T10:00:00Z' ), $event['starts_at'] );
	}

	public function test_the_day_and_time_are_the_studios_local_ones(): void {
		$event = Events::normalise(
			$this->timed(
				array(
					'start' => array( 'dateTime' => '2026-07-01T23:30:00Z' ),
					'end'   => array( 'dateTime' => '2026-07-02T00:30:00Z' ),
				)
			),
			$this->zone()
		);

		$this->assertSame( '2026-07-02', $event['on'] );
		$this->assertSame( '00:30', $event['at'] );
	}

	public function test_outside_attendees_are_kept_without_the_person_themself(): void {
		$event = Events::normalise( $this->timed(), $this->zone() );

		$this->assertSame(
			array(
				array(
					'email' => 'client@example.test',
					'name'  => 'A Client',
				),
			),
			$event['attendees']
		);
	}

	public function test_a_declined_event_shows_but_does_not_count(): void {
		$item                                   = $this->timed();
		$item['attendees'][0]['responseStatus'] = 'declined';

		$event = Events::normalise( $item, $this->zone() );

		$this->assertSame( 'declined', $event['response'] );
		$this->assertFalse( $event['counts'] );
	}

	public function test_an_all_day_event_spans_its_days_and_counts_nothing(): void {
		$event = Events::normalise(
			$this->timed(
				array(
					'start' => array( 'date' => '2026-10-26' ),
					'end'   => array( 'date' => '2026-10-29' ),
				)
			),
			$this->zone()
		);

		$this->assertTrue( $event['all_day'] );
		$this->assertSame( '2026-10-26', $event['on'] );
		$this->assertSame( '2026-10-28', $event['ends_on'] );
		$this->assertSame( '', $event['at'] );
		$this->assertSame( 0.0, $event['planned_hours'] );
		$this->assertFalse( $event['counts'] );
	}

	public function test_a_one_day_all_day_event_has_no_end_day(): void {
		$event = Events::normalise(
			$this->timed(
				array(
					'start' => array( 'date' => '2026-10-26' ),
					'end'   => array( 'date' => '2026-10-27' ),
				)
			),
			$this->zone()
		);

		$this->assertSame( '', $event['ends_on'] );
	}

	public function test_out_of_office_free_and_focus_time_do_not_count(): void {
		$this->assertFalse( Events::normalise( $this->timed( array( 'eventType' => 'outOfOffice' ) ), $this->zone() )['counts'] );
		$this->assertFalse( Events::normalise( $this->timed( array( 'eventType' => 'focusTime' ) ), $this->zone() )['counts'] );
		$this->assertFalse( Events::normalise( $this->timed( array( 'eventType' => 'workingLocation' ) ), $this->zone() )['counts'] );
		$this->assertFalse( Events::normalise( $this->timed( array( 'transparency' => 'transparent' ) ), $this->zone() )['counts'] );
	}

	public function test_a_private_or_confidential_event_is_private(): void {
		$this->assertTrue( Events::normalise( $this->timed( array( 'visibility' => 'private' ) ), $this->zone() )['private'] );
		$this->assertTrue( Events::normalise( $this->timed( array( 'visibility' => 'confidential' ) ), $this->zone() )['private'] );
		$this->assertFalse( Events::normalise( $this->timed( array( 'visibility' => 'default' ) ), $this->zone() )['private'] );
	}

	public function test_a_cancelled_event_says_so_even_with_nothing_else_on_it(): void {
		$event = Events::normalise(
			array(
				'id'     => 'evt1',
				'status' => 'cancelled',
			),
			$this->zone()
		);

		$this->assertTrue( $event['cancelled'] );
		$this->assertSame( 'evt1', $event['event_id'] );
	}

	public function test_an_event_with_no_id_is_nothing(): void {
		$this->assertNull( Events::normalise( array( 'summary' => 'No id' ), $this->zone() ) );
	}

	public function test_an_untitled_event_is_named(): void {
		$item = $this->timed();
		unset( $item['summary'] );

		$this->assertSame( '(No title)', Events::normalise( $item, $this->zone() )['title'] );
	}

	public function test_an_event_with_no_attendees_is_the_organisers_own_and_accepted(): void {
		$item = $this->timed();
		unset( $item['attendees'] );

		$event = Events::normalise( $item, $this->zone() );

		$this->assertSame( 'accepted', $event['response'] );
		$this->assertTrue( $event['counts'] );
	}

	public function test_the_same_invitation_on_two_calendars_shares_a_key(): void {
		$mine   = Events::normalise( $this->timed(), $this->zone() );
		$theirs = Events::normalise( $this->timed( array( 'id' => 'other-copy-id' ) ), $this->zone() );

		$this->assertSame( $mine['share_key'], $theirs['share_key'] );
		$this->assertSame( 32, strlen( $mine['share_key'] ) );
	}

	public function test_each_instance_of_a_recurring_event_is_its_own_meeting(): void {
		$first  = Events::normalise(
			$this->timed(
				array(
					'id'                => 'rec_20261026T100000Z',
					'iCalUID'           => 'rec@google.com',
					'recurringEventId'  => 'rec',
					'originalStartTime' => array( 'dateTime' => '2026-10-26T10:00:00Z' ),
				)
			),
			$this->zone()
		);
		$second = Events::normalise(
			$this->timed(
				array(
					'id'                => 'rec_20261102T100000Z',
					'iCalUID'           => 'rec@google.com',
					'recurringEventId'  => 'rec',
					'originalStartTime' => array( 'dateTime' => '2026-11-02T10:00:00Z' ),
				)
			),
			$this->zone()
		);

		$this->assertNotSame( $first['share_key'], $second['share_key'] );
	}

	public function test_a_long_description_is_cut_and_its_markup_removed(): void {
		$event = Events::normalise( $this->timed( array( 'description' => '<b>Agenda</b> ' . str_repeat( 'x', 5000 ) ) ), $this->zone() );

		$this->assertStringStartsWith( 'Agenda ', $event['description'] );
		$this->assertLessThanOrEqual( Events::MAX_DESCRIPTION, mb_strlen( $event['description'] ) );
	}

	public function test_occurrences_of_one_series_share_a_series_key(): void {
		$first  = Events::normalise(
			$this->timed(
				array(
					'id'                => 'evt1_20261026T100000Z',
					'recurringEventId'  => 'evt1',
					'originalStartTime' => array( 'dateTime' => '2026-10-26T10:00:00+00:00' ),
				)
			),
			$this->zone()
		);
		$second = Events::normalise(
			$this->timed(
				array(
					'id'                => 'evt1_20261102T100000Z',
					'recurringEventId'  => 'evt1',
					'originalStartTime' => array( 'dateTime' => '2026-11-02T10:00:00+00:00' ),
				)
			),
			$this->zone()
		);

		$this->assertNotSame( '', $first['series_key'] );
		$this->assertSame( $first['series_key'], $second['series_key'] );
		$this->assertNotSame( $first['share_key'], $second['share_key'] );
	}

	public function test_a_one_off_has_no_series_key(): void {
		$this->assertSame( '', Events::normalise( $this->timed(), $this->zone() )['series_key'] );
	}
}
