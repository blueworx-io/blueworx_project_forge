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
	 * Names the Designers on this site.
	 */
	public static function run(): void {
		global $wpdb;

		$items   = Schema::work_items_table();
		$records = Schema::gate_records_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own tables; names cannot be placeholders.
		$rows = $wpdb->get_results( "SELECT id, stage, prior_stage, primary_user_id, designer_id, cycle, archived, terminal_outcome FROM {$items} WHERE designer_id = ''", ARRAY_A );
		$skip = $wpdb->get_results( $wpdb->prepare( "SELECT item_id, cycle FROM {$records} WHERE requirement = %s", Gates::DESIGN_NOT_APPLICABLE ), ARRAY_A );

		foreach ( self::picks( is_array( $rows ) ? $rows : array(), is_array( $skip ) ? $skip : array() ) as $id => $designer ) {
			$wpdb->update( $items, array( 'designer_id' => $designer ), array( 'id' => $id ), array( '%s' ), array( '%s' ) );
		}
		// phpcs:enable
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
