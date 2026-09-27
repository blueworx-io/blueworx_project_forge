<?php
/**
 * The one-off step that names a Designer on existing work.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

use Blueworx\Forge\Data\Schema;

/**
 * #409. Without a Designer a task skips Design. Every task went through Design
 * before, so open work not yet past it gets the person doing the work as its
 * Designer, and carries on as it was. Work already past Design, and work with
 * an approved Design Not Applicable, is left empty.
 *
 * Run once, from the schema step that adds the column. Only empty seats are
 * filled, so running it again changes nothing already named.
 */
final class DesignerUpgrade {

	/**
	 * Rows written per UPDATE, so a large site batches rather than issuing one
	 * statement per task.
	 */
	private const CHUNK = 500;

	/**
	 * Names the Designers on this site.
	 */
	public static function run(): void {
		global $wpdb;

		$items   = Schema::work_items_table();
		$records = Schema::gate_records_table();

		// Only open work not yet past Design can be a candidate at all; picks()
		// still checks stage, primary and cycle precisely, this just keeps the
		// row set small on a large table.
		$design = Stages::position( 'design-process' );
		$stages = array_merge( array_slice( Stages::ALL, 0, $design + 1 ), array( 'blocked' ) );
		$slots  = implode( ', ', array_fill( 0, count( $stages ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Own tables; names cannot be placeholders; the stage placeholders are counted above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, stage, prior_stage, primary_user_id, designer_id, cycle, archived, terminal_outcome FROM {$items} WHERE designer_id = '' AND archived = 0 AND terminal_outcome = '' AND stage IN ({$slots})",
				$stages
			),
			ARRAY_A
		);
		$skip = $wpdb->get_results( $wpdb->prepare( "SELECT item_id, cycle FROM {$records} WHERE requirement = %s", Gates::DESIGN_NOT_APPLICABLE ), ARRAY_A );

		$picks = self::picks( is_array( $rows ) ? $rows : array(), is_array( $skip ) ? $skip : array() );

		foreach ( array_chunk( $picks, self::CHUNK, true ) as $chunk ) {
			self::apply( $items, $chunk );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Writes one chunk of picks in a single UPDATE, each row set to its own
	 * Designer via CASE. `designer_id = ''` in the WHERE keeps a second run a
	 * no-op even if called again with the same picks.
	 *
	 * @param string                $items Work items table.
	 * @param array<string, string> $picks Task id to Designer, one chunk.
	 */
	private static function apply( string $items, array $picks ): void {
		global $wpdb;

		if ( array() === $picks ) {
			return;
		}

		$cases  = '';
		$values = array();
		$ids    = array_keys( $picks );

		foreach ( $picks as $id => $designer ) {
			$cases   .= ' WHEN %s THEN %s';
			$values[] = $id;
			$values[] = $designer;
		}

		$slots = implode( ', ', array_fill( 0, count( $ids ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Own table; the CASE structure is fixed, only values are interpolated, and all through prepare(); the placeholders are counted above.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$items} SET designer_id = CASE id{$cases} ELSE designer_id END WHERE id IN ({$slots}) AND designer_id = ''",
				array_merge( $values, $ids )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Which tasks get a Designer, and who. Pure.
	 *
	 * @param array<int, array<string, mixed>> $rows           Task rows.
	 * @param array<int, array<string, mixed>> $not_applicable Design Not Applicable records: item_id and cycle.
	 * @return array<string, string> Task id to Designer.
	 */
	public static function picks( array $rows, array $not_applicable ): array {
		$skipped = array();

		foreach ( $not_applicable as $record ) {
			$skipped[ (string) $record['item_id'] . ':' . (int) $record['cycle'] ] = true;
		}

		$design = Stages::position( 'design-process' );
		$picks  = array();

		foreach ( $rows as $row ) {
			$stage   = 'blocked' === (string) $row['stage'] ? (string) $row['prior_stage'] : (string) $row['stage'];
			$primary = (string) $row['primary_user_id'];
			$at      = Stages::position( $stage );

			if ( '' === $primary || '' !== (string) $row['designer_id'] || ! empty( $row['archived'] ) || '' !== (string) $row['terminal_outcome'] ) {
				continue;
			}

			if ( $at < 0 || $at > $design || isset( $skipped[ (string) $row['id'] . ':' . (int) $row['cycle'] ] ) ) {
				continue;
			}

			$picks[ (string) $row['id'] ] = $primary;
		}

		return $picks;
	}
}
