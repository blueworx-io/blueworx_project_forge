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
	 * Somebody was over-booked, deliberately and with a reason (CAP-4, #143).
	 *
	 * Its own action rather than an OVERRIDDEN with a note, so "how often are
	 * we over-committing people" is a query. It is the question the capacity
	 * report exists to answer, and a report that has to read reasons to answer
	 * it is a report nobody runs.
	 */
	public const OVER_ALLOCATED = 'over-allocated';

	/**
	 * A field was changed (#99).
	 */
	public const EDITED = 'edited';

	/**
	 * An earlier entry was wrong, and this one says so.
	 *
	 * The only way a mistake in the log is put right. Nothing here is ever
	 * changed, so a correction is a further entry — which is also the more
	 * useful record, because "somebody got this wrong on Tuesday and fixed it on
	 * Thursday" is a fact worth keeping.
	 */
	public const CORRECTED = 'corrected';

	/**
	 * This work is what a client's request became (#132).
	 *
	 * Recorded on the work rather than only on the request, and that direction
	 * is the point. The submission already carries the link forward — the
	 * client reads "this became" on their own site — but somebody looking at a
	 * card six months later wants the other direction: why does this exist, and
	 * who asked for it. An entry here answers that from the item's own history
	 * instead of from a join nobody thinks to make.
	 */
	public const CONVERTED = 'converted';

	/**
	 * Work was made to wait on other work (#103).
	 */
	public const DEPENDENCY_ADDED = 'dependency-added';

	/**
	 * It stopped waiting.
	 */
	public const DEPENDENCY_REMOVED = 'dependency-removed';

	/**
	 * Every action an entry can record.
	 *
	 * Listed so a reader can see the whole vocabulary at once, and so the test
	 * that proves a correction is possible has something to check against.
	 */
	public const ACTIONS = array(
		self::CREATED,
		self::EDITED,
		self::MOVED,
		self::RETURNED,
		self::BLOCKED,
		self::UNBLOCKED,
		self::ENDED,
		self::ARCHIVED,
		self::REOPENED,
		self::OVERRIDDEN,
		self::OVER_ALLOCATED,
		self::CORRECTED,
		self::CONVERTED,
		self::DEPENDENCY_ADDED,
		self::DEPENDENCY_REMOVED,
	);

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

			/*
			 * #99. Which field changed and both sides of the change, so an entry
			 * answers "what was it before" on its own rather than by somebody
			 * replaying the whole history to work it out.
			 */
			'field'            => (string) ( $entry['field'] ?? '' ),
			'previous_value'   => Changelog::render( $entry['previous_value'] ?? '' ),
			'new_value'        => Changelog::render( $entry['new_value'] ?? '' ),

			/*
			 * Which interface it came from. The same edit made by us and made by
			 * the client are different facts, and nothing else in the row can say
			 * which — the actor is a person, and a person can be on either side.
			 */
			'source_interface' => (string) ( $entry['source_interface'] ?? '' ),

			/*
			 * The site's timezone at the time, stored with the entry rather than
			 * looked up when it is read. A client that moves timezone would
			 * otherwise rewrite when every past event appears to have happened.
			 */
			'timezone'         => (string) ( $entry['timezone'] ?? '' ),
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
					'via'              => (string) $row['via'],
					'field'            => (string) ( $row['field'] ?? '' ),
					'previous_value'   => (string) ( $row['previous_value'] ?? '' ),
					'new_value'        => (string) ( $row['new_value'] ?? '' ),
					'source_interface' => (string) ( $row['source_interface'] ?? '' ),
					'timezone'         => (string) ( $row['timezone'] ?? '' ),
					'reason'           => (string) $row['reason'],
					'detail'           => (string) $row['detail'],
					'cycle'            => (int) $row['cycle'],
					'attempt'          => (int) $row['attempt'],
					'actor'            => (int) $row['actor'],
					'occurred_at'      => (int) $row['occurred_at'],
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

	/**
	 * Whether anything has ever gone live on this site (#166).
	 *
	 * Asked of the history rather than of the items, and the difference matters:
	 * an item that was released and later archived has still been released, and
	 * a site that went live a year ago is live whatever its board looks like
	 * today. The current stage of anything answers a different question.
	 *
	 * @param string $client_site_id The site.
	 * @param string $stage          The stage that counts as live.
	 * @return bool
	 */
	public static function has_ever_reached( string $client_site_id, string $stage ): bool {
		global $wpdb;

		if ( '' === $client_site_id || '' === $stage ) {
			return false;
		}

		$table = Schema::work_events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE client_site_id = %s AND to_stage = %s LIMIT 1", $client_site_id, $stage ) );

		return null !== $found && '' !== (string) $found;
	}
}
