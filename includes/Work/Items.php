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
 * - **No delete** (NOTIF-5). Work is cancelled or archived, and the row stays,
 *   because a deleted item takes its ledger entries and its changelog with it.
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
			self::writable( $values ),
			array(
				'id'             => Ids::create( self::PREFIX ),
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table name and the WHERE columns are this class's own literals; the placeholders are counted by the loop above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$clause} ORDER BY created_at DESC", $values ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
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

		$changes = self::writable( $values );

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

			if ( 'remaining_estimate' === $field ) {
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
	 * What a row holds before anybody writes to it. Spelled out rather than
	 * left to the column defaults, so an insert names every column and
	 * Formats::for_row() can type every one of them.
	 *
	 * @return array<string, mixed>
	 */
	private static function defaults(): array {
		return array(
			'parent_id'               => '',
			'level'                   => Levels::SUB_FEATURE,
			'work_type'               => Types::TASK,
			'title'                   => '',
			'problem'                 => '',
			'scope'                   => '',
			'non_goals'               => '',
			'requirements'            => '',
			'acceptance_criteria'     => '',
			'references_text'         => '',
			'prior_stage'             => '',
			'blocked_at'              => 0,
			'blocked_elapsed'         => 0,
			'terminal_outcome'        => '',
			'duplicate_of'            => '',
			'archived'                => 0,
			'review_attempt'          => 1,
			'primary_user_id'         => '',
			'reviewer_id'             => '',
			'deliverer_id'            => '',
			'reviewer_substitute_id'  => '',
			'deliverer_substitute_id' => '',
			'self_reviewed'           => 0,
			'override_used'           => 0,
			'override_reason'         => '',
			'commercial_class'        => 'unclassified',
			'delivered_by_forge'      => 0,
			'priority'                => '',
			'planned_start'           => '',
			'planned_due'             => '',
			'review_target'           => '',
			'release_target'          => '',
			'remaining_estimate'      => '0',
			'release_method'          => '',
			'release_destination'     => '',
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
			'id'                      => (string) $row['id'],
			'client_site_id'          => (string) $row['client_site_id'],
			'client_id'               => (string) $row['client_id'],
			'parent_id'               => (string) $row['parent_id'],
			'level'                   => (string) $row['level'],
			'level_label'             => Levels::label( (string) $row['level'] ),
			'work_type'               => (string) $row['work_type'],
			'work_type_label'         => Types::label( (string) $row['work_type'] ),
			'title'                   => (string) $row['title'],
			'problem'                 => (string) $row['problem'],
			'scope'                   => (string) $row['scope'],
			'non_goals'               => (string) $row['non_goals'],
			'requirements'            => (string) $row['requirements'],
			'acceptance_criteria'     => (string) $row['acceptance_criteria'],
			'references'              => (string) $row['references_text'],
			'stage'                   => (string) $row['stage'],
			'stage_label'             => Stages::label( (string) $row['stage'] ),
			'prior_stage'             => (string) $row['prior_stage'],
			'blocked_at'              => (int) $row['blocked_at'],
			'blocked_elapsed'         => (int) $row['blocked_elapsed'],
			'terminal_outcome'        => (string) $row['terminal_outcome'],
			'terminal_label'          => Outcomes::label( (string) $row['terminal_outcome'] ),
			'duplicate_of'            => (string) $row['duplicate_of'],
			'archived'                => (bool) $row['archived'],
			'review_attempt'          => (int) $row['review_attempt'],
			'primary_user_id'         => (string) $row['primary_user_id'],
			'reviewer_id'             => (string) $row['reviewer_id'],
			'deliverer_id'            => (string) $row['deliverer_id'],
			'reviewer_substitute_id'  => (string) $row['reviewer_substitute_id'],
			'deliverer_substitute_id' => (string) $row['deliverer_substitute_id'],
			'cycle'                   => (int) $row['cycle'],
			'self_reviewed'           => (bool) $row['self_reviewed'],
			'override_used'           => (bool) $row['override_used'],
			'override_reason'         => (string) $row['override_reason'],
			'commercial_class'        => (string) $row['commercial_class'],
			'delivered_by_forge'      => (bool) $row['delivered_by_forge'],
			'priority'                => (string) $row['priority'],
			'planned_start'           => (string) $row['planned_start'],
			'planned_due'             => (string) $row['planned_due'],
			'review_target'           => (string) $row['review_target'],
			'release_target'          => (string) $row['release_target'],
			'remaining_estimate'      => (float) $row['remaining_estimate'],
			'release_method'          => (string) $row['release_method'],
			'release_destination'     => (string) $row['release_destination'],
			'created_at'              => (int) $row['created_at'],
			'updated_at'              => (int) $row['updated_at'],
			'created_by'              => (int) $row['created_by'],
			'record_version'          => (int) $row['record_version'],
		);
	}
}
