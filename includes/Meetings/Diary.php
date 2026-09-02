<?php
/**
 * A site's meetings: what the rules say, and what actually happened.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Meetings;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Tenancy\Ids;

/**
 * #153, MEET-2 and MEET-5. The writing half of {@see Occurrence}.
 *
 * Every rule about what a merged view contains is in the pure class. What is
 * here is the reads it needs, and the one write that matters: **acting on a
 * meeting writes an exception against the slot it came from, and never touches
 * the series.**
 *
 * That is why there is a single {@see except()} rather than a method per
 * action. Moving a meeting, cancelling it, marking it held and changing its
 * hours are the same write with different fields — the row is created on first
 * touch and updated afterwards — and splitting them into four would give four
 * chances to forget the audit entry that has to go with each.
 */
final class Diary {

	/**
	 * How far ahead a caller that does not say gets.
	 *
	 * Twelve weeks, matching MEET-4's reservation horizon, so the default view
	 * and the hours that are actually committed agree about what "coming up"
	 * means.
	 */
	public const HORIZON_DAYS = 84;

	/**
	 * One series' meetings in a window.
	 *
	 * @param array<string, mixed> $series The series, as stored.
	 * @param string               $from   YYYY-MM-DD, inclusive.
	 * @param string               $to     YYYY-MM-DD, inclusive.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_series( array $series, string $from, string $to ): array {
		return Occurrence::merge(
			Series::occurrences( $series, $from, $to ),
			self::stored_for( (string) ( $series['id'] ?? '' ), $from, $to ),
			$from,
			$to
		);
	}

	/**
	 * Every meeting a site has in a window, across all its series.
	 *
	 * @param string $client_site_id The site.
	 * @param string $from           YYYY-MM-DD, inclusive.
	 * @param string $to             YYYY-MM-DD, inclusive.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_site( string $client_site_id, string $from, string $to ): array {
		$meetings = array();

		foreach ( Series::for_site( $client_site_id ) as $series ) {
			$meetings = array_merge( $meetings, self::for_series( $series, $from, $to ) );
		}

		usort(
			$meetings,
			static fn( array $a, array $b ): int => array( $a['on'], $a['at'], $a['id'] ) <=> array( $b['on'], $b['at'], $b['id'] )
		);

		return $meetings;
	}

	/**
	 * Records something happening to one meeting.
	 *
	 * The row is created if this is the first thing to happen to that slot, and
	 * updated if it is not. Either way an entry goes in the history, and a
	 * write that cannot be attributed is refused rather than made quietly.
	 *
	 * @param array<string, mixed> $series  The series it belongs to.
	 * @param string               $slot    The date the rule put it on.
	 * @param array<string, mixed> $changes on, at, status, planned_hours,
	 *                                      meeting_link.
	 * @param string               $action  One of {@see Events::ACTIONS}.
	 * @param int                  $actor   Who did it.
	 * @param string               $reason  Why, where there is one.
	 * @return array<string, mixed>|null Null when it could not be written.
	 */
	public static function except( array $series, string $slot, array $changes, string $action, int $actor, string $reason = '' ): ?array {
		$series_id = (string) ( $series['id'] ?? '' );

		if ( '' === $series_id || '' === $slot || $actor <= 0 ) {
			return null;
		}

		$existing = self::slot( $series_id, $slot );
		$before   = null === $existing ? Occurrence::SCHEDULED : (string) $existing['status'];
		$was_on   = null === $existing ? $slot : (string) $existing['on'];

		$written = null === $existing
			? self::write( $series, $slot, $changes, $actor )
			: self::amend( $existing, $changes );

		if ( null === $written ) {
			return null;
		}

		Events::append(
			array(
				'occurrence_id'  => (string) $written['id'],
				'series_id'      => $series_id,
				'client_site_id' => (string) ( $series['client_site_id'] ?? '' ),
				'action'         => $action,
				'from_status'    => $before,
				'to_status'      => (string) $written['status'],
				'from_date'      => $was_on,
				'to_date'        => (string) $written['on'],
				'reason'         => $reason,
				'actor'          => $actor,
			)
		);

		return $written;
	}

	/**
	 * One slot's stored exception, if it has one.
	 *
	 * @param string $series_id The series.
	 * @param string $slot      The date the rule put the meeting on.
	 * @return array<string, mixed>|null
	 */
	public static function slot( string $series_id, string $slot ): ?array {
		global $wpdb;

		$table = Schema::meeting_occurrences_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE series_id = %s AND excepted_from = %s", $series_id, $slot ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * One stored occurrence by its id.
	 *
	 * @param string $id The occurrence.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		global $wpdb;

		$table = Schema::meeting_occurrences_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * The exceptions that touch a window, either where they came from or where
	 * they landed.
	 *
	 * Both, because a meeting can move out of a window and another can move in,
	 * and {@see Occurrence::merge()} needs to see both to get either right.
	 *
	 * @param string $series_id The series.
	 * @param string $from      YYYY-MM-DD, inclusive.
	 * @param string $to        YYYY-MM-DD, inclusive.
	 * @return array<int, array<string, mixed>>
	 */
	public static function stored_for( string $series_id, string $from, string $to ): array {
		global $wpdb;

		if ( '' === $series_id ) {
			return array();
		}

		$table = Schema::meeting_occurrences_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE series_id = %s AND ( ( excepted_from >= %s AND excepted_from <= %s ) OR ( on_date >= %s AND on_date <= %s ) ) ORDER BY on_date ASC, id ASC",
				$series_id,
				$from,
				$to,
				$from,
				$to
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Writes the first exception against a slot.
	 *
	 * @param array<string, mixed> $series  The series.
	 * @param string               $slot    The date the rule put it on.
	 * @param array<string, mixed> $changes What is different about it.
	 * @param int                  $actor   Who did it.
	 * @return array<string, mixed>|null
	 */
	private static function write( array $series, string $slot, array $changes, int $actor ): ?array {
		global $wpdb;

		$now    = bwx_forge_now();
		$status = self::status_of( $changes, Occurrence::SCHEDULED );
		$on     = (string) ( $changes['on'] ?? $slot );

		$row = array(
			'id'             => Ids::create( Occurrence::PREFIX ),
			'series_id'      => (string) $series['id'],
			'client_site_id' => (string) ( $series['client_site_id'] ?? '' ),
			'client_id'      => (string) ( $series['client_id'] ?? '' ),
			'excepted_from'  => $slot,
			'on_date'        => $on,
			'at_time'        => (string) ( $changes['at'] ?? ( $series['time_of_day'] ?? '09:00' ) ),
			'starts_at'      => (int) ( $changes['starts_at'] ?? 0 ),
			'ends_at'        => (int) ( $changes['ends_at'] ?? 0 ),
			'status'         => $status,
			'planned_hours'  => (float) ( $changes['planned_hours'] ?? Validate::hours_for( $series ) ),
			'ledger_state'   => (string) ( $changes['ledger_state'] ?? 'forecast' ),
			'meeting_link'   => (string) ( $changes['meeting_link'] ?? '' ),
			'held_marked_by' => Occurrence::HELD === $status ? $actor : 0,
			'held_at'        => Occurrence::HELD === $status ? $now : 0,
			'created_at'     => $now,
			'updated_at'     => $now,
			'created_by'     => $actor,
			'record_version' => 1,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		$written = $wpdb->insert( Schema::meeting_occurrences_table(), $row, Formats::for_row( $row ) );

		return $written ? self::hydrate( $row ) : null;
	}

	/**
	 * Changes an exception that already exists.
	 *
	 * @param array<string, mixed> $existing The stored occurrence.
	 * @param array<string, mixed> $changes  What is different about it.
	 * @return array<string, mixed>|null
	 */
	private static function amend( array $existing, array $changes ): ?array {
		global $wpdb;

		$fields = array(
			'on'            => 'on_date',
			'at'            => 'at_time',
			'starts_at'     => 'starts_at',
			'ends_at'       => 'ends_at',
			'status'        => 'status',
			'planned_hours' => 'planned_hours',
			'ledger_state'  => 'ledger_state',
			'meeting_link'  => 'meeting_link',
		);

		$update = array();

		foreach ( $fields as $given => $column ) {
			if ( array_key_exists( $given, $changes ) ) {
				$update[ $column ] = $changes[ $given ];
			}
		}

		if ( array() === $update ) {
			return $existing;
		}

		$update['updated_at']     = bwx_forge_now();
		$update['record_version'] = (int) $existing['record_version'] + 1;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$changed = $wpdb->update(
			Schema::meeting_occurrences_table(),
			$update,
			array( 'id' => (string) $existing['id'] ),
			Formats::for_row( $update ),
			array( '%s' )
		);

		return false === $changed ? null : self::get( (string) $existing['id'] );
	}

	/**
	 * The status a change asks for, or a fallback when it does not ask.
	 *
	 * @param array<string, mixed> $changes  What is different.
	 * @param string               $fallback What it is now.
	 * @return string
	 */
	private static function status_of( array $changes, string $fallback ): string {
		$asked = (string) ( $changes['status'] ?? '' );

		return Occurrence::exists( $asked ) ? $asked : $fallback;
	}

	/**
	 * A stored row in the shape {@see Occurrence::merge()} reads.
	 *
	 * `on_date` becomes `on` here rather than everywhere downstream: the column
	 * is named around a reserved word and nothing above this should have to
	 * know that.
	 *
	 * @param array<string, mixed> $row As the database returned it.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		return array(
			'id'             => (string) $row['id'],
			'series_id'      => (string) $row['series_id'],
			'client_site_id' => (string) $row['client_site_id'],
			'client_id'      => (string) $row['client_id'],
			'excepted_from'  => (string) $row['excepted_from'],
			'on'             => (string) $row['on_date'],
			'at'             => (string) $row['at_time'],
			'starts_at'      => (int) $row['starts_at'],
			'ends_at'        => (int) $row['ends_at'],
			'status'         => (string) $row['status'],
			'planned_hours'  => (float) $row['planned_hours'],
			'ledger_state'   => (string) $row['ledger_state'],
			'meeting_link'   => (string) $row['meeting_link'],
			'held_marked_by' => (int) $row['held_marked_by'],
			'held_at'        => (int) $row['held_at'],
			'created_at'     => (int) $row['created_at'],
			'updated_at'     => (int) $row['updated_at'],
			'created_by'     => (int) $row['created_by'],
			'record_version' => (int) $row['record_version'],
		);
	}
}
