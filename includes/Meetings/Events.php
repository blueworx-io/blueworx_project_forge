<?php
/**
 * Everything that has been done to a meeting.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Meetings;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Tenancy\Ids;

/**
 * #153. Append-only, like every other history in this plugin.
 *
 * The occurrence row says where a meeting is now. This says how it got there:
 * moved twice, cancelled and put back, marked held by whom. Without it the row
 * alone can only ever answer for the last thing that happened — and "when was
 * this moved, and who agreed it" is exactly the question a client asks, usually
 * about a meeting three months ago.
 *
 * Every entry names somebody. A meeting that moved itself is not a thing that
 * happens, and an entry nobody can be asked about is one nobody can answer for.
 */
final class Events {

	/**
	 * Id prefix for a meeting event.
	 */
	public const PREFIX = 'mte';

	/**
	 * An exception was first written against a slot.
	 */
	public const EXCEPTED = 'excepted';

	/**
	 * The meeting was moved to another date.
	 */
	public const MOVED = 'moved';

	/**
	 * It was called off.
	 */
	public const CANCELLED = 'cancelled';

	/**
	 * The host marked it held, which is what draws the hours (MEET-5).
	 */
	public const HELD = 'held';

	/**
	 * Nobody came.
	 */
	public const NO_SHOW = 'no-show';

	/**
	 * It was put back to scheduled after being cancelled.
	 */
	public const REINSTATED = 'reinstated';

	/**
	 * Its planned hours were changed by hand.
	 */
	public const REPRICED = 'repriced';

	/**
	 * Every action that can be recorded.
	 *
	 * @var array<int, string>
	 */
	public const ACTIONS = array(
		self::EXCEPTED,
		self::MOVED,
		self::CANCELLED,
		self::HELD,
		self::NO_SHOW,
		self::REINSTATED,
		self::REPRICED,
	);

	/**
	 * Longest a reason may be.
	 */
	public const MAX_REASON = 2000;

	/**
	 * The row an entry would become, or empty when it may not be written.
	 *
	 * Separate from writing it so what makes an entry attributable can be read,
	 * and tested, without a database.
	 *
	 * @param array<string, mixed> $entry occurrence_id, series_id,
	 *                                    client_site_id, action, from_status,
	 *                                    to_status, from_date, to_date, reason,
	 *                                    actor.
	 * @return array<string, mixed>
	 */
	public static function row_from( array $entry ): array {
		$action = (string) ( $entry['action'] ?? '' );
		$actor  = (int) ( $entry['actor'] ?? 0 );

		if ( ! in_array( $action, self::ACTIONS, true ) || $actor <= 0 ) {
			return array();
		}

		$occurrence = (string) ( $entry['occurrence_id'] ?? '' );

		if ( '' === $occurrence ) {
			return array();
		}

		return array(
			'id'             => Ids::create( self::PREFIX ),
			'occurrence_id'  => $occurrence,
			'series_id'      => (string) ( $entry['series_id'] ?? '' ),
			'client_site_id' => (string) ( $entry['client_site_id'] ?? '' ),
			'action'         => $action,
			'from_status'    => (string) ( $entry['from_status'] ?? '' ),
			'to_status'      => (string) ( $entry['to_status'] ?? '' ),
			'from_date'      => (string) ( $entry['from_date'] ?? '' ),
			'to_date'        => (string) ( $entry['to_date'] ?? '' ),
			'reason'         => mb_substr( (string) ( $entry['reason'] ?? '' ), 0, self::MAX_REASON ),
			'actor'          => $actor,
			'occurred_at'    => bwx_forge_now(),
		);
	}

	/**
	 * Appends an entry.
	 *
	 * @param array<string, mixed> $entry As {@see self::row_from()} takes.
	 * @return bool Whether it was written.
	 */
	public static function append( array $entry ): bool {
		global $wpdb;

		$row = self::row_from( $entry );

		if ( array() === $row ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		return (bool) $wpdb->insert( Schema::meeting_events_table(), $row, Formats::for_row( $row ) );
	}

	/**
	 * One meeting's history, oldest first.
	 *
	 * @param string $occurrence_id The occurrence.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_occurrence( string $occurrence_id ): array {
		global $wpdb;

		$table = Schema::meeting_events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE occurrence_id = %s ORDER BY occurred_at ASC, id ASC", $occurrence_id ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * A whole series' history, newest first — what a screen shows.
	 *
	 * @param string $series_id The series.
	 * @param int    $limit     How many at most.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_series( string $series_id, int $limit = 100 ): array {
		global $wpdb;

		$table = Schema::meeting_events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE series_id = %s ORDER BY occurred_at DESC, id DESC LIMIT %d", $series_id, max( 1, min( 500, $limit ) ) ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}
}
