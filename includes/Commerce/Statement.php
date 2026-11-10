<?php
/**
 * The arithmetic behind an hours statement.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Commerce;

use WP_Error;

/**
 * #669. Which ledger entries a statement counts, and what a date range means.
 *
 * Pure, so it is argued with in a test rather than against a database. A hold
 * (a reservation or the release that gives it back) moves hours between
 * "available" and "set aside" without any being spent, so it never appears as
 * a line on a statement and is summed apart from the real movements.
 */
final class Statement {

	/**
	 * The entry types that only hold hours.
	 *
	 * @var array<int, string>
	 */
	private const HOLDS = array(
		Entries::WORK_RESERVATION,
		Entries::WORK_RELEASE,
		Entries::MEETING_RESERVATION,
		Entries::MEETING_RELEASE,
	);

	/**
	 * Whether an entry of this type is a line on a statement.
	 *
	 * @param string $event_type Event type.
	 * @return bool
	 */
	public static function counts( string $event_type ): bool {
		return ! in_array( $event_type, self::HOLDS, true );
	}

	/**
	 * Hours that counted, up to and including a moment.
	 *
	 * @param array<int, array<string, mixed>> $entries Ledger entries.
	 * @param int                              $at      Unix time.
	 * @return float
	 */
	public static function sum_through( array $entries, int $at ): float {
		return self::sum( $entries, $at, true );
	}

	/**
	 * Hours held, up to and including a moment. Negative when hours are held.
	 *
	 * @param array<int, array<string, mixed>> $entries Ledger entries.
	 * @param int                              $at      Unix time.
	 * @return float
	 */
	public static function held_at( array $entries, int $at ): float {
		return self::sum( $entries, $at, false );
	}

	/**
	 * The counting entries inside a range, inclusive, in the order given.
	 *
	 * @param array<int, array<string, mixed>> $entries Ledger entries.
	 * @param int                              $from    Unix time.
	 * @param int                              $to      Unix time.
	 * @return array<int, array<string, mixed>>
	 */
	public static function in_range( array $entries, int $from, int $to ): array {
		$kept = array();

		foreach ( $entries as $entry ) {
			$when = (int) $entry['occurred_at'];

			if ( self::counts( (string) $entry['event_type'] ) && $when >= $from && $when <= $to ) {
				$kept[] = $entry;
			}
		}

		return $kept;
	}

	/**
	 * Two days as an inclusive range of UTC seconds.
	 *
	 * @param string $from First day, Y-m-d.
	 * @param string $to   Last day, Y-m-d.
	 * @return array{from: int, to: int}|WP_Error
	 */
	public static function range( string $from, string $to ) {
		$start = self::day( $from );
		$end   = self::day( $to );

		if ( null === $start || null === $end || $end < $start ) {
			return new WP_Error(
				'bwx_forge_invalid_range',
				'Choose two valid dates, the first no later than the second.',
				array( 'status' => 400 )
			);
		}

		return array(
			'from' => $start,
			'to'   => $end + DAY_IN_SECONDS - 1,
		);
	}

	/**
	 * Start of a Y-m-d day in UTC, or null when it is not a real date.
	 *
	 * @param string $day Y-m-d.
	 * @return int|null
	 */
	private static function day( string $day ): ?int {
		$parsed = strtotime( $day . ' 00:00:00 UTC' );

		if ( false === $parsed || gmdate( 'Y-m-d', $parsed ) !== $day ) {
			return null;
		}

		return $parsed;
	}

	/**
	 * Entries up to a moment, counting or holding.
	 *
	 * @param array<int, array<string, mixed>> $entries  Ledger entries.
	 * @param int                              $at       Unix time.
	 * @param bool                             $counting True for counting entries, false for holds.
	 * @return float
	 */
	private static function sum( array $entries, int $at, bool $counting ): float {
		$total = 0.0;

		foreach ( $entries as $entry ) {
			if ( (int) $entry['occurred_at'] <= $at && self::counts( (string) $entry['event_type'] ) === $counting ) {
				$total += (float) $entry['hours'];
			}
		}

		return round( $total, 2 );
	}
}
