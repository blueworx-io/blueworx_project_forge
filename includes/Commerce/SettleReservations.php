<?php
/**
 * The one-off step that gives back hours held twice before #568.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Commerce;

use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Work\Items;

/**
 * #568. Before the fix, work sent back to Up Next or Design after it started
 * reserved its hours again although they were already spent, and a client's
 * "Left" read low for as long as it sat there. New moves no longer do that,
 * but a task already sitting back there kept its doubled reservation until
 * somebody next moved or edited it.
 *
 * Run once, from the schema step that ships the fix: every work item that
 * has spent hours and still holds some is reconciled, which appends a
 * release. Nothing written is changed. A second run finds nothing to do,
 * because a reconciled item holds nothing.
 */
final class SettleReservations {

	/**
	 * Reconciles every work item holding hours it has also spent.
	 *
	 * The ledger refuses an entry with nobody behind it, so the first
	 * administrator is named as the actor; a site with none is left alone.
	 */
	public static function run(): void {
		global $wpdb;

		$actor = self::administrator();

		if ( 0 === $actor ) {
			return;
		}

		$table = Schema::hour_ledger_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; the name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT source_id, event_type, hours FROM {$table} WHERE source_type = %s", WorkHours::SOURCE ), ARRAY_A );

		foreach ( self::sources( is_array( $rows ) ? $rows : array() ) as $item_id ) {
			$item = Items::get( $item_id );

			if ( null !== $item ) {
				WorkLedger::reconcile( $item, $actor );
			}
		}
	}

	/**
	 * Which work items have spent hours and still hold some. Pure, so a test
	 * can say which are picked.
	 *
	 * @param array<int, array<string, mixed>> $entries Work-item ledger entries: source_id, event_type, hours.
	 * @return array<int, string> Item ids.
	 */
	public static function sources( array $entries ): array {
		$by_item = array();

		foreach ( $entries as $entry ) {
			$id = (string) ( $entry['source_id'] ?? '' );

			if ( '' !== $id ) {
				$by_item[ $id ][] = $entry;
			}
		}

		$picked = array();

		foreach ( $by_item as $id => $group ) {
			$position = WorkHours::position( $group );

			if ( $position['used'] > 0 && $position['reserved'] > 0 ) {
				$picked[] = (string) $id;
			}
		}

		return $picked;
	}

	/**
	 * The first administrator's user id, or 0 when the site has none.
	 *
	 * @return int
	 */
	private static function administrator(): int {
		$ids = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'fields'  => 'ID',
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		return (int) ( $ids[0] ?? 0 );
	}
}
