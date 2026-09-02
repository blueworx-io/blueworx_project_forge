<?php
/**
 * The standing meetings a client's package includes, as dates.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Meetings\Recurrence;
use PHPUnit\Framework\TestCase;

/**
 * #152, MEET-2 and MEET-3. Turning a recurrence rule into occurrences.
 *
 * **The whole class exists to get one thing right: a meeting is at a time of
 * day, in the client's timezone, not at an instant.** A ten o'clock call is at
 * ten o'clock in March and at ten o'clock in November, and between those two
 * the clocks move — so the instant it happens at shifts by an hour and the
 * local time does not. Generating from a fixed instant is the obvious
 * implementation and it silently moves every client's meeting by an hour twice
 * a year, in opposite directions, in a way nobody notices until somebody misses
 * a call.
 *
 * That is why the acceptance criterion for this issue is a daylight-saving
 * boundary rather than a count of dates.
 *
 * Four patterns and no more (MEET-2). Full RRULE support is a large surface for
 * patterns nobody has asked for, and every one of them would need expressing in
 * a screen somebody has to understand.
 */
final class MeetingRecurrenceTest extends TestCase {

	/**
	 * A rule, with only what the expansion reads.
	 *
	 * @param array<string, mixed> $overrides Anything to say differently.
	 * @return array<string, mixed>
	 */
	private function rule( array $overrides = array() ): array {
		return array_merge(
			array(
				'frequency'     => Recurrence::WEEKLY,
				'starts_on'     => '2026-01-05',
				'ends_on'       => '',
				'time_of_day'   => '10:00',
				'duration_mins' => 60,
				'timezone'      => 'Europe/London',
			),
			$overrides
		);
	}

	/**
	 * The local dates an expansion produced.
	 *
	 * @param array<int, array<string, mixed>> $occurrences What expand() returned.
	 * @return array<int, string>
	 */
	private function dates( array $occurrences ): array {
		return array_column( $occurrences, 'on' );
	}

	/* ------------------------------------------------------- the four patterns */

	public function test_weekly_lands_on_the_same_weekday_every_week(): void {
		$dates = $this->dates( Recurrence::expand( $this->rule(), '2026-01-01', '2026-02-01' ) );

		$this->assertSame(
			array( '2026-01-05', '2026-01-12', '2026-01-19', '2026-01-26' ),
			$dates
		);
	}

	public function test_fortnightly_skips_every_other_week(): void {
		$dates = $this->dates(
			Recurrence::expand( $this->rule( array( 'frequency' => Recurrence::FORTNIGHTLY ) ), '2026-01-01', '2026-03-01' )
		);

		$this->assertSame(
			array( '2026-01-05', '2026-01-19', '2026-02-02', '2026-02-16' ),
			$dates
		);
	}

	public function test_four_weekly_is_not_the_same_as_monthly(): void {
		/*
		 * The distinction MEET-2 draws on purpose. Thirteen four-weekly meetings
		 * a year against twelve monthly ones is one extra meeting a client is
		 * billed for, so the two cannot quietly become each other.
		 */
		$dates = $this->dates(
			Recurrence::expand( $this->rule( array( 'frequency' => Recurrence::FOUR_WEEKLY ) ), '2026-01-01', '2026-04-01' )
		);

		$this->assertSame(
			array( '2026-01-05', '2026-02-02', '2026-03-02', '2026-03-30' ),
			$dates
		);
	}

	public function test_monthly_keeps_the_day_of_the_month(): void {
		$dates = $this->dates(
			Recurrence::expand(
				$this->rule(
					array(
						'frequency' => Recurrence::MONTHLY,
						'starts_on' => '2026-01-15',
					)
				),
				'2026-01-01',
				'2026-05-01'
			)
		);

		$this->assertSame( array( '2026-01-15', '2026-02-15', '2026-03-15', '2026-04-15' ), $dates );
	}

	public function test_a_monthly_meeting_on_a_day_a_month_has_not_got_is_skipped(): void {
		/*
		 * The thirty-first of February is not a date, and the two ways of
		 * pretending otherwise are both worse than skipping. Clamping to the
		 * twenty-eighth books a client meeting on a day nobody agreed; rolling
		 * into March puts two meetings in one month. Skipping is the only
		 * answer that invents nothing, and the screen can say so.
		 */
		$dates = $this->dates(
			Recurrence::expand(
				$this->rule(
					array(
						'frequency' => Recurrence::MONTHLY,
						'starts_on' => '2026-01-31',
					)
				),
				'2026-01-01',
				'2026-05-01'
			)
		);

		$this->assertSame( array( '2026-01-31', '2026-03-31' ), $dates );
	}

	/* ---------------------------------------- the reason this class is careful */

	public function test_a_meeting_keeps_its_local_time_across_a_spring_clock_change(): void {
		/*
		 * #152's acceptance criterion. The clocks go forward in the UK on 29
		 * March 2026. A ten o'clock meeting is at ten o'clock on both sides of
		 * it — which means the instant moves from 10:00 UTC to 09:00 UTC, and a
		 * generator working in fixed instants would hold the instant and move
		 * the meeting to eleven.
		 */
		$occurrences = Recurrence::expand(
			$this->rule( array( 'starts_on' => '2026-03-23' ) ),
			'2026-03-01',
			'2026-04-15'
		);

		$by_date = array_column( $occurrences, null, 'on' );

		$this->assertSame( '10:00', $by_date['2026-03-23']['at'] );
		$this->assertSame( '10:00', $by_date['2026-04-06']['at'] );

		// And the instants differ by exactly the hour the clocks moved.
		$before = (int) $by_date['2026-03-23']['starts_at'];
		$after  = (int) $by_date['2026-04-06']['starts_at'];

		$this->assertSame( ( 14 * 86400 ) - 3600, $after - $before );
	}

	public function test_a_meeting_keeps_its_local_time_across_an_autumn_clock_change(): void {
		// And back the other way, because a generator that only ever adds an
		// hour is right twice a year and wrong the other two times.
		$occurrences = Recurrence::expand(
			$this->rule( array( 'starts_on' => '2026-10-19' ) ),
			'2026-10-01',
			'2026-11-15'
		);

		$by_date = array_column( $occurrences, null, 'on' );

		$this->assertSame( '10:00', $by_date['2026-11-02']['at'] );
		$this->assertSame( ( 14 * 86400 ) + 3600, (int) $by_date['2026-11-02']['starts_at'] - (int) $by_date['2026-10-19']['starts_at'] );
	}

	public function test_the_client_s_timezone_is_the_one_that_counts(): void {
		// Not the studio's, and not the server's. A client in Sydney has a ten
		// o'clock meeting at ten o'clock in Sydney.
		$sydney = Recurrence::expand(
			$this->rule( array( 'timezone' => 'Australia/Sydney' ) ),
			'2026-01-01',
			'2026-01-12'
		);

		$london = Recurrence::expand( $this->rule(), '2026-01-01', '2026-01-12' );

		$this->assertSame( '10:00', $sydney[0]['at'] );
		$this->assertSame( '10:00', $london[0]['at'] );
		$this->assertNotSame( $sydney[0]['starts_at'], $london[0]['starts_at'] );
	}

	/* --------------------------------------------------------- the boundaries */

	public function test_nothing_is_generated_before_the_series_starts(): void {
		// A window opening two months before the arrangement did. The series
		// begins when it begins, however far back somebody asks.
		$dates = $this->dates( Recurrence::expand( $this->rule(), '2025-11-01', '2026-01-12' ) );

		$this->assertSame( array( '2026-01-05', '2026-01-12' ), $dates );
	}

	public function test_nothing_is_generated_after_the_series_ends(): void {
		$dates = $this->dates(
			Recurrence::expand( $this->rule( array( 'ends_on' => '2026-01-19' ) ), '2026-01-01', '2026-03-01' )
		);

		$this->assertSame( array( '2026-01-05', '2026-01-12', '2026-01-19' ), $dates );
	}

	public function test_the_end_date_is_a_day_the_meeting_still_happens_on(): void {
		// Inclusive, because somebody setting "until the nineteenth" means the
		// nineteenth is the last one rather than the first one that is not.
		$dates = $this->dates(
			Recurrence::expand( $this->rule( array( 'ends_on' => '2026-01-12' ) ), '2026-01-01', '2026-03-01' )
		);

		$this->assertContains( '2026-01-12', $dates );
	}

	public function test_a_window_asking_for_nothing_gets_nothing(): void {
		$this->assertSame( array(), Recurrence::expand( $this->rule(), '2026-02-01', '2026-01-01' ) );
	}

	public function test_an_unknown_pattern_generates_nothing_rather_than_guessing(): void {
		/*
		 * Silence rather than a fallback to weekly. A rule nobody can read is a
		 * rule nobody agreed to, and booking a client in every week because the
		 * frequency column held a typo is worse than booking them nowhere —
		 * the second is noticed the same day.
		 */
		$this->assertSame( array(), Recurrence::expand( $this->rule( array( 'frequency' => 'daily' ) ), '2026-01-01', '2026-03-01' ) );
	}

	public function test_an_unknown_timezone_generates_nothing(): void {
		$this->assertSame( array(), Recurrence::expand( $this->rule( array( 'timezone' => 'Middle/Earth' ) ), '2026-01-01', '2026-03-01' ) );
	}

	/* ------------------------------------------------ what an occurrence costs */

	public function test_planned_hours_round_the_meeting_up_to_the_next_half_hour(): void {
		// MEET-3. An hour and ten minutes of somebody's afternoon is not an
		// hour and a sixth on an invoice.
		$this->assertSame( 1.5, Recurrence::planned_hours( 70 ) );
		$this->assertSame( 1.0, Recurrence::planned_hours( 60 ) );
		$this->assertSame( 0.5, Recurrence::planned_hours( 25 ) );
		$this->assertSame( 2.0, Recurrence::planned_hours( 105 ) );
	}

	public function test_a_meeting_with_no_length_plans_no_hours(): void {
		$this->assertSame( 0.0, Recurrence::planned_hours( 0 ) );
		$this->assertSame( 0.0, Recurrence::planned_hours( -30 ) );
	}

	public function test_every_occurrence_carries_the_hours_its_length_implies(): void {
		$occurrences = Recurrence::expand( $this->rule( array( 'duration_mins' => 90 ) ), '2026-01-01', '2026-01-12' );

		$this->assertSame( 1.5, $occurrences[0]['planned_hours'] );
	}
}
