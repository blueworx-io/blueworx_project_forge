<?php
/**
 * When a recurring task is due.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Recurring;

use DateInterval;
use DateTimeImmutable;

/**
 * The three cadences a recurring task can have, and the dates they produce.
 *
 * Pure: a rule in, dates out, nothing read from anywhere. The dates are
 * calendar days in the site's timezone, as `YYYY-MM-DD` strings, because a
 * task due "on the 15th" is due on the 15th wherever the server is.
 *
 * A rule is stored normalised — `{ every: 'day' }`, `{ every: 'week', days }`
 * with ISO weekdays 1–7 sorted and unique, or `{ every: 'month', day }` — and
 * anything else is refused at the door rather than stored and puzzled over.
 * A month day that a month does not have lands on that month's last day, so
 * "the 31st" means "the end of the month" without anybody having to say so.
 */
final class Rule {

	/**
	 * The cadences.
	 */
	public const EVERY = array( 'day', 'week', 'month' );

	/**
	 * Weekday names, by ISO number.
	 */
	private const WEEKDAYS = array(
		1 => 'Monday',
		2 => 'Tuesday',
		3 => 'Wednesday',
		4 => 'Thursday',
		5 => 'Friday',
		6 => 'Saturday',
		7 => 'Sunday',
	);

	/**
	 * A rule as stored, or null when the input is not one.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>|null
	 */
	public static function normalise( array $input ): ?array {
		$every = (string) ( $input['every'] ?? '' );

		if ( 'day' === $every ) {
			return array( 'every' => 'day' );
		}

		if ( 'week' === $every ) {
			$days = array();

			foreach ( (array) ( $input['days'] ?? array() ) as $day ) {
				$day = (int) $day;

				if ( $day < 1 || $day > 7 ) {
					return null;
				}

				$days[ $day ] = $day;
			}

			if ( array() === $days ) {
				return null;
			}

			ksort( $days );

			return array(
				'every' => 'week',
				'days'  => array_values( $days ),
			);
		}

		if ( 'month' === $every ) {
			$day = (int) ( $input['day'] ?? 0 );

			if ( $day < 1 || $day > 31 ) {
				return null;
			}

			return array(
				'every' => 'month',
				'day'   => $day,
			);
		}

		return null;
	}

	/**
	 * The first date the rule is due on, on or after a date.
	 *
	 * @param array<string, mixed> $rule A normalised rule.
	 * @param string               $date YYYY-MM-DD.
	 * @return string YYYY-MM-DD.
	 */
	public static function next_on_or_after( array $rule, string $date ): string {
		$day = self::day( $date );

		if ( 'week' === $rule['every'] ) {
			$days = array_map( 'intval', (array) $rule['days'] );

			for ( $i = 0; $i < 7; $i++ ) {
				$candidate = $day->add( new DateInterval( 'P' . $i . 'D' ) );

				if ( in_array( (int) $candidate->format( 'N' ), $days, true ) ) {
					return $candidate->format( 'Y-m-d' );
				}
			}
		}

		if ( 'month' === $rule['every'] ) {
			$wanted = (int) $rule['day'];

			// This month's occurrence, then next month's if it has gone.
			for ( $i = 0; $i < 2; $i++ ) {
				$month     = $day->modify( 'first day of this month' )->add( new DateInterval( 'P' . $i . 'M' ) );
				$candidate = self::clamp( $month, $wanted );

				if ( $candidate >= $day->format( 'Y-m-d' ) ) {
					return $candidate;
				}
			}
		}

		return $day->format( 'Y-m-d' );
	}

	/**
	 * Every date the rule is due on inside a window, inclusive at both ends,
	 * stopping at the rule's end date if it has one.
	 *
	 * @param array<string, mixed> $rule    A normalised rule.
	 * @param string               $from    YYYY-MM-DD.
	 * @param string               $to      YYYY-MM-DD.
	 * @param string               $ends_on YYYY-MM-DD, or empty for never.
	 * @return array<int, string>
	 */
	public static function due_between( array $rule, string $from, string $to, string $ends_on = '' ): array {
		if ( '' !== $ends_on && $ends_on < $to ) {
			$to = $ends_on;
		}

		$found = array();
		$next  = self::next_on_or_after( $rule, $from );

		// A guard rather than a limit anybody should meet: a daily task
		// unopened for three years is a thousand rows, and that is enough.
		while ( $next <= $to && count( $found ) < 1000 ) {
			$found[] = $next;
			$next    = self::next_on_or_after( $rule, self::day( $next )->add( new DateInterval( 'P1D' ) )->format( 'Y-m-d' ) );
		}

		return $found;
	}

	/**
	 * A rule in words, for a list.
	 *
	 * @param array<string, mixed> $rule A normalised rule.
	 * @return string
	 */
	public static function describe( array $rule ): string {
		if ( 'day' === $rule['every'] ) {
			return __( 'Every day', 'blueworx-forge' );
		}

		if ( 'week' === $rule['every'] ) {
			$names = array();

			foreach ( (array) $rule['days'] as $day ) {
				$names[] = self::WEEKDAYS[ (int) $day ] ?? '';
			}

			$last = array_pop( $names );

			return array() === $names
				/* translators: %s: weekday name */
				? sprintf( __( 'Every %s', 'blueworx-forge' ), $last )
				/* translators: 1: comma-separated weekday names, 2: last weekday name */
				: sprintf( __( 'Every %1$s and %2$s', 'blueworx-forge' ), implode( ', ', $names ), $last );
		}

		$day = (int) $rule['day'];

		if ( 31 === $day ) {
			return __( 'Monthly on the last day', 'blueworx-forge' );
		}

		/* translators: %s: ordinal day of the month, such as 15th */
		return sprintf( __( 'Monthly on the %s', 'blueworx-forge' ), self::ordinal( $day ) );
	}

	/**
	 * A day in the site's timezone.
	 *
	 * @param string $date YYYY-MM-DD.
	 * @return DateTimeImmutable
	 */
	private static function day( string $date ): DateTimeImmutable {
		$day = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );

		return false === $day ? new DateTimeImmutable( 'today', wp_timezone() ) : $day;
	}

	/**
	 * The wanted day of a month, or the month's last day when it is shorter.
	 *
	 * @param DateTimeImmutable $month  Any day in the month.
	 * @param int               $wanted Day of the month wanted.
	 * @return string YYYY-MM-DD.
	 */
	private static function clamp( DateTimeImmutable $month, int $wanted ): string {
		$last = (int) $month->format( 't' );

		return $month->setDate( (int) $month->format( 'Y' ), (int) $month->format( 'n' ), min( $wanted, $last ) )->format( 'Y-m-d' );
	}

	/**
	 * 1st, 2nd, 3rd, 4th … 21st, 22nd, 23rd.
	 *
	 * @param int $n A day of the month.
	 * @return string
	 */
	private static function ordinal( int $n ): string {
		if ( $n % 100 >= 11 && $n % 100 <= 13 ) {
			return $n . 'th';
		}

		switch ( $n % 10 ) {
			case 1:
				return $n . 'st';
			case 2:
				return $n . 'nd';
			case 3:
				return $n . 'rd';
			default:
				return $n . 'th';
		}
	}
}
