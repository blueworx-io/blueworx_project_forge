<?php
/**
 * Work item records.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Tenancy\Ids;

/**
 * Every rung of WORK-1 in one table (#96, #97).
 *
 * Two rules live here rather than in callers:
 *
 * - **The stage is not writable.** `update()` will not set it whatever it is
 *   handed. Stage changes go through Work\Transition, which is the only place
 *   that also records the move — a stage set by an ordinary edit would move
 *   work with no history of it having moved.
 * - **No delete in the ordinary course of work** (NOTIF-5). Work is cancelled
 *   or archived, and the row stays. The one exception is `delete()`, which only
 *   an administrator can reach: it is for clearing out what should never have
 *   been there, and even it leaves the hour ledger and the notification record
 *   alone, because those say what actually happened.
 */
final class Items {

	/**
	 * Id prefix for a work item.
	 */
	public const PREFIX = 'wrk';

	/**
	 * Stores a new item, at the only stage work may start in.
	 *
	 * @param string               $client_site_id The site it belongs to.
	 * @param string               $client_id      That site's client, denormalised.
	 * @param array<string, mixed> $values         Validated values.
	 * @param int                  $author         WordPress user id of the author.
	 * @return array<string, mixed>|null Null when the insert failed.
	 */
	public static function create( string $client_site_id, string $client_id, array $values, int $author ): ?array {
		global $wpdb;

		$now = bwx_forge_now();

		$row = array_merge(
			self::defaults(),
			RoleHours::seed( self::writable( $values ) ),
			array(
				'id'             => Ids::create( self::PREFIX ),
				// Only the recurring engine names one, at creation and never
				// afterwards: which arrangement this task came from, who does
				// it, and the hours each of them spends.
				'recurring_id'   => (string) ( $values['recurring_id'] ?? '' ),
				'assignees'      => (string) wp_json_encode( array_values( array_map( 'strval', (array) ( $values['assignees'] ?? array() ) ) ) ),
				'hours_each'     => (string) (float) ( $values['hours_each'] ?? 0 ),
				'client_site_id' => $client_site_id,
				'client_id'      => $client_id,
				'stage'          => Stages::FIRST,
				'cycle'          => 1,
				'created_at'     => $now,
				'updated_at'     => $now,
				'created_by'     => $author,
				'record_version' => 1,
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		$inserted = $wpdb->insert( Schema::work_items_table(), $row, Formats::for_row( $row ) );

		if ( ! $inserted ) {
			return null;
		}

		return self::hydrate( $row );
	}

	/**
	 * One item.
	 *
	 * @param string $id Item id.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		global $wpdb;

		$table = Schema::work_items_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * The items on a site, newest first, optionally narrowed.
	 *
	 * Every filter is matched against a fixed list of columns. A caller does not
	 * get to name the column it filters on: that is how a filter becomes a way
	 * to read a column somebody was never meant to query.
	 *
	 * Archived work is left out unless it is asked for (#111). "Hidden from
	 * default views, never from reports" is enforced by the default here rather
	 * than by every caller remembering: a report asks for it explicitly, and a
	 * board never has to.
	 *
	 * @param string               $client_site_id The site.
	 * @param array<string, mixed> $filters        stage, level, work_type,
	 *                                             parent_id, include_archived.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_site( string $client_site_id, array $filters = array() ): array {
		global $wpdb;

		$table  = Schema::work_items_table();
		$where  = array( 'client_site_id = %s' );
		$values = array( $client_site_id );

		foreach ( array( 'stage', 'level', 'work_type', 'parent_id' ) as $column ) {
			if ( ! array_key_exists( $column, $filters ) ) {
				continue;
			}

			$where[]  = $column . ' = %s';
			$values[] = (string) $filters[ $column ];
		}

		if ( empty( $filters['include_archived'] ) ) {
			$where[]  = 'archived = %s';
			$values[] = '0';
		}

		$clause = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name and the WHERE columns are this class's own literals; the placeholders are counted by the loop above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$clause} ORDER BY created_at DESC", $values ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Just enough about several items to name them, keyed by id.
	 *
	 * For lists built from something other than the items themselves — the
	 * in-product signals (#175) are built from the history, and a history entry
	 * knows which item it is about but not what that item is called. Fetching
	 * each one in turn would be a query per row of the list.
	 *
	 * Deliberately not the whole record. A caller that wants an item wants
	 * {@see self::get()}; this exists so a list can print a title without
	 * pulling a hundred rows of everything.
	 *
	 * @param array<int, string> $ids Item ids.
	 * @return array<string, array<string, mixed>> Keyed by id.
	 */
	public static function summaries_for( array $ids ): array {
		global $wpdb;

		$wanted = array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) );

		if ( array() === $wanted ) {
			return array();
		}

		$table = Schema::work_items_table();
		$slots = implode( ', ', array_fill( 0, count( $wanted ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name cannot be a placeholder, and the id placeholders are built above from the ids themselves; every value is still prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, title, stage, level, work_type, client_id, client_site_id FROM {$table} WHERE id IN ({$slots})", $wanted ), ARRAY_A );

		$summaries = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$summaries[ (string) $row['id'] ] = array(
				'id'             => (string) $row['id'],
				'title'          => (string) $row['title'],
				'stage'          => (string) $row['stage'],
				'level'          => (string) $row['level'],
				'work_type'      => (string) $row['work_type'],
				'client_id'      => (string) $row['client_id'],
				'client_site_id' => (string) $row['client_site_id'],
			);
		}

		return $summaries;
	}

	/**
	 * The children of one item.
	 *
	 * @param string $parent_id Parent item id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function children( string $parent_id ): array {
		global $wpdb;

		if ( '' === $parent_id ) {
			return array();
		}

		$table = Schema::work_items_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE parent_id = %s ORDER BY created_at ASC", $parent_id ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Applies an edit, refusing one made against a version that has moved.
	 *
	 * @param string               $id           Item id.
	 * @param array<string, mixed> $values       Validated values.
	 * @param int                  $sent_version Version the edit was made against.
	 * @return array<string, mixed>|null Null when the version did not match.
	 */
	public static function update( string $id, array $values, int $sent_version ): ?array {
		global $wpdb;

		/*
		 * The item is read first so a seeded default cannot overwrite a figure
		 * already on it (CAP-2). One extra read on the rare write that changes
		 * an estimate, against silently re-deciding somebody's review time
		 * every time the estimate moves.
		 */
		$changes = RoleHours::seed( self::writable( $values ), (array) self::get( $id ) );

		if ( array() === $changes ) {
			// Nothing to write. Returning the row rather than a failure: an edit
			// that changes nothing succeeded at changing nothing.
			return self::get( $id );
		}

		$changes['updated_at']     = bwx_forge_now();
		$changes['record_version'] = $sent_version + 1;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$changed = $wpdb->update(
			Schema::work_items_table(),
			$changes,
			array(
				'id'             => $id,
				'record_version' => $sent_version,
			),
			Formats::for_row( $changes ),
			array( '%s', '%d' )
		);

		if ( ! $changed ) {
			return null;
		}

		return self::get( $id );
	}

	/**
	 * Moves an item to a stage. Called only by Work\Transition, which is what
	 * also writes the event — hence the name, and hence its being the one
	 * method here that touches the stage.
	 *
	 * @param string               $id           Item id.
	 * @param string               $stage        The stage moved to.
	 * @param int                  $sent_version Version the move was made against.
	 * @param array<string, mixed> $also         Other columns the move sets.
	 * @return bool Whether the row moved.
	 */
	public static function apply_stage( string $id, string $stage, int $sent_version, array $also = array() ): bool {
		global $wpdb;

		$changes = array_merge(
			$also,
			array(
				'stage'          => $stage,
				'updated_at'     => bwx_forge_now(),
				'record_version' => $sent_version + 1,
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$changed = $wpdb->update(
			Schema::work_items_table(),
			$changes,
			array(
				'id'             => $id,
				'record_version' => $sent_version,
			),
			Formats::for_row( $changes ),
			array( '%s', '%d' )
		);

		return (bool) $changed;
	}

	/**
	 * The columns an edit may set — never the stage, and never the site.
	 *
	 * @param array<string, mixed> $values Validated values.
	 * @return array<string, mixed>
	 */
	private static function writable( array $values ): array {
		$changes = array();

		foreach ( Fields::writable() as $field ) {
			if ( ! array_key_exists( $field, $values ) ) {
				continue;
			}

			$column = 'references' === $field ? 'references_text' : $field;

			// Every field measured in hours goes to the column as a decimal
			// string, so the format map treats them all the same way.
			if ( in_array( $field, array_merge( array( 'remaining_estimate' ), Fields::HOURS ), true ) ) {
				$changes[ $column ] = (string) (float) $values[ $field ];
				continue;
			}

			if ( 'delivered_by_forge' === $field ) {
				$changes[ $column ] = (int) $values[ $field ];
				continue;
			}

			$changes[ $column ] = (string) $values[ $field ];
		}

		return $changes;
	}

	/**
	 * Records one assignee's tick on a recurring task, or takes it back.
	 *
	 * Not versioned: a tick is one person's own answer and cannot collide
	 * with anybody else's, and asking two people who tick within a second to
	 * retry would be asking them to fight over a checkbox.
	 *
	 * @param string $id      Item id.
	 * @param string $user_id Who ticked.
	 * @param bool   $done    Ticked, or not.
	 * @return array<string, mixed>|null The item as it now stands.
	 */
	public static function tick( string $id, string $user_id, bool $done ): ?array {
		global $wpdb;

		$item = self::get( $id );

		if ( null === $item ) {
			return null;
		}

		$ticks = (array) $item['ticks'];

		if ( $done ) {
			$ticks[ $user_id ] = bwx_forge_now();
		} else {
			unset( $ticks[ $user_id ] );
		}

		$changes = array(
			'ticks'      => (string) wp_json_encode( (object) $ticks ),
			'updated_at' => bwx_forge_now(),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update( Schema::work_items_table(), $changes, array( 'id' => $id ), Formats::for_row( $changes ), array( '%s' ) );

		return self::get( $id );
	}

	/**
	 * A stored list of ids.
	 *
	 * @param string $stored JSON, or nothing.
	 * @return array<int, string>
	 */
	private static function ids( string $stored ): array {
		$decoded = '' === $stored ? array() : json_decode( $stored, true );

		return array_values( array_map( 'strval', is_array( $decoded ) ? $decoded : array() ) );
	}

	/**
	 * Who has ticked a recurring task, and when.
	 *
	 * @param string $stored JSON, or nothing.
	 * @return array<string, int>
	 */
	private static function ticks( string $stored ): array {
		$decoded = '' === $stored ? array() : json_decode( $stored, true );
		$out     = array();

		foreach ( is_array( $decoded ) ? $decoded : array() as $user_id => $at ) {
			$out[ (string) $user_id ] = (int) $at;
		}

		return $out;
	}

	/**
	 * The checklist as the column holds it, as rows: text and whether it is done.
	 *
	 * @param string $stored The JSON, or nothing.
	 * @return array<int, array{text: string, done: bool}>
	 */
	private static function checklist( string $stored ): array {
		$decoded = '' === $stored ? array() : json_decode( $stored, true );
		$rows    = array();

		foreach ( is_array( $decoded ) ? $decoded : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$rows[] = array(
				'text' => (string) ( $row['text'] ?? '' ),
				'done' => ! empty( $row['done'] ),
			);
		}

		return $rows;
	}

	/**
	 * What a row holds before anybody writes to it. Spelled out rather than
	 * left to the column defaults, so an insert names every column and
	 * Formats::for_row() can type every one of them.
	 *
	 * @return array<string, mixed>
	 */
	private static function defaults(): array {
		return array(
			'parent_id'                => '',
			'level'                    => Levels::SUB_FEATURE,
			'work_type'                => Types::TASK,
			'title'                    => '',
			'problem'                  => '',
			'scope'                    => '',
			'non_goals'                => '',
			'requirements'             => '',
			'acceptance_criteria'      => '',
			'references_text'          => '',
			'checklist'                => '[]',
			'assignees'                => '[]',
			'ticks'                    => '{}',
			'hours_each'               => '0',
			'prior_stage'              => '',
			'blocked_at'               => 0,
			'blocked_elapsed'          => 0,
			'terminal_outcome'         => '',
			'duplicate_of'             => '',
			'archived'                 => 0,
			'recurring_id'             => '',
			'review_attempt'           => 1,
			'primary_user_id'          => '',
			'reviewer_id'              => '',
			'deliverer_id'             => '',
			'reviewer_substitute_id'   => '',
			'deliverer_substitute_id'  => '',
			'self_reviewed'            => 0,
			'override_used'            => 0,
			'override_reason'          => '',
			'capacity_override_used'   => 0,
			'capacity_override_reason' => '',
			'commercial_class'         => 'unclassified',
			'delivered_by_forge'       => 0,
			'priority'                 => '',
			'planned_start'            => '',
			'planned_due'              => '',
			'review_target'            => '',
			'release_target'           => '',
			'remaining_estimate'       => '0',
			'hours_primary'            => '0',
			'hours_review'             => '0',
			'hours_delivery'           => '0',
			'release_method'           => '',
			'release_destination'      => '',
		);
	}

	/**
	 * Turns a database row into the record the rest of the plugin uses.
	 *
	 * @param array<string, mixed> $row Row as stored.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		return array(
			'id'                       => (string) $row['id'],
			'client_site_id'           => (string) $row['client_site_id'],
			'client_id'                => (string) $row['client_id'],
			'parent_id'                => (string) $row['parent_id'],
			'level'                    => (string) $row['level'],
			'level_label'              => Levels::label( (string) $row['level'] ),
			'work_type'                => (string) $row['work_type'],
			'work_type_label'          => Types::label( (string) $row['work_type'] ),
			'title'                    => (string) $row['title'],
			'problem'                  => (string) $row['problem'],
			'scope'                    => (string) $row['scope'],
			'non_goals'                => (string) $row['non_goals'],
			'requirements'             => (string) $row['requirements'],
			'acceptance_criteria'      => (string) $row['acceptance_criteria'],
			'references'               => (string) $row['references_text'],
			'checklist'                => self::checklist( (string) ( $row['checklist'] ?? '' ) ),
			'stage'                    => (string) $row['stage'],
			'stage_label'              => Stages::label( (string) $row['stage'] ),
			'prior_stage'              => (string) $row['prior_stage'],
			'blocked_at'               => (int) $row['blocked_at'],
			'blocked_elapsed'          => (int) $row['blocked_elapsed'],
			'terminal_outcome'         => (string) $row['terminal_outcome'],
			'terminal_label'           => Outcomes::label( (string) $row['terminal_outcome'] ),
			'duplicate_of'             => (string) $row['duplicate_of'],
			'archived'                 => (bool) $row['archived'],
			'recurring_id'             => (string) ( $row['recurring_id'] ?? '' ),
			'assignees'                => self::ids( (string) ( $row['assignees'] ?? '' ) ),
			'ticks'                    => self::ticks( (string) ( $row['ticks'] ?? '' ) ),
			'hours_each'               => (float) ( $row['hours_each'] ?? 0 ),
			'review_attempt'           => (int) $row['review_attempt'],
			'primary_user_id'          => (string) $row['primary_user_id'],
			'reviewer_id'              => (string) $row['reviewer_id'],
			'deliverer_id'             => (string) $row['deliverer_id'],
			'reviewer_substitute_id'   => (string) $row['reviewer_substitute_id'],
			'deliverer_substitute_id'  => (string) $row['deliverer_substitute_id'],
			'cycle'                    => (int) $row['cycle'],
			'self_reviewed'            => (bool) $row['self_reviewed'],
			'override_used'            => (bool) $row['override_used'],
			'override_reason'          => (string) $row['override_reason'],
			'capacity_override_used'   => (bool) ( $row['capacity_override_used'] ?? 0 ),
			'capacity_override_reason' => (string) ( $row['capacity_override_reason'] ?? '' ),
			'commercial_class'         => (string) $row['commercial_class'],
			'delivered_by_forge'       => (bool) $row['delivered_by_forge'],
			'priority'                 => (string) $row['priority'],
			'planned_start'            => (string) $row['planned_start'],
			'planned_due'              => (string) $row['planned_due'],
			'review_target'            => (string) $row['review_target'],
			'release_target'           => (string) $row['release_target'],
			'remaining_estimate'       => (float) $row['remaining_estimate'],
			'hours_primary'            => (float) ( $row['hours_primary'] ?? 0 ),
			'hours_review'             => (float) ( $row['hours_review'] ?? 0 ),
			'hours_delivery'           => (float) ( $row['hours_delivery'] ?? 0 ),
			'release_method'           => (string) $row['release_method'],
			'release_destination'      => (string) $row['release_destination'],
			'created_at'               => (int) $row['created_at'],
			'updated_at'               => (int) $row['updated_at'],
			'created_by'               => (int) $row['created_by'],
			'record_version'           => (int) $row['record_version'],
		);
	}

	/**
	 * Every open item a person holds a seat on, across every site.
	 *
	 * For the morning Slack message, which is about one person rather than
	 * one site. Open means not archived, not ended, not released.
	 *
	 * @param string $user_id Forge person id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function held_by( string $user_id ): array {
		global $wpdb;

		if ( '' === $user_id ) {
			return array();
		}

		$table = Schema::work_items_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
				"SELECT * FROM {$table} WHERE ( primary_user_id = %s OR reviewer_id = %s OR deliverer_id = %s OR assignees LIKE %s ) AND archived = 0 AND terminal_outcome = '' AND stage <> %s ORDER BY planned_due ASC, created_at ASC",
				$user_id,
				$user_id,
				$user_id,
				'%' . $wpdb->esc_like( '"' . $user_id . '"' ) . '%',
				Stages::RELEASED
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * The order to remove an item and everything under it: children first,
	 * the item itself last.
	 *
	 * Pure, over `[ id, parent_id ]` rows, so the order can be tested without a
	 * database. Children before parents means a delete that stops halfway
	 * leaves a parent that can be tried again, never an orphan nothing lists.
	 *
	 * @param array<int, array<string, mixed>> $rows Every item on the site, id and parent_id.
	 * @param string                           $root The item being deleted.
	 * @return array<int, string> Ids, deepest first.
	 */
	public static function delete_order( array $rows, string $root ): array {
		$children = array();

		foreach ( $rows as $row ) {
			$children[ (string) $row['parent_id'] ][] = (string) $row['id'];
		}

		$order = array();
		$walk  = static function ( string $id ) use ( &$walk, &$order, $children ): void {
			foreach ( $children[ $id ] ?? array() as $child ) {
				$walk( $child );
			}

			$order[] = $id;
		};

		$walk( $root );

		return $order;
	}

	/**
	 * Removes an item and everything under it, for good.
	 *
	 * Administrators only, and the route is the only caller. Comments, history,
	 * gate records and dependencies go with each item; a request that was
	 * converted into it is left in place with the link cleared, so the request
	 * itself is still on record. The hour ledger and the notification register
	 * are untouched.
	 *
	 * @param string $id The item.
	 * @return int How many items were removed, 0 when there was no such item.
	 */
	public static function delete( string $id ): int {
		global $wpdb;

		$item = self::get( $id );

		if ( null === $item ) {
			return 0;
		}

		$table = Schema::work_items_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, parent_id FROM {$table} WHERE client_site_id = %s", (string) $item['client_site_id'] ), ARRAY_A );

		$removed = 0;

		foreach ( self::delete_order( is_array( $rows ) ? $rows : array(), $id ) as $doomed ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own tables; there is no core API for them.
			$wpdb->delete( Schema::dependencies_table(), array( 'item_id' => $doomed ), array( '%s' ) );
			$wpdb->delete( Schema::dependencies_table(), array( 'depends_on_id' => $doomed ), array( '%s' ) );
			$wpdb->delete( Schema::work_events_table(), array( 'item_id' => $doomed ), array( '%s' ) );
			$wpdb->delete( Schema::gate_records_table(), array( 'item_id' => $doomed ), array( '%s' ) );
			$wpdb->delete( Schema::comments_table(), array( 'item_id' => $doomed ), array( '%s' ) );
			$wpdb->update( Schema::submissions_table(), array( 'converted_item_id' => '' ), array( 'converted_item_id' => $doomed ), array( '%s' ), array( '%s' ) );

			if ( false !== $wpdb->delete( $table, array( 'id' => $doomed ), array( '%s' ) ) ) {
				++$removed;
			}
			// phpcs:enable
		}

		return $removed;
	}
}
