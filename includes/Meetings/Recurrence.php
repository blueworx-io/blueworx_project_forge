<?php
/**
 * A recurrence rule, as the dates it means.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Meetings;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * #152, MEET-2 and MEET-3. Turning a standing arrangement into dates.
 *
 * Pure: handed a rule and a window, it says which days the meeting falls on and
 * what each one costs. Nothing here reads or writes anything, which is what
 * lets the awkward half — the clocks — be argued with in a test rather than
 * against a database.
 *
 * **A meeting is at a time of day, not at an instant.** That sentence is the
 * whole design. A ten o'clock call is at ten o'clock in March and at ten
 * o'clock in November; the clocks move between them, so the *instant* shifts by
 * an hour and the local time does not. Generating by adding seven days to a
 * timestamp is the obvious implementation, and it moves every client's meeting
 * by an hour twice a year, in opposite directions — a bug nobody spots until
 * somebody misses a call, and which then looks like a mystery rather than a
 * clock change.
 *
 * So the walk is done in the client's own timezone, a calendar day at a time,
 * and the instant is worked out from the local date and time at the end. The
 * timezone that counts is the client's: a client in Sydney has their meeting at
 * ten o'clock in Sydney, whatever the studio's clock says.
 *
 * **Four patterns and no more** (MEET-2). Full recurrence-rule support is a
 * large surface for patterns nobody has asked for, and every one of them would
 * need expressing in a screen somebody has to understand.
 */
final class Recurrence {

	/**
	 * Every week, on the same weekday.
	 */
	public const WEEKLY = 'weekly';

	/**
	 * Every second week.
	 */
	public const FORTNIGHTLY = 'fortnightly';

	/**
	 * Every four weeks — thirteen a year, not twelve.
	 */
	public const FOUR_WEEKLY = 'four-weekly';

	/**
	 * The same date each month.
	 */
	public const MONTHLY = 'monthly';

	/**
	 * Every pattern a series may use.
	 *
	 * @var array<int, string>
	 */
	public const FREQUENCIES = array(
		self::WEEKLY,
		self::FORTNIGHTLY,
		self::FOUR_WEEKLY,
		self::MONTHLY,
	);

	/**
	 * How many weeks each weekly-family pattern skips.
	 *
	 * @var array<string, int>
	 */
	private const WEEKS = array(
		self::WEEKLY      => 1,
		self::FORTNIGHTLY => 2,
		self::FOUR_WEEKLY => 4,
	);

	/**
	 * The most occurrences one expansion will produce.
	 *
	 * A guard rather than a rule: a window nobody meant — a typo in an end date
	 * putting it in 2226 — should come back short rather than spend a minute
	 * building a hundred thousand dates.
	 */
	public const MOST = 500;

	/**
	 * The occurrences a rule produces inside a window.
	 *
	 * @param array<string, mixed> $rule  frequency, starts_on, ends_on,
	 *                                    time_of_day, duration_mins, timezone.
	 * @param string               $from  YYYY-MM-DD, inclusive.
	 * @param string               $to    YYYY-MM-DD, inclusive.
	 * @return array<int, array<string, mixed>> Each with on, at, starts_at,
	 *                                          ends_at and planned_hours.
	 */
	public static function expand( array $rule, string $from, string $to ): array {
		$frequency = (string) ( $rule['frequency'] ?? '' );
		$starts_on = (string) ( $rule['starts_on'] ?? '' );

		if ( ! in_array( $frequency, self::FREQUENCIES, true ) || '' === $starts_on || $from > $to ) {
			return array();
		}

		$zone = self::zone( (string) ( $rule['timezone'] ?? '' ) );

		if ( null === $zone ) {
			return array();
		}

		$ends_on  = (string) ( $rule['ends_on'] ?? '' );
		$duration = (int) ( $rule['duration_mins'] ?? 0 );
		$at       = self::time_of_day( (string) ( $rule['time_of_day'] ?? '' ) );
		$found    = array();

		foreach ( self::days( $frequency, $starts_on, $ends_on, $to ) as $day ) {
			if ( $day < $from ) {
				continue;
			}

			$moment = self::moment( $day, $at, $zone );

			if ( null === $moment ) {
				continue;
			}

			$found[] = array(
				'on'            => $day,
				'at'            => $at,

				/*
				 * Worked out from the local date and time, every time, rather
				 * than by adding seconds to the last one. This is the line the
				 * clock change is survived by.
				 */
				'starts_at'     => $moment->getTimestamp(),
				'ends_at'       => $moment->getTimestamp() + ( max( 0, $duration ) * 60 ),
				'planned_hours' => self::planned_hours( $duration ),
			);
		}

		return $found;
	}

	/**
	 * What an occurrence of this length plans for (MEET-3).
	 *
	 * Rounded up to the next half hour, because an hour and ten minutes of
	 * somebody's afternoon is not an hour and a sixth on an invoice.
	 *
	 * @param int $duration_mins How long the meeting is.
	 * @return float
	 */
	public static function planned_hours( int $duration_mins ): float {
		if ( $duration_mins <= 0 ) {
			return 0.0;
		}

		return ceil( $duration_mins / 30 ) / 2;
	}

	/**
	 * Whether a string is one of the four patterns.
	 *
	 * @param string $frequency Candidate.
	 * @return bool
	 */
	public static function exists( string $frequency ): bool {
		return in_array( $frequency, self::FREQUENCIES, true );
	}

	/**
	 * How a pattern reads to a person.
	 *
	 * @param string $frequency One of {@see self::FREQUENCIES}.
	 * @return string
	 */
	public static function label( string $frequency ): string {
		switch ( $frequency ) {
			case self::WEEKLY:
				return __( 'Every week', 'blueworx-forge' );
			case self::FORTNIGHTLY:
				return __( 'Every fortnight', 'blueworx-forge' );
			case self::FOUR_WEEKLY:
				return __( 'Every four weeks', 'blueworx-forge' );
			case self::MONTHLY:
				return __( 'Every month, on the same date', 'blueworx-forge' );
			default:
				return __( 'Unknown', 'blueworx-forge' );
		}
	}

	/**
	 * The local days a pattern falls on, up to the end of the window.
	 *
	 * Walked as calendar dates rather than as instants, which is what keeps a
	 * meeting on its weekday and at its hour when the clocks move.
	 *
	 * @param string $frequency One of {@see self::FREQUENCIES}.
	 * @param string $starts_on First day, YYYY-MM-DD.
	 * @param string $ends_on   Last day the series runs, or '' for open.
	 * @param string $to        Last day of the window asked about.
	 * @return array<int, string>
	 */
	private static function days( string $frequency, string $starts_on, string $ends_on, string $to ): array {
		$last = '' !== $ends_on && $ends_on < $to ? $ends_on : $to;
		$days = array();

		try {
			$day = new DateTimeImmutable( $starts_on, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $error ) {
			return array();
		}

		$monthly = self::MONTHLY === $frequency;
		$step    = $monthly ? 1 : (int) self::WEEKS[ $frequency ];
		$of      = (int) $day->format( 'j' );
		$count   = 0;

		while ( $count < self::MOST ) {
			$on = $monthly
				? self::month_day( $day, $of )
				: $day->format( 'Y-m-d' );

			if ( '' !== $on && $on > $last ) {
				break;
			}

			if ( '' !== $on ) {
				$days[] = $on;
			}

			/*
			 * A monthly series walks month by month from the *first* of each
			 * month rather than by adding a month to the last date. Adding a
			 * month to the 31st of January lands in March and the February
			 * meeting is gone for ever; walking the months and asking each one
			 * whether it has a 31st skips February and keeps March.
			 */
			$day = $monthly
				? $day->modify( 'first day of next month' )
				: $day->add( new DateInterval( 'P' . ( $step * 7 ) . 'D' ) );

			++$count;
		}

		return $days;
	}

	/**
	 * A month's version of a day-of-month, or '' when it has not got one.
	 *
	 * The thirty-first of February is not a date, and both ways of pretending
	 * otherwise are worse than skipping it. Clamping to the twenty-eighth books
	 * a client meeting on a day nobody agreed to; rolling forward puts two
	 * meetings in March. Skipping invents nothing.
	 *
	 * @param DateTimeImmutable $month Any day in the month.
	 * @param int               $of    Day of the month wanted.
	 * @return string
	 */
	private static function month_day( DateTimeImmutable $month, int $of ): string {
		if ( $of > (int) $month->format( 't' ) ) {
			return '';
		}

		return $month->format( 'Y-m-' ) . str_pad( (string) $of, 2, '0', STR_PAD_LEFT );
	}

	/**
	 * The instant a local day and time falls at, in the client's zone.
	 *
	 * @param string       $day  YYYY-MM-DD.
	 * @param string       $at   HH:MM.
	 * @param DateTimeZone $zone The client's timezone.
	 * @return DateTimeImmutable|null
	 */
	private static function moment( string $day, string $at, DateTimeZone $zone ): ?DateTimeImmutable {
		try {
			return new DateTimeImmutable( $day . ' ' . $at . ':00', $zone );
		} catch ( Exception $error ) {
			return null;
		}
	}

	/**
	 * A timezone, or null when it is not one.
	 *
	 * @param string $timezone Candidate.
	 * @return DateTimeZone|null
	 */
	private static function zone( string $timezone ): ?DateTimeZone {
		if ( '' === $timezone ) {
			return null;
		}

		try {
			return new DateTimeZone( $timezone );
		} catch ( Exception $error ) {
			return null;
		}
	}

	/**
	 * A time of day, defaulting to something rather than to midnight by accident.
	 *
	 * @param string $at Candidate, HH:MM.
	 * @return string
	 */
	private static function time_of_day( string $at ): string {
		return 1 === preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $at ) ? $at : '09:00';
	}
}
