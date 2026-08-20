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
	 * The item was sent back to a stage it had occupied (#108).
	 */
	public const RETURNED = 'returned';

	/**
	 * The item was paused, and where it came from stored (#109).
	 */
	public const BLOCKED = 'blocked';

	/**
	 * The blocker was resolved and the item put back (#109).
	 */
	public const UNBLOCKED = 'unblocked';

	/**
	 * The item ended at one of the WF-2 outcomes (#111).
	 */
	public const ENDED = 'ended';

	/**
	 * The item was put out of the default views (#111).
	 */
	public const ARCHIVED = 'archived';

	/**
	 * Finished work was picked up again, as a new cycle (#113).
	 */
	public const REOPENED = 'reopened';

	/**
	 * The workflow was gone round by the Primary administrator (#114).
	 */
	public const OVERRIDDEN = 'overridden';

	/**
	 * Done by somebody standing in for the person the item names (AUTH-4).
	 */
	public const VIA_SUBSTITUTE = 'substitute';

	/**
	 * Done through the WF-5 override.
	 */
	public const VIA_OVERRIDE = 'override';

	/**
	 * Appends an entry.
	 *
	 * @param array<string, mixed> $entry item_id, client_site_id, action, and
	 *                                    optionally from_stage, to_stage, gate,
	 *                                    reason, detail, outcome, cycle,
	 *                                    attempt, actor.
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
			'outcome'        => (string) ( $entry['outcome'] ?? '' ),

			/*
			 * How the actor was entitled to do this, where it was not simply
			 * their own authority: standing in for somebody (AUTH-4), or the
			 * WF-5 override. Blank is the ordinary case, and that is why it is
			 * a column rather than a note in the reason — "which of these were
			 * done by a substitute" has to be a query.
			 */
			'via'            => (string) ( $entry['via'] ?? '' ),
			// Bounded because it lands in a varchar and comes from a person
			// typing a reason into a box.
			'reason'         => mb_substr( (string) ( $entry['reason'] ?? '' ), 0, 191 ),

			/*
			 * Whatever the action needs beyond a reason: a reviewer's feedback,
			 * the next action on a blocker, the surviving item a duplicate
			 * points at. It is a text column because review feedback is the one
			 * thing here somebody genuinely writes paragraphs of, and truncating
			 * that would throw away the part the developer needs.
			 */
			'detail'         => (string) ( $entry['detail'] ?? '' ),
			'cycle'          => max( 1, (int) ( $entry['cycle'] ?? 1 ) ),
			'attempt'        => max( 1, (int) ( $entry['attempt'] ?? 1 ) ),
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
					'outcome'     => (string) $row['outcome'],
					'via'         => (string) $row['via'],
					'reason'      => (string) $row['reason'],
					'detail'      => (string) $row['detail'],
					'cycle'       => (int) $row['cycle'],
					'attempt'     => (int) $row['attempt'],
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
