<?php
/**
 * What happened to a work item.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Tenancy\Ids;

/**
 * Append-only, and small on purpose. #99 turns this into the full changelog —
 * every field change, not just stage moves — and it exists now because #106
 * promises a move is recorded atomically with the move itself, which is not a
 * promise anybody can keep without somewhere for the record to go.
 *
 * There is no update and no delete. A mistaken action is corrected by a further
 * action, which appends its own entry (the immutability rules in
 * docs/architecture/data-model.md). That is the whole design: an audit trail
 * somebody can edit is not one.
 */
final class Events {

	/**
	 * Id prefix for an event.
	 */
	public const PREFIX = 'evt';

	/**
	 * The item was created.
	 */
	public const CREATED = 'created';

	/**
	 * The item moved from one stage to another.
	 */
	public const MOVED = 'moved';

	/**
	 * Appends an entry.
	 *
	 * @param array<string, mixed> $entry item_id, client_site_id, action, and
	 *                                    optionally from_stage, to_stage, gate,
	 *                                    reason, actor.
	 * @return bool Whether it was written.
	 */
	public static function append( array $entry ): bool {
		global $wpdb;

		$row = array(
			'id'             => Ids::create( self::PREFIX ),
			'item_id'        => (string) ( $entry['item_id'] ?? '' ),
			'client_site_id' => (string) ( $entry['client_site_id'] ?? '' ),
			'action'         => (string) ( $entry['action'] ?? '' ),
			'from_stage'     => (string) ( $entry['from_stage'] ?? '' ),
			'to_stage'       => (string) ( $entry['to_stage'] ?? '' ),
			'gate'           => (string) ( $entry['gate'] ?? '' ),
			// Bounded because it lands in a varchar and comes from a person
			// typing a reason into a box.
			'reason'         => mb_substr( (string) ( $entry['reason'] ?? '' ), 0, 191 ),
			'actor'          => (int) ( $entry['actor'] ?? 0 ),
			'occurred_at'    => bwx_forge_now(),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		return (bool) $wpdb->insert( Schema::work_events_table(), $row, Formats::for_row( $row ) );
	}

	/**
	 * One item's history, oldest first — the order it happened in, which is the
	 * order anybody reading it wants.
	 *
	 * @param string $item_id Item id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_item( string $item_id ): array {
		global $wpdb;

		$table = Schema::work_events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE item_id = %s ORDER BY occurred_at ASC, id ASC", $item_id ), ARRAY_A );

		return array_map(
			static function ( array $row ): array {
				return array(
					'id'          => (string) $row['id'],
					'item_id'     => (string) $row['item_id'],
					'action'      => (string) $row['action'],
					'from_stage'  => (string) $row['from_stage'],
					'to_stage'    => (string) $row['to_stage'],
					'gate'        => (string) $row['gate'],
					'reason'      => (string) $row['reason'],
					'actor'       => (int) $row['actor'],
					'occurred_at' => (int) $row['occurred_at'],
				);
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * How many entries an item has. Used by the tests that prove a refused move
	 * left nothing behind.
	 *
	 * @param string $item_id Item id.
	 * @return int
	 */
	public static function count_for_item( string $item_id ): int {
		global $wpdb;

		$table = Schema::work_events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE item_id = %s", $item_id ) );
	}
}
