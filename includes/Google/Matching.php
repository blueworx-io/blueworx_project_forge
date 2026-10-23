<?php
/**
 * Google meetings that are really standing meetings.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Google;

use Blueworx\Forge\Meetings\Diary;
use Blueworx\Forge\Meetings\Occurrence;
use Blueworx\Forge\Meetings\Series;

/**
 * #606. Standing meetings stay as they are, and a Google event that is the
 * same meeting is skipped, so it counts and charges once (Luke, 2026-10-23).
 *
 * It matches when it is on the same day, its time overlaps the standing
 * meeting's, and somebody on it is that meeting's host or an attendee.
 *
 * **Worked out on every read, never stored.** If either one moves, Google
 * sends no change for the standing meeting moving, so a stored answer would
 * go stale; asked fresh, the Google copy appears or disappears to suit. Only
 * a meeting nobody has settled is skipped: once somebody has picked its
 * client it is theirs, and hiding it would hide a choice they made.
 */
final class Matching {

	/**
	 * Whether a synced meeting is a standing meeting's own.
	 *
	 * @param array<string, mixed>             $meeting Hydrated.
	 * @param array<int, array<string, mixed>> $slots   From {@see self::standing_slots()}.
	 * @return bool
	 */
	public static function skipped( array $meeting, array $slots ): bool {
		if ( ! empty( $meeting['settled'] ) || ! empty( $meeting['all_day'] ) ) {
			return false;
		}

		$people = array_column( (array) ( $meeting['people'] ?? array() ), 'user_id' );
		$start  = (int) ( $meeting['starts_at'] ?? 0 );
		$end    = max( $start, (int) ( $meeting['ends_at'] ?? 0 ) );

		foreach ( $slots as $slot ) {
			$day = (string) ( $meeting['on'] ?? '' );

			if ( $day !== (string) $slot['on'] ) {
				continue;
			}

			if ( ! ( $start < (int) $slot['ends_at'] && (int) $slot['starts_at'] < $end ) ) {
				continue;
			}

			if ( array() !== array_intersect( $people, (array) $slot['people'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every standing meeting in a window, as who is on it and when.
	 *
	 * Unscoped across clients, as {@see Series::running_between()} is: a
	 * standing meeting on any client is still the same hour of somebody's day.
	 *
	 * @param string $from YYYY-MM-DD, inclusive.
	 * @param string $to   YYYY-MM-DD, inclusive.
	 * @return array<int, array{people: array<int, string>, on: string, starts_at: int, ends_at: int}>
	 */
	public static function standing_slots( string $from, string $to ): array {
		$series = Series::running_between( $from, $to );

		if ( array() === $series ) {
			return array();
		}

		$stored = Diary::stored_between( $from, $to );
		$slots  = array();

		foreach ( $series as $one ) {
			$people = array_values( array_filter( array_merge( array( (string) $one['host_user_id'] ), (array) ( $one['attendee_ids'] ?? array() ) ), 'strlen' ) );

			foreach ( Occurrence::merge( Series::occurrences( $one, $from, $to ), $stored[ (string) $one['id'] ] ?? array(), $from, $to ) as $meeting ) {
				if ( Occurrence::CANCELLED === (string) $meeting['status'] ) {
					continue;
				}

				$slots[] = array(
					'people'    => $people,
					'on'        => (string) $meeting['on'],
					'starts_at' => (int) $meeting['starts_at'],
					'ends_at'   => (int) $meeting['ends_at'],
				);
			}
		}

		return $slots;
	}
}
