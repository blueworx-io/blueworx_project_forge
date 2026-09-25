<?php
/**
 * Recurring task definitions.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Recurring;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Tenancy\Ids;

/**
 * A source is the arrangement — "backups, every Monday, Sam does it, an hour"
 * — never the tasks. The tasks are ordinary work items that Materialise makes
 * from it, one per due day, and each one remembers which source made it.
 *
 * Two kinds share the table. A `schedule` is one somebody typed in. A
 * `subscription` is one the SureCart sync keeps in step with a subscription's
 * renewal date, named by `source_ref`. Both produce tasks the same way, which
 * is the point of them being one table.
 *
 * A source is ended rather than deleted, so the tasks it made keep a source
 * to point back to. Every update quotes the version it was made against, as
 * every other record here does (ARCH-5).
 */
final class Sources {

	/**
	 * Id prefix for a recurring source.
	 */
	public const PREFIX = 'rec';

	/**
	 * Typed in by a person.
	 */
	public const SCHEDULE = 'schedule';

	/**
	 * Kept in step with a SureCart subscription.
	 */
	public const SUBSCRIPTION = 'subscription';

	/**
	 * A task on a fixed day or period (2026-09-25): no rule, made once.
	 */
	public const REMINDER = 'reminder';

	/**
	 * Id prefix for a reminder, so its copies are known by their source id.
	 */
	public const REMINDER_PREFIX = 'rem';

	/**
	 * Producing tasks.
	 */
	public const ACTIVE = 'active';

	/**
	 * Skipping due days until resumed.
	 */
	public const PAUSED = 'paused';

	/**
	 * Finished with, kept for the record.
	 */
	public const ENDED = 'ended';

	/**
	 * The columns a caller may set.
	 */
	private const WRITABLE = array(
		'title',
		'description',
		'work_type',
		'primary_user_id',
		'reviewer_id',
		'deliverer_id',
		'hours_primary',
		'hours_review',
		'hours_delivery',
		'assignees',
		'hours_each',
		'checklist',
		'rule',
		'starts_on',
		'ends_on',
		'status',
		'source_ref',
		'client_site_id',
		'client_id',
	);

	/**
	 * Stores a new source. Its first due date is worked out from the rule and
	 * its start.
	 *
	 * @param string               $client_site_id The site its tasks go on.
	 * @param string               $client_id      That site's client.
	 * @param string               $kind           SCHEDULE or SUBSCRIPTION.
	 * @param array<string, mixed> $values         Validated values.
	 * @param int                  $author         WordPress user id, 0 for the system.
	 * @return array<string, mixed>|null
	 */
	public static function create( string $client_site_id, string $client_id, string $kind, array $values, int $author ): ?array {
		global $wpdb;

		$now = bwx_forge_now();
		$row = array_merge(
			array(
				'title'           => '',
				'description'     => '',
				'work_type'       => 'task',
				'primary_user_id' => '',
				'reviewer_id'     => '',
				'deliverer_id'    => '',
				'hours_primary'   => '0',
				'hours_review'    => '0',
				'hours_delivery'  => '0',
				'assignees'       => '[]',
				'hours_each'      => '0',
				'checklist'       => '[]',
				'rule'            => '{"every":"day"}',
				'starts_on'       => wp_date( 'Y-m-d' ),
				'ends_on'         => '',
				'status'          => self::ACTIVE,
				'source_ref'      => '',
			),
			self::writable( $values )
		);

		$row['id']             = Ids::create( self::REMINDER === $kind ? self::REMINDER_PREFIX : self::PREFIX );
		$row['kind']           = $kind;
		$row['client_site_id'] = $client_site_id;
		$row['client_id']      = $client_id;
		// A reminder has no next day: Reminders makes its copies when it is saved.
		$row['next_due']        = self::REMINDER === $kind ? '' : Rule::next_on_or_after( (array) json_decode( (string) $row['rule'], true ), (string) $row['starts_on'] );
		$row['last_created_at'] = 0;
		$row['created_at']      = $now;
		$row['updated_at']      = $now;
		$row['created_by']      = $author;
		$row['record_version']  = 1;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$inserted = $wpdb->insert( Schema::recurring_table(), $row, Formats::for_row( $row ) );

		return $inserted ? self::hydrate( $row ) : null;
	}

	/**
	 * One source.
	 *
	 * @param string $id Source id.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		global $wpdb;

		$table = Schema::recurring_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * The source kept in step with an outside record, if there is one.
	 *
	 * @param string $source_ref The outside id.
	 * @return array<string, mixed>|null
	 */
	public static function by_source_ref( string $source_ref ): ?array {
		global $wpdb;

		$table = Schema::recurring_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_ref = %s ORDER BY created_at DESC LIMIT 1", $source_ref ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * The sources on a site, newest first.
	 *
	 * @param string      $client_site_id The site.
	 * @param string|null $kind           One kind, or null for both.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_site( string $client_site_id, ?string $kind = null ): array {
		global $wpdb;

		$table = Schema::recurring_table();

		if ( null === $kind ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_site_id = %s AND status <> %s ORDER BY created_at DESC", $client_site_id, self::ENDED ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_site_id = %s AND kind = %s AND status <> %s ORDER BY created_at DESC", $client_site_id, $kind, self::ENDED ), ARRAY_A );
		}

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * The sources of some kinds on some sites, newest first.
	 *
	 * @param array<int, string> $site_ids Sites.
	 * @param array<int, string> $kinds    Kinds.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_sites( array $site_ids, array $kinds ): array {
		global $wpdb;

		$site_ids = array_values( array_unique( array_filter( array_map( 'strval', $site_ids ) ) ) );
		$kinds    = array_values( array_unique( array_filter( array_map( 'strval', $kinds ) ) ) );

		if ( array() === $site_ids || array() === $kinds ) {
			return array();
		}

		$table = Schema::recurring_table();
		$sites = implode( ', ', array_fill( 0, count( $site_ids ), '%s' ) );
		$slots = implode( ', ', array_fill( 0, count( $kinds ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name cannot be a placeholder; the slots are counted above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_site_id IN ({$sites}) AND kind IN ({$slots}) AND status <> %s ORDER BY created_at DESC", array_merge( $site_ids, $kinds, array( self::ENDED ) ) ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Every running schedule, for the days ahead (2026-09-19): what the
	 * capacity read counts before the tasks exist.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function running(): array {
		global $wpdb;

		$table = Schema::recurring_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s AND kind = %s AND hours_each > 0", self::ACTIVE, self::SCHEDULE ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Every source with a due day that has arrived, paused ones included —
	 * a paused source still needs its date moved along.
	 *
	 * @param string $today YYYY-MM-DD.
	 * @return array<int, array<string, mixed>>
	 */
	public static function due( string $today ): array {
		global $wpdb;

		$table = Schema::recurring_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status <> %s AND next_due <> '' AND next_due <= %s ORDER BY next_due ASC", self::ENDED, $today ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Changes a source, against the version the caller saw.
	 *
	 * A changed rule or start moves the next due date along with it, so an
	 * edit made on Tuesday to "every Thursday" is due on Thursday and not on
	 * whatever the old rule had lined up.
	 *
	 * @param string               $id           Source id.
	 * @param array<string, mixed> $values       Validated values.
	 * @param int                  $sent_version Version the caller saw.
	 * @return array<string, mixed>|null Null when stale or absent.
	 */
	public static function update( string $id, array $values, int $sent_version ): ?array {
		global $wpdb;

		$current = self::get( $id );

		if ( null === $current ) {
			return null;
		}

		$changes = self::writable( $values );

		if ( self::REMINDER !== (string) $current['kind'] && ( isset( $changes['rule'] ) || isset( $changes['starts_on'] ) ) ) {
			$rule  = isset( $changes['rule'] ) ? (array) json_decode( (string) $changes['rule'], true ) : $current['rule'];
			$from  = max( (string) ( $changes['starts_on'] ?? $current['starts_on'] ), wp_date( 'Y-m-d' ) );
			$after = $current['next_due'];

			// Only ever forward: a rule change must not re-create days that
			// have already been made.
			$changes['next_due'] = max( $after, Rule::next_on_or_after( $rule, $from ) );
		}

		$changes['updated_at']     = bwx_forge_now();
		$changes['record_version'] = $sent_version + 1;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$changed = $wpdb->update(
			Schema::recurring_table(),
			$changes,
			array(
				'id'             => $id,
				'record_version' => $sent_version,
			),
			Formats::for_row( $changes ),
			array( '%s', '%d' )
		);

		return $changed ? self::get( $id ) : null;
	}

	/**
	 * Moves a source's next due date, after its due days have been dealt with.
	 *
	 * Not versioned: this is the engine's own bookkeeping, and the engine is
	 * the only writer of this column. An empty next date ends a schedule —
	 * it ran past its end — but leaves a subscription source open with no
	 * date, waiting for the next sync to pin the next renewal.
	 *
	 * @param string $id       Source id.
	 * @param string $next_due YYYY-MM-DD, or empty when there is no next.
	 * @param bool   $created  Whether a task was made this time.
	 * @param bool   $end      Whether an empty next date ends the source.
	 */
	public static function advance( string $id, string $next_due, bool $created, bool $end = true ): void {
		global $wpdb;

		$changes = array(
			'next_due'   => $next_due,
			'updated_at' => bwx_forge_now(),
		);

		if ( $created ) {
			$changes['last_created_at'] = bwx_forge_now();
		}

		if ( '' === $next_due && $end ) {
			$changes['status'] = self::ENDED;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update( Schema::recurring_table(), $changes, array( 'id' => $id ), Formats::for_row( $changes ), array( '%s' ) );
	}

	/**
	 * Pins a source's next due date to a date decided elsewhere — a
	 * subscription's renewal date, which SureCart knows and no rule can
	 * work out. Never moves a date back past a day already made.
	 *
	 * @param string $id       Source id.
	 * @param string $next_due YYYY-MM-DD.
	 */
	public static function pin( string $id, string $next_due ): void {
		global $wpdb;

		$changes = array(
			'next_due'   => $next_due,
			'status'     => self::ACTIVE,
			'updated_at' => bwx_forge_now(),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update( Schema::recurring_table(), $changes, array( 'id' => $id ), Formats::for_row( $changes ), array( '%s' ) );
	}

	/**
	 * Ends a source. Its tasks stay.
	 *
	 * @param string $id Source id.
	 */
	public static function end( string $id ): void {
		global $wpdb;

		$changes = array(
			'status'     => self::ENDED,
			'updated_at' => bwx_forge_now(),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update( Schema::recurring_table(), $changes, array( 'id' => $id ), Formats::for_row( $changes ), array( '%s' ) );
	}

	/**
	 * Only the writable columns, as strings for the row.
	 *
	 * @param array<string, mixed> $values Validated values.
	 * @return array<string, string>
	 */
	private static function writable( array $values ): array {
		$row = array();

		foreach ( self::WRITABLE as $column ) {
			if ( ! array_key_exists( $column, $values ) ) {
				continue;
			}

			$row[ $column ] = in_array( $column, array( 'rule', 'assignees', 'checklist' ), true ) && is_array( $values[ $column ] )
				? (string) wp_json_encode( $values[ $column ] )
				: (string) $values[ $column ];
		}

		return $row;
	}

	/**
	 * A row as the rest of the plugin reads it.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$rule      = json_decode( (string) $row['rule'], true );
		$assignees = json_decode( (string) ( $row['assignees'] ?? '' ), true );
		$checklist = json_decode( (string) ( $row['checklist'] ?? '' ), true );
		$lines     = array();

		foreach ( is_array( $checklist ) ? $checklist : array() as $line ) {
			if ( is_array( $line ) && '' !== (string) ( $line['text'] ?? '' ) ) {
				$lines[] = array(
					'text' => (string) $line['text'],
					'done' => false,
				);
			}
		}

		return array(
			'id'              => (string) $row['id'],
			'kind'            => (string) $row['kind'],
			'client_site_id'  => (string) $row['client_site_id'],
			'client_id'       => (string) $row['client_id'],
			'title'           => (string) $row['title'],
			'description'     => (string) $row['description'],
			'work_type'       => (string) $row['work_type'],
			'primary_user_id' => (string) $row['primary_user_id'],
			'reviewer_id'     => (string) $row['reviewer_id'],
			'deliverer_id'    => (string) $row['deliverer_id'],
			'hours_primary'   => (float) $row['hours_primary'],
			'hours_review'    => (float) $row['hours_review'],
			'hours_delivery'  => (float) $row['hours_delivery'],
			// Who does it, and the hours each of them spends (2026-09-18).
			'assignees'       => array_values( array_map( 'strval', is_array( $assignees ) ? $assignees : array() ) ),
			'hours_each'      => (float) ( $row['hours_each'] ?? 0 ),
			// The checklist every task it makes starts with (2026-09-19).
			'checklist'       => $lines,
			'rule'            => is_array( $rule ) ? $rule : array( 'every' => 'day' ),
			'cadence'         => Rule::describe( is_array( $rule ) ? $rule : array( 'every' => 'day' ) ),
			'starts_on'       => (string) $row['starts_on'],
			'ends_on'         => (string) $row['ends_on'],
			'next_due'        => (string) $row['next_due'],
			'last_created_at' => (int) $row['last_created_at'],
			'status'          => (string) $row['status'],
			'source_ref'      => (string) $row['source_ref'],
			'created_at'      => (int) $row['created_at'],
			'updated_at'      => (int) $row['updated_at'],
			'created_by'      => (int) $row['created_by'],
			'record_version'  => (int) $row['record_version'],
		);
	}
}
