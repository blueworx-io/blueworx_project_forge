<?php
/**
 * What is already spoken for, across every client at once.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Capacity;

use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Work\Stages;

/**
 * The cross-client commitment figure (#138).
 *
 * A person cannot look free on one client while committed on another, which
 * means this is the one read in Forge whose whole purpose is to span tenants.
 * Everywhere else, a read scoped to a client is the safe answer; here it is the
 * wrong one, and a per-client capacity figure would be actively misleading —
 * three clients each shown a person with plenty of room, and the person with
 * none.
 *
 * People are already global records (AUTH-6), so counting once is a matter of
 * summing across clients rather than reconciling three identities. What has to
 * be got right is the boundary: this read is reachable from the studio only,
 * and the route that exposes it says so.
 */
final class Commitments {

	/**
	 * Every allocation across every client overlapping a window.
	 *
	 * Deliberately unscoped. The stages and the window do the narrowing, which
	 * keeps the result to live work rather than to the whole history.
	 *
	 * @param string $from YYYY-MM-DD, inclusive.
	 * @param string $to   YYYY-MM-DD, inclusive.
	 * @return array<int, array<string, mixed>>
	 */
	public static function live( string $from, string $to ): array {
		global $wpdb;

		if ( '' === $from || '' === $to || $to < $from ) {
			return array();
		}

		$table  = Schema::work_items_table();
		$stages = array_merge( Allocations::COMMITTING, array( Stages::BLOCKED ) );
		$slots  = implode( ', ', array_fill( 0, count( $stages ), '%s' ) );

		$values = $stages;

		/*
		 * An item overlaps the window when it starts before the window ends and
		 * ends after the window starts. Written this way round so a job that
		 * spans the whole period is included rather than missed for starting
		 * before it.
		 */
		$values[] = $to;
		$values[] = $from;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table name and stage list are this class's own literals; the placeholders are counted above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE archived = 0
				AND terminal_outcome = ''
				AND stage IN ({$slots})
				AND planned_start <> ''
				AND planned_due <> ''
				AND planned_start <= %s
				AND planned_due >= %s",
				$values
			),
			ARRAY_A
		);

		$out = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			// Allocations decides whether a Blocked item counts, from the stage
			// it was blocked out of. The query cannot ask that question, so it
			// fetches Blocked and lets the rule refuse it.
			foreach ( Allocations::from_item( $row ) as $allocation ) {
				$out[] = $allocation;
			}
		}

		return $out;
	}

	/**
	 * The same answer, worked out from allocations already in hand.
	 *
	 * No database in it, so "one person, one total, however many clients" can
	 * be stated in a test rather than inferred from a site.
	 *
	 * @param array<int, array<string, mixed>>                $allocations  Every allocation to consider.
	 * @param array<string, array<int, array<string, mixed>>> $days_by_user Availability::by_day per person.
	 * @return array<string, array<string, mixed>>
	 */
	public static function gather( array $allocations, array $days_by_user ): array {
		$out = array();

		foreach ( array_keys( $days_by_user ) as $user_id ) {
			$out[ $user_id ] = array(
				'hours'       => 0.0,
				'by_day'      => array(),
				'allocations' => array(),
			);
		}

		foreach ( $allocations as $allocation ) {
			$user_id = (string) ( $allocation['user_id'] ?? '' );

			if ( ! isset( $out[ $user_id ] ) ) {
				continue;
			}

			$spread = Allocations::spread( $allocation, $days_by_user[ $user_id ] );

			foreach ( $spread as $date => $hours ) {
				$out[ $user_id ]['by_day'][ $date ] = round( ( $out[ $user_id ]['by_day'][ $date ] ?? 0.0 ) + $hours, 2 );
			}

			$out[ $user_id ]['allocations'][] = array_merge( $allocation, array( 'by_day' => $spread ) );
			$out[ $user_id ]['hours']         = round( $out[ $user_id ]['hours'] + array_sum( $spread ), 2 );
		}

		foreach ( array_keys( $out ) as $user_id ) {
			ksort( $out[ $user_id ]['by_day'] );
		}

		return $out;
	}

	/**
	 * What a set of people are committed to over a window.
	 *
	 * @param array<int, string> $user_ids The people.
	 * @param string             $from     YYYY-MM-DD, inclusive.
	 * @param string             $to       YYYY-MM-DD, inclusive.
	 * @return array<string, array<string, mixed>>
	 */
	public static function for_people( array $user_ids, string $from, string $to ): array {
		$days = array();

		foreach ( $user_ids as $user_id ) {
			$days[ $user_id ] = Availability::by_day( $user_id, $from, $to );
		}

		// One query for everybody, not one per person: the capacity view asks
		// this for the whole studio at once, and a query per person over eight
		// weeks was the difference between a page and a wait.
		return self::gather( self::live( $from, $to ), $days );
	}

	/**
	 * One person's committed hours over a window.
	 *
	 * @param string $user_id The person.
	 * @param string $from    YYYY-MM-DD, inclusive.
	 * @param string $to      YYYY-MM-DD, inclusive.
	 * @return float
	 */
	public static function hours( string $user_id, string $from, string $to ): float {
		$gathered = self::for_people( array( $user_id ), $from, $to );

		return (float) ( $gathered[ $user_id ]['hours'] ?? 0.0 );
	}
}
