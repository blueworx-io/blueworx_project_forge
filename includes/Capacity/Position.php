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
	 * A whole studio's position over a window, person by person.
	 *
	 * @param array<int, string> $user_ids The people.
	 * @param string             $from     YYYY-MM-DD, inclusive.
	 * @param string             $to       YYYY-MM-DD, inclusive.
	 * @return array<string, array<string, mixed>>
	 */
	public static function for_people( array $user_ids, string $from, string $to ): array {
		$committed = Commitments::for_people( $user_ids, $from, $to );
		$out       = array();

		foreach ( $user_ids as $user_id ) {
			$out[ $user_id ] = self::calculate(
				Availability::hours( $user_id, $from, $to ),
				(float) ( $committed[ $user_id ]['hours'] ?? 0.0 ),
				Availability::is_recorded( $user_id, $from )
			);
		}

		return $out;
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
