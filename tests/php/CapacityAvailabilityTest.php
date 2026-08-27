<?php
/**
 * What somebody's time is, before anything is committed against it.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Capacity\Availability;
use Blueworx\Forge\Capacity\Patterns;
use Blueworx\Forge\Capacity\Unavailability;
use PHPUnit\Framework\TestCase;

/**
 * CAP-1's base availability (#136).
 *
 * Every figure the rest of M7 produces starts here, so the cases below are the
 * ones that would be wrong quietly: a part-time week counted as full, leave
 * that stops a day early, an hours change that rewrites last month, and a
 * person nobody has set up reading as a person with no time.
 */
final class CapacityAvailabilityTest extends TestCase {

	/**
	 * A weekly pattern from a date, with the same hours every weekday.
	 *
	 * @param string $from  YYYY-MM-DD.
	 * @param float  $daily Hours each weekday.
	 * @return array<string, mixed>
	 */
	private function pattern( string $from, float $daily ): array {
		return array(
			'effective_from' => $from,
			'hours_sun'      => 0.0,
			'hours_mon'      => $daily,
			'hours_tue'      => $daily,
			'hours_wed'      => $daily,
			'hours_thu'      => $daily,
			'hours_fri'      => $daily,
			'hours_sat'      => 0.0,
		);
	}

	/**
	 * One unavailability record.
	 *
	 * @param string $starts_on YYYY-MM-DD.
	 * @param string $ends_on   YYYY-MM-DD.
	 * @param string $kind      Kind.
	 * @return array<string, mixed>
	 */
	private function away( string $starts_on, string $ends_on, string $kind = 'leave' ): array {
		return array(
			'starts_on' => $starts_on,
			'ends_on'   => $ends_on,
			'kind'      => $kind,
		);
	}

	/**
	 * Total hours for a period.
	 *
	 * @param array<int, array<string, mixed>> $patterns Patterns.
	 * @param array<int, array<string, mixed>> $away     Unavailability.
	 * @param string                           $from     YYYY-MM-DD.
	 * @param string                           $to       YYYY-MM-DD.
	 * @return float
	 */
	private function total( array $patterns, array $away, string $from, string $to ): float {
		return round( array_sum( array_column( Availability::calculate( $patterns, $away, $from, $to ), 'hours' ) ), 2 );
	}

	/**
	 * A full week is five days, not seven.
	 *
	 * 2026-03-02 is a Monday, so this is Monday to Sunday.
	 */
	public function test_a_full_time_week_counts_only_working_days(): void {
		$patterns = array( $this->pattern( '2026-01-01', 7.5 ) );

		$this->assertSame( 37.5, $this->total( $patterns, array(), '2026-03-02', '2026-03-08' ) );
	}

	/**
	 * A part-time pattern is not a full one scaled down afterwards.
	 */
	public function test_a_part_time_week_is_counted_as_entered(): void {
		$patterns = array(
			array(
				'effective_from' => '2026-01-01',
				'hours_sun'      => 0.0,
				'hours_mon'      => 7.5,
				'hours_tue'      => 7.5,
				'hours_wed'      => 4.0,
				'hours_thu'      => 0.0,
				'hours_fri'      => 0.0,
				'hours_sat'      => 0.0,
			),
		);

		$this->assertSame( 19.0, $this->total( $patterns, array(), '2026-03-02', '2026-03-08' ) );
	}

	/**
	 * Leave takes whole days out, both ends included.
	 *
	 * The end date is the case that would be wrong quietly: an exclusive end
	 * puts somebody back at work on a day they are away, and the total is only
	 * one day out.
	 */
	public function test_leave_includes_the_day_it_ends_on(): void {
		$patterns = array( $this->pattern( '2026-01-01', 7.5 ) );

		// Wednesday to Friday: three days off, so two working days left.
		$away = array( $this->away( '2026-03-04', '2026-03-06' ) );

		$this->assertSame( 15.0, $this->total( $patterns, $away, '2026-03-02', '2026-03-08' ) );
	}

	/**
	 * Two records covering one day take it out once.
	 */
	public function test_overlapping_leave_does_not_take_the_same_day_out_twice(): void {
		$patterns = array( $this->pattern( '2026-01-01', 7.5 ) );

		$away = array(
			$this->away( '2026-03-04', '2026-03-05' ),
			$this->away( '2026-03-05', '2026-03-06', 'public-holiday' ),
		);

		// Wednesday, Thursday and Friday gone once each.
		$this->assertSame( 15.0, $this->total( $patterns, $away, '2026-03-02', '2026-03-08' ) );
	}

	/**
	 * Leave that starts before the period still takes days out of it.
	 */
	public function test_leave_starting_before_the_period_is_still_counted(): void {
		$patterns = array( $this->pattern( '2026-01-01', 7.5 ) );

		$away = array( $this->away( '2026-02-25', '2026-03-03' ) );

		// Monday and Tuesday gone; Wednesday to Friday left.
		$this->assertSame( 22.5, $this->total( $patterns, $away, '2026-03-02', '2026-03-08' ) );
	}

	/**
	 * The heart of effective dating: changing somebody's hours must not change
	 * what last month was.
	 */
	public function test_an_hours_change_does_not_rewrite_earlier_periods(): void {
		$patterns = array(
			$this->pattern( '2026-01-01', 7.5 ),
			$this->pattern( '2026-03-05', 4.0 ),
		);

		// February, untouched by the March change.
		$this->assertSame( 37.5, $this->total( $patterns, array(), '2026-02-02', '2026-02-08' ) );

		// The week the change lands in: Monday to Wednesday at 7.5, Thursday
		// and Friday at 4.
		$this->assertSame( 30.5, $this->total( $patterns, array(), '2026-03-02', '2026-03-08' ) );
	}

	/**
	 * Patterns are not required to arrive in order.
	 */
	public function test_the_pattern_in_force_is_the_latest_one_on_or_before_the_date(): void {
		$history = array(
			$this->pattern( '2026-03-05', 4.0 ),
			$this->pattern( '2026-01-01', 7.5 ),
			$this->pattern( '2026-06-01', 6.0 ),
		);

		$this->assertSame( '2026-01-01', Patterns::pick( $history, '2026-02-14' )['effective_from'] );
		$this->assertSame( '2026-03-05', Patterns::pick( $history, '2026-03-05' )['effective_from'] );
		$this->assertSame( '2026-06-01', Patterns::pick( $history, '2027-01-01' )['effective_from'] );
	}

	/**
	 * Correcting hours for a date already spoken about.
	 *
	 * The table is append-only, so a correction is another row with the same
	 * effective date. If the later one did not win, a mistake would be
	 * uncorrectable — and if it were not recorded as a second row, the earlier
	 * belief would vanish. Both halves matter, and this is what makes the first
	 * one true.
	 */
	public function test_the_later_statement_about_a_date_wins(): void {
		$wrong = $this->pattern( '2026-03-05', 7.5 );
		$right = $this->pattern( '2026-03-05', 4.0 );

		$wrong['created_at'] = 1000;
		$right['created_at'] = 2000;

		$this->assertSame( 4.0, Patterns::pick( array( $wrong, $right ), '2026-03-06' )['hours_mon'] );

		// And the order they are handed over in must not change the answer.
		$this->assertSame( 4.0, Patterns::pick( array( $right, $wrong ), '2026-03-06' )['hours_mon'] );
	}

	/**
	 * Before anybody has said anything, there is no pattern — not a pattern of
	 * zero.
	 */
	public function test_a_date_before_the_first_pattern_has_none(): void {
		$this->assertNull( Patterns::pick( array( $this->pattern( '2026-03-05', 7.5 ) ), '2026-01-01' ) );
	}

	/**
	 * Nobody set up, and a normal day off, are different facts. A total shows
	 * both as zero, so the breakdown has to tell them apart or the capacity
	 * view cannot say which one to act on.
	 */
	public function test_zero_hours_says_which_kind_of_zero_it_is(): void {
		$patterns = array( $this->pattern( '2026-01-01', 7.5 ) );
		$away     = array( $this->away( '2026-03-04', '2026-03-04' ) );

		$days = Availability::calculate( $patterns, $away, '2026-03-04', '2026-03-08' );
		$by   = array_column( $days, 'reason', 'date' );

		$this->assertSame( 'leave', $by['2026-03-04'] );
		$this->assertSame( '', $by['2026-03-05'] );
		$this->assertSame( 'non-working-day', $by['2026-03-07'] );

		$unset = Availability::calculate( array(), array(), '2026-03-04', '2026-03-04' );
		$this->assertSame( 'no-pattern', $unset[0]['reason'] );
	}

	/**
	 * A day taken out by leave still says what it would have been, so the view
	 * can show what the leave cost rather than just a blank.
	 */
	public function test_a_day_off_still_reports_the_hours_it_would_have_been(): void {
		$patterns = array( $this->pattern( '2026-01-01', 7.5 ) );
		$away     = array( $this->away( '2026-03-04', '2026-03-04' ) );

		$day = Availability::calculate( $patterns, $away, '2026-03-04', '2026-03-04' )[0];

		$this->assertSame( 0.0, $day['hours'] );
		$this->assertSame( 7.5, $day['base_hours'] );
	}

	/**
	 * A single day is a period.
	 */
	public function test_one_day_is_a_valid_period(): void {
		$patterns = array( $this->pattern( '2026-01-01', 7.5 ) );

		$this->assertCount( 1, Availability::calculate( $patterns, array(), '2026-03-02', '2026-03-02' ) );
		$this->assertSame( 7.5, $this->total( $patterns, array(), '2026-03-02', '2026-03-02' ) );
	}

	/**
	 * A backwards period is a mistake, and answering it with a number would
	 * hide the mistake behind a plausible zero.
	 */
	public function test_a_period_that_ends_before_it_starts_is_empty(): void {
		$patterns = array( $this->pattern( '2026-01-01', 7.5 ) );

		$this->assertSame( array(), Availability::calculate( $patterns, array(), '2026-03-08', '2026-03-02' ) );
	}

	/**
	 * A mistyped year must not walk day by day through two centuries.
	 */
	public function test_an_absurd_period_stops_rather_than_running_away(): void {
		$patterns = array( $this->pattern( '2026-01-01', 7.5 ) );

		$days = Availability::calculate( $patterns, array(), '2026-01-01', '2206-01-01' );

		$this->assertCount( Availability::MAX_DAYS, $days );
	}

	/**
	 * The expansion is clipped to the period asked about, not to the record.
	 */
	public function test_leave_running_past_the_period_is_clipped_to_it(): void {
		$days = Unavailability::expand(
			array( $this->away( '2026-02-01', '2026-12-31' ) ),
			'2026-03-02',
			'2026-03-04'
		);

		$this->assertSame( array( '2026-03-02', '2026-03-03', '2026-03-04' ), array_keys( $days ) );
	}
}
