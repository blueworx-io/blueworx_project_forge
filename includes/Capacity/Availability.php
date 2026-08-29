<?php
/**
 * What somebody's time actually is, before anything is committed against it.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Capacity;

/**
 * The one place a person's available hours for a period are read from (#136).
 *
 * Everything downstream in M7 — the cross-client figure, the capacity view, the
 * gate at Up Next — asks this and nothing else. That is deliberate: the moment
 * two places work out available hours, they disagree, and the one somebody is
 * looking at is not the one the gate used.
 *
 * Availability here is base hours minus time off, and nothing else. What has
 * been committed against it is a separate question with a separate answer, so
 * that "they had no time" and "their time was already spoken for" never arrive
 * as the same number.
 */
final class Availability {

	/**
	 * The most days one call will expand.
	 *
	 * A guard against a typo, not a policy — an end date typed as 2206 instead
	 * of 2026 would otherwise walk day by day through two centuries. Roughly
	 * three years, which is longer than any period anybody plans against.
	 */
	public const MAX_DAYS = 1100;

	/**
	 * A person's available hours across a period.
	 *
	 * @param string $user_id The person.
	 * @param string $from    YYYY-MM-DD, inclusive.
	 * @param string $to      YYYY-MM-DD, inclusive.
	 * @return float
	 */
	public static function hours( string $user_id, string $from, string $to ): float {
		$breakdown = self::by_day( $user_id, $from, $to );

		return round( array_sum( array_column( $breakdown, 'hours' ) ), 2 );
	}

	/**
	 * The same answer, day by day, with the reason for every zero.
	 *
	 * Drill-down needs this and so does trust: a total that cannot be taken
	 * apart is a number people work around rather than with. #139 renders it;
	 * the gates quote it when they refuse something.
	 *
	 * @param string $user_id The person.
	 * @param string $from    YYYY-MM-DD, inclusive.
	 * @param string $to      YYYY-MM-DD, inclusive.
	 * @return array<int, array{date: string, hours: float, base_hours: float, reason: string}>
	 */
	public static function by_day( string $user_id, string $from, string $to ): array {
		if ( '' === $user_id ) {
			return array();
		}

		// Both read once for the whole period rather than once per day. A
		// fortnight was otherwise fourteen pairs of queries returning the same
		// rows, and the capacity view asks this for every person at once.
		return self::calculate(
			Patterns::history( $user_id ),
			Unavailability::overlapping( $user_id, $from, $to ),
			$from,
			$to
		);
	}

	/**
	 * The same answer, for several people at once.
	 *
	 * Two queries for the whole studio rather than two per person. The rule
	 * itself is unchanged — every person still goes through {@see self::calculate()}
	 * — but the capacity view asks this for everybody over a quarter, and a pair
	 * of reads each was the difference between a screen and a wait (#139).
	 *
	 * @param array<int, string> $user_ids The people.
	 * @param string             $from     YYYY-MM-DD, inclusive.
	 * @param string             $to       YYYY-MM-DD, inclusive.
	 * @return array<string, array<int, array{date: string, hours: float, base_hours: float, reason: string}>>
	 */
	public static function for_people( array $user_ids, string $from, string $to ): array {
		$patterns = Patterns::for_people( $user_ids );
		$away     = Unavailability::for_people( $user_ids, $from, $to );
		$out      = array();

		foreach ( $user_ids as $user_id ) {
			$out[ $user_id ] = self::calculate(
				$patterns[ $user_id ] ?? array(),
				$away[ $user_id ] ?? array(),
				$from,
				$to
			);
		}

		return $out;
	}

	/**
	 * The same answer, worked out from records already in hand.
	 *
	 * The whole rule lives here and nowhere else, with no database in it, so
	 * what it does can be stated in tests rather than inferred from a site.
	 *
	 * @param array<int, array<string, mixed>> $patterns Every pattern for the person.
	 * @param array<int, array<string, mixed>> $away     Unavailability records touching the period.
	 * @param string                           $from     YYYY-MM-DD, inclusive.
	 * @param string                           $to       YYYY-MM-DD, inclusive.
	 * @return array<int, array{date: string, hours: float, base_hours: float, reason: string}>
	 */
	public static function calculate( array $patterns, array $away, string $from, string $to ): array {
		if ( '' === $from || '' === $to || $to < $from ) {
			return array();
		}

		$off  = Unavailability::expand( $away, $from, $to );
		$days = array();
		$day  = $from;
		$seen = 0;

		while ( $day <= $to && $seen < self::MAX_DAYS ) {
			$days[] = self::one_day( $patterns, $day, $off );

			$day = gmdate( 'Y-m-d', (int) strtotime( $day . ' 00:00:00 UTC' ) + DAY_IN_SECONDS );
			++$seen;
		}

		return $days;
	}

	/**
	 * Whether anybody has said what this person's hours are.
	 *
	 * Asked separately because zero hours and nothing recorded are different
	 * facts that look identical in a total. A capacity view that shows somebody
	 * at zero is saying they have no room; one that shows them as unrecorded is
	 * saying to go and set them up.
	 *
	 * @param string $user_id The person.
	 * @param string $date    YYYY-MM-DD.
	 * @return bool
	 */
	public static function is_recorded( string $user_id, string $date ): bool {
		return null !== Patterns::in_force( $user_id, $date );
	}

	/**
	 * One day's availability, and why.
	 *
	 * @param array<int, array<string, mixed>> $patterns Every pattern for the person.
	 * @param string                           $date     YYYY-MM-DD.
	 * @param array<string, string>            $away     Dates the person is unavailable, to the kind.
	 * @return array{date: string, hours: float, base_hours: float, reason: string}
	 */
	private static function one_day( array $patterns, string $date, array $away ): array {
		$pattern = Patterns::pick( $patterns, $date );

		if ( null === $pattern ) {
			return array(
				'date'       => $date,
				'hours'      => 0.0,
				'base_hours' => 0.0,
				'reason'     => 'no-pattern',
			);
		}

		$base = Patterns::hours_for_weekday( $pattern, $date );

		if ( isset( $away[ $date ] ) ) {
			return array(
				'date'       => $date,
				'hours'      => 0.0,
				'base_hours' => $base,
				'reason'     => $away[ $date ],
			);
		}

		return array(
			'date'       => $date,
			'hours'      => $base,
			'base_hours' => $base,
			// A day off in somebody's normal week is not the same as leave, and
			// a view that showed both as blank would have people asking why
			// somebody is on leave every Friday.
			'reason'     => $base > 0 ? '' : 'non-working-day',
		);
	}
}
