<?php
/**
 * Time against commitment, in one place.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Capacity;

/**
 * Where somebody stands (#139).
 *
 * The one place available hours and committed hours meet. Everything that has
 * an opinion about whether there is room — the capacity view, the gates in #141
 * and #142, the answer a client gets in #140 — asks this, so that a figure
 * somebody is looking at is the same figure a gate refused on.
 *
 * The band matters as much as the numbers. A cell showing 39 of 40 hours is
 * technically fine and practically full, and a person nobody has set up is not
 * a person with no room — they are a person to go and set up, which is a
 * different thing to do about it.
 */
final class Position {

	/**
	 * Room to take something on.
	 */
	public const CLEAR = 'clear';

	/**
	 * Nearly full. Not a refusal, a warning.
	 */
	public const TIGHT = 'tight';

	/**
	 * More committed than there is time for.
	 */
	public const OVER = 'over';

	/**
	 * Nobody has said what this person's hours are. Not a capacity state.
	 */
	public const UNRECORDED = 'unrecorded';

	/**
	 * The share of somebody's time at which "fine" becomes "careful".
	 */
	public const TIGHT_AT = 0.8;

	/**
	 * Available against committed, and what to call it.
	 *
	 * No database in it, so the thresholds can be stated in a test.
	 *
	 * @param float $available Hours the person has.
	 * @param float $committed Hours already spoken for.
	 * @param bool  $recorded  Whether anybody has set their hours up at all.
	 * @return array{available: float, committed: float, remaining: float, band: string}
	 */
	public static function calculate( float $available, float $committed, bool $recorded ): array {
		$available = round( $available, 2 );
		$committed = round( $committed, 2 );

		return array(
			'available' => $available,
			'committed' => $committed,
			'remaining' => round( $available - $committed, 2 ),
			'band'      => self::band( $available, $committed, $recorded ),
		);
	}

	/**
	 * A position worked out from days already in hand.
	 *
	 * The efficient shape, and the reason it exists: a capacity view asks for
	 * eight weeks at once, and reading a person's availability once per week
	 * turns one screen into hundreds of queries. Both series here cover the
	 * whole range, and any window inside it is a sum rather than another read.
	 *
	 * @param array<int, array<string, mixed>> $days      Availability::by_day for the person.
	 * @param array<string, float>             $committed Committed hours by date.
	 * @param string                           $from      YYYY-MM-DD, inclusive.
	 * @param string                           $to        YYYY-MM-DD, inclusive.
	 * @return array{available: float, committed: float, remaining: float, band: string}
	 */
	public static function over( array $days, array $committed, string $from, string $to ): array {
		$available = 0.0;
		$recorded  = false;

		foreach ( $days as $day ) {
			$date = (string) ( $day['date'] ?? '' );

			if ( $date < $from || $date > $to ) {
				continue;
			}

			$available += (float) ( $day['hours'] ?? 0 );

			/*
			 * A day whose reason is "no pattern" is a day nobody has spoken
			 * about. One day in the window that is not that means somebody's
			 * hours are recorded — which is what separates "no room" from "not
			 * set up", without a second query to ask.
			 */
			if ( 'no-pattern' !== (string) ( $day['reason'] ?? '' ) ) {
				$recorded = true;
			}
		}

		$spent = 0.0;

		foreach ( $committed as $date => $hours ) {
			if ( (string) $date < $from || (string) $date > $to ) {
				continue;
			}

			$spent += (float) $hours;
		}

		return self::calculate( $available, $spent, $recorded );
	}

	/**
	 * A whole studio's position over a window, person by person.
	 *
	 * @param array<int, string> $user_ids The people.
	 * @param string             $from     YYYY-MM-DD, inclusive.
	 * @param string             $to       YYYY-MM-DD, inclusive.
	 * @return array<string, array<string, mixed>>
	 */
	public static function for_people( array $user_ids, string $from, string $to ): array {
		$read = self::read( $user_ids, $from, $to );
		$out  = array();

		foreach ( $user_ids as $user_id ) {
			$out[ $user_id ] = self::over(
				$read['days'][ $user_id ] ?? array(),
				$read['committed'][ $user_id ]['by_day'] ?? array(),
				$from,
				$to
			);
		}

		return $out;
	}

	/**
	 * The whole grid: a position per person per week, and one for the period.
	 *
	 * One place rather than a loop in the controller, because the loop is where
	 * the cost is. Everything is read once for the whole range and every cell
	 * is a sum over what is already in memory.
	 *
	 * @param array<int, string>                        $user_ids The people.
	 * @param array<int, array{from: string, to: string}> $weeks  The columns.
	 * @param string                                    $from     YYYY-MM-DD, inclusive.
	 * @param string                                    $to       YYYY-MM-DD, inclusive.
	 * @return array<string, array{weeks: array<int, array<string, mixed>>, total: array<string, mixed>}>
	 */
	public static function grid( array $user_ids, array $weeks, string $from, string $to ): array {
		$read = self::read( $user_ids, $from, $to );
		$out  = array();

		foreach ( $user_ids as $user_id ) {
			$days      = $read['days'][ $user_id ] ?? array();
			$committed = $read['committed'][ $user_id ]['by_day'] ?? array();
			$cells     = array();

			foreach ( $weeks as $week ) {
				$cells[] = array_merge(
					array(
						'from' => $week['from'],
						'to'   => $week['to'],
					),
					self::over( $days, $committed, $week['from'], $week['to'] )
				);
			}

			$out[ $user_id ] = array(
				'weeks' => $cells,
				'total' => self::over( $days, $committed, $from, $to ),
			);
		}

		return $out;
	}

	/**
	 * Both series, for everybody, read once.
	 *
	 * @param array<int, string> $user_ids The people.
	 * @param string             $from     YYYY-MM-DD, inclusive.
	 * @param string             $to       YYYY-MM-DD, inclusive.
	 * @return array{days: array<string, array<int, array<string, mixed>>>, committed: array<string, array<string, mixed>>}
	 */
	private static function read( array $user_ids, string $from, string $to ): array {
		$days = Availability::for_people( $user_ids, $from, $to );

		return array(
			'days'      => $days,
			'committed' => Commitments::gather( Commitments::live( $from, $to ), $days ),
		);
	}

	/**
	 * What to call a position.
	 *
	 * @param float $available Hours the person has.
	 * @param float $committed Hours already spoken for.
	 * @param bool  $recorded  Whether their hours are set up.
	 * @return string
	 */
	private static function band( float $available, float $committed, bool $recorded ): string {
		if ( ! $recorded ) {
			return self::UNRECORDED;
		}

		if ( $committed > $available ) {
			return self::OVER;
		}

		if ( $available <= 0 ) {
			// No time and nothing on it: a week of leave, which is a plan
			// rather than a problem.
			return self::CLEAR;
		}

		return $committed / $available >= self::TIGHT_AT ? self::TIGHT : self::CLEAR;
	}
}
