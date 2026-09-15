<?php
/**
 * Which due days have already become tasks.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Recurring;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Tenancy\Ids;

/**
 * One row per source per due day, and the row is the claim.
 *
 * The same argument Notifications\Register makes. Two people opening Forge
 * at the same moment both find Monday's backup task due; both try to make it.
 * Checking first and then creating lets both through. Inserting a row with
 * a unique key on (source, day) lets exactly one through — the database
 * refuses the second insert — and that one makes the task. No lock, no
 * transient, no second query.
 */
final class Occurrences {

	/**
	 * Id prefix for an occurrence.
	 */
	public const PREFIX = 'rco';

	/**
	 * Claims a due day for a source. True for the caller who got it.
	 *
	 * @param string $recurring_id Source id.
	 * @param string $due_on       YYYY-MM-DD.
	 * @return bool
	 */
	public static function claim( string $recurring_id, string $due_on ): bool {
		global $wpdb;

		$row = array(
			'id'           => Ids::create( self::PREFIX ),
			'recurring_id' => $recurring_id,
			'due_on'       => $due_on,
			'work_item_id' => '',
			'created_at'   => bwx_forge_now(),
		);

		$suppress = $wpdb->suppress_errors();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the duplicate-key refusal is the point.
		$inserted = $wpdb->insert( Schema::recurring_occurrences_table(), $row, Formats::for_row( $row ) );

		$wpdb->suppress_errors( $suppress );

		return false !== $inserted && 0 < (int) $inserted;
	}

	/**
	 * Records which task a claimed day became.
	 *
	 * @param string $recurring_id Source id.
	 * @param string $due_on       YYYY-MM-DD.
	 * @param string $work_item_id The task.
	 */
	public static function record_item( string $recurring_id, string $due_on, string $work_item_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update(
			Schema::recurring_occurrences_table(),
			array( 'work_item_id' => $work_item_id ),
			array(
				'recurring_id' => $recurring_id,
				'due_on'       => $due_on,
			),
			array( '%s' ),
			array( '%s', '%s' )
		);
	}

	/**
	 * The most recent occurrence of each source, keyed by source id.
	 *
	 * @param array<int, string> $recurring_ids Source ids.
	 * @return array<string, array{due_on: string, work_item_id: string}>
	 */
	public static function latest_for( array $recurring_ids ): array {
		global $wpdb;

		$wanted = array_values( array_unique( array_filter( array_map( 'strval', $recurring_ids ) ) ) );

		if ( array() === $wanted ) {
			return array();
		}

		$table = Schema::recurring_occurrences_table();
		$slots = implode( ', ', array_fill( 0, count( $wanted ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name cannot be a placeholder; the slots are built from the count above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT recurring_id, due_on, work_item_id FROM {$table} WHERE recurring_id IN ({$slots}) ORDER BY due_on DESC", $wanted ), ARRAY_A );

		$latest = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id = (string) $row['recurring_id'];

			if ( ! isset( $latest[ $id ] ) ) {
				$latest[ $id ] = array(
					'due_on'       => (string) $row['due_on'],
					'work_item_id' => (string) $row['work_item_id'],
				);
			}
		}

		return $latest;
	}
}
