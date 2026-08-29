<?php
/**
 * Dated time somebody is not available for.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Capacity;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Tenancy\Ids;

/**
 * CAP-1's leave and other unavailable time (#136).
 *
 * Whole days, both ends inclusive — "off from the 3rd to the 7th" is five days
 * to everybody who says it, and a range that quietly excluded the last day
 * would put somebody back at work on the day they are away.
 *
 * Overlapping records are allowed and are not a mistake: somebody on leave
 * during a shutdown is two true statements about the same day. The calculation
 * takes the day out once however many records cover it.
 */
final class Unavailability {

	/**
	 * Id prefix for an unavailability record.
	 */
	public const PREFIX = 'una';

	/**
	 * The kinds a record can be. A closed list, because it is reported on.
	 */
	public const KINDS = array( 'leave', 'public-holiday', 'training', 'other' );

	/**
	 * Records time somebody is not available for.
	 *
	 * @param string $user_id   The person.
	 * @param string $starts_on YYYY-MM-DD, inclusive.
	 * @param string $ends_on   YYYY-MM-DD, inclusive.
	 * @param string $kind      One of KINDS.
	 * @param int    $author    WordPress user id of the author.
	 * @param string $note      Optional note.
	 * @return array<string, mixed>|null
	 */
	public static function add( string $user_id, string $starts_on, string $ends_on, string $kind, int $author, string $note = '' ): ?array {
		global $wpdb;

		// A range entered backwards is a typo with an obvious reading, and
		// storing it as given would silently cover no days at all.
		if ( $ends_on < $starts_on ) {
			list( $starts_on, $ends_on ) = array( $ends_on, $starts_on );
		}

		$row = array(
			'id'         => Ids::create( self::PREFIX ),
			'user_id'    => $user_id,
			'starts_on'  => $starts_on,
			'ends_on'    => $ends_on,
			'kind'       => in_array( $kind, self::KINDS, true ) ? $kind : 'other',
			'note'       => $note,
			'created_at' => bwx_forge_now(),
			'created_by' => $author,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		$inserted = $wpdb->insert( Schema::unavailability_table(), $row, Formats::for_row( $row ) );

		if ( ! $inserted ) {
			return null;
		}

		// #144. Time off takes days out of weeks that work is already committed
		// to, and the work should say so rather than only going red.
		Trail::record(
			$user_id,
			$starts_on,
			$ends_on,
			__( 'Somebody in a seat had time off recorded against them.', 'blueworx-forge' )
		);

		return self::hydrate( $row );
	}

	/**
	 * Removes a record.
	 *
	 * @param string $id Record id.
	 * @return bool
	 */
	public static function remove( string $id ): bool {
		global $wpdb;

		$table = Schema::unavailability_table();

		/*
		 * Read before deleting, because the record is what says whose time it
		 * was and which weeks it covered — and after the delete there is
		 * nothing left to ask. Cancelled leave changes the picture as surely as
		 * booked leave does; it just changes it the other way.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		$removed = (bool) $wpdb->delete( $table, array( 'id' => $id ), array( '%s' ) );

		if ( $removed && is_array( $row ) ) {
			Trail::record(
				(string) $row['user_id'],
				(string) $row['starts_on'],
				(string) $row['ends_on'],
				__( 'Time off booked for somebody in a seat was cancelled.', 'blueworx-forge' )
			);
		}

		return $removed;
	}

	/**
	 * Every record touching a period, for one person.
	 *
	 * Touching, not contained by: a fortnight's leave that starts before the
	 * period and ends inside it takes days out of it, and a query that only
	 * found records starting within the period would miss exactly the long
	 * absences that matter most.
	 *
	 * @param string $user_id The person.
	 * @param string $from    YYYY-MM-DD, inclusive.
	 * @param string $to      YYYY-MM-DD, inclusive.
	 * @return array<int, array<string, mixed>>
	 */
	public static function overlapping( string $user_id, string $from, string $to ): array {
		global $wpdb;

		$table = Schema::unavailability_table();

		// The comparison reads backwards on purpose: a record overlaps the
		// period when it starts before the period ends and ends after the
		// period starts. Anything narrower misses the long absences that take
		// the most time out.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %s AND starts_on <= %s AND ends_on >= %s ORDER BY starts_on ASC", $user_id, $to, $from ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * The same, for several people at once.
	 *
	 * One query rather than one per person. The capacity view asks about the
	 * whole studio, and a query each turned a screen into a wait (#139).
	 *
	 * @param array<int, string> $user_ids The people.
	 * @param string             $from     YYYY-MM-DD, inclusive.
	 * @param string             $to       YYYY-MM-DD, inclusive.
	 * @return array<string, array<int, array<string, mixed>>> Keyed by person, everybody present.
	 */
	public static function for_people( array $user_ids, string $from, string $to ): array {
		global $wpdb;

		$out = array_fill_keys( $user_ids, array() );

		if ( array() === $user_ids ) {
			return $out;
		}

		$table = Schema::unavailability_table();
		$slots = implode( ', ', array_fill( 0, count( $user_ids ), '%s' ) );

		$values   = array_values( $user_ids );
		$values[] = $to;
		$values[] = $from;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table name cannot be a placeholder; the id placeholders are counted above.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id IN ({$slots}) AND starts_on <= %s AND ends_on >= %s ORDER BY starts_on ASC", $values ),
			ARRAY_A
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$user_id = (string) $row['user_id'];

			if ( isset( $out[ $user_id ] ) ) {
				$out[ $user_id ][] = self::hydrate( $row );
			}
		}

		return $out;
	}

	/**
	 * The days a person is unavailable within a period, each listed once.
	 *
	 * @param string $user_id The person.
	 * @param string $from    YYYY-MM-DD, inclusive.
	 * @param string $to      YYYY-MM-DD, inclusive.
	 * @return array<string, string> Date to the kind that took it out.
	 */
	public static function days( string $user_id, string $from, string $to ): array {
		return self::expand( self::overlapping( $user_id, $from, $to ), $from, $to );
	}

	/**
	 * The same expansion, over records already in hand.
	 *
	 * Separate from the query so the rules that matter — both ends inclusive,
	 * a day covered twice taken out once, a record clipped to the period asked
	 * about — can be tested without a database.
	 *
	 * @param array<int, array<string, mixed>> $records Unavailability records.
	 * @param string                           $from    YYYY-MM-DD, inclusive.
	 * @param string                           $to      YYYY-MM-DD, inclusive.
	 * @return array<string, string> Date to the kind that took it out.
	 */
	public static function expand( array $records, string $from, string $to ): array {
		$days = array();

		foreach ( $records as $record ) {
			$day  = max( $record['starts_on'], $from );
			$last = min( $record['ends_on'], $to );

			while ( $day <= $last ) {
				// First record to claim a day keeps it. Which kind is shown for
				// a day covered twice is arbitrary; that it is only taken out
				// once is not.
				if ( ! isset( $days[ $day ] ) ) {
					$days[ $day ] = $record['kind'];
				}

				$day = gmdate( 'Y-m-d', (int) strtotime( $day . ' 00:00:00 UTC' ) + DAY_IN_SECONDS );
			}
		}

		ksort( $days );

		return $days;
	}

	/**
	 * A stored row as the rest of the code wants it.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		return array(
			'id'         => (string) $row['id'],
			'user_id'    => (string) $row['user_id'],
			'starts_on'  => (string) $row['starts_on'],
			'ends_on'    => (string) $row['ends_on'],
			'kind'       => (string) $row['kind'],
			'note'       => (string) ( $row['note'] ?? '' ),
			'created_at' => (int) ( $row['created_at'] ?? 0 ),
			'created_by' => (int) ( $row['created_by'] ?? 0 ),
		);
	}
}
