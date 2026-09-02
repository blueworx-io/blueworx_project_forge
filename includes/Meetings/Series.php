<?php
/**
 * The standing meetings a client's package includes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Meetings;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Tenancy\Ids;

/**
 * #152, MEET-1 and MEET-2. A series is the arrangement, never the meetings.
 *
 * **The rule is stored; the dates are worked out.** Writing out every future
 * occurrence at the moment a series is created is the obvious implementation
 * and it goes wrong in three directions at once: changing "every Tuesday" to
 * "every other Tuesday" means rewriting rows nobody can safely identify, a
 * meeting somebody moved by hand gets overwritten by the next regeneration, and
 * an open-ended series has no natural end to stop writing at.
 *
 * So the rule is one row, {@see occurrences()} expands it on demand, and #153's
 * exceptions are recorded against it rather than replacing it. What is stored
 * is what somebody agreed; what is shown is what that means today.
 *
 * Scoped to the site (ARCH-3), like everything else a client owns.
 */
final class Series {

	/**
	 * Id prefix for a meeting series.
	 */
	public const PREFIX = 'mts';

	/**
	 * Running.
	 */
	public const ACTIVE = 'active';

	/**
	 * Stopped. Past occurrences stay exactly as they were.
	 */
	public const ENDED = 'ended';

	/**
	 * Every state a series can be in.
	 *
	 * @var array<int, string>
	 */
	public const STATES = array( self::ACTIVE, self::ENDED );

	/**
	 * Starts a series.
	 *
	 * @param array<string, mixed> $values Validated by {@see Validate::series()}.
	 * @param string               $client_id The client the site belongs to.
	 * @param int                  $actor  Who set it up.
	 * @return array<string, mixed>|null Null when the write failed.
	 */
	public static function create( array $values, string $client_id, int $actor ): ?array {
		global $wpdb;

		$now = bwx_forge_now();
		$row = array(
			'id'             => Ids::create( self::PREFIX ),
			'client_site_id' => (string) ( $values['client_site_id'] ?? '' ),
			'client_id'      => $client_id,
			'title'          => (string) ( $values['title'] ?? '' ),
			'frequency'      => (string) ( $values['frequency'] ?? Recurrence::WEEKLY ),
			'starts_on'      => (string) ( $values['starts_on'] ?? '' ),
			'ends_on'        => (string) ( $values['ends_on'] ?? '' ),
			'time_of_day'    => (string) ( $values['time_of_day'] ?? '09:00' ),
			'duration_mins'  => (int) ( $values['duration_mins'] ?? 60 ),
			'timezone'       => (string) ( $values['timezone'] ?? 'UTC' ),
			'host_user_id'   => (string) ( $values['host_user_id'] ?? '' ),
			'attendees'      => (string) ( $values['attendees'] ?? '' ),
			'planned_hours'  => (float) ( $values['planned_hours'] ?? 0 ),
			'state'          => self::ACTIVE,
			'created_at'     => $now,
			'updated_at'     => $now,
			'created_by'     => $actor,
			'record_version' => 1,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		$written = $wpdb->insert( Schema::meeting_series_table(), $row, Formats::for_row( $row ) );

		return $written ? self::hydrate( $row ) : null;
	}

	/**
	 * Changes a series, if nobody has changed it first.
	 *
	 * @param string               $id           The series.
	 * @param array<string, mixed> $values       Validated fields.
	 * @param int                  $sent_version Version the edit was made against.
	 * @return array<string, mixed>|null Null when it was stale or the write failed.
	 */
	public static function update( string $id, array $values, int $sent_version ): ?array {
		global $wpdb;

		$changes = array();

		foreach ( self::editable() as $field ) {
			if ( array_key_exists( $field, $values ) ) {
				$changes[ $field ] = $values[ $field ];
			}
		}

		if ( array() === $changes ) {
			return self::get( $id );
		}

		$changes['updated_at']     = bwx_forge_now();
		$changes['record_version'] = $sent_version + 1;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$changed = $wpdb->update(
			Schema::meeting_series_table(),
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
	 * Stops a series.
	 *
	 * The row stays and its past occurrences stay with it. A client asking what
	 * their meetings were last March needs the arrangement that was in force
	 * then, and a deleted row cannot answer.
	 *
	 * @param string $id           The series.
	 * @param int    $sent_version Version the change was made against.
	 * @return bool
	 */
	public static function end( string $id, int $sent_version ): bool {
		return null !== self::update( $id, array( 'state' => self::ENDED ), $sent_version );
	}

	/**
	 * One series.
	 *
	 * @param string $id The series.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		global $wpdb;

		$table = Schema::meeting_series_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * A site's series, running ones first.
	 *
	 * @param string $client_site_id The site.
	 * @param bool   $running_only   Whether to leave out the ended ones.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_site( string $client_site_id, bool $running_only = false ): array {
		global $wpdb;

		$table = Schema::meeting_series_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $running_only
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_site_id = %s AND state = %s ORDER BY starts_on ASC, id ASC", $client_site_id, self::ACTIVE ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_site_id = %s ORDER BY state ASC, starts_on ASC, id ASC", $client_site_id ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Every running series that could put a meeting in a window, on any client.
	 *
	 * **Deliberately unscoped**, like {@see \Blueworx\Forge\Capacity\Commitments}
	 * and for the same reason: a person cannot look free on one client while
	 * sitting in another client's meeting, so the read that answers "is there
	 * room" has to span tenants. It is reachable from the studio only, and the
	 * routes that expose what it feeds say so.
	 *
	 * @param string $from YYYY-MM-DD, inclusive.
	 * @param string $to   YYYY-MM-DD, inclusive.
	 * @return array<int, array<string, mixed>>
	 */
	public static function running_between( string $from, string $to ): array {
		global $wpdb;

		if ( '' === $from || '' === $to || $to < $from ) {
			return array();
		}

		$table = Schema::meeting_series_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE state = %s AND starts_on <> '' AND starts_on <= %s AND ( ends_on = '' OR ends_on >= %s ) ORDER BY id ASC",
				self::ACTIVE,
				$to,
				$from
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * What a series means, as dates, inside a window.
	 *
	 * The series' own hours figure is carried onto each one, so a caller never
	 * has to remember whether nought meant free or meant "work it out".
	 *
	 * @param array<string, mixed> $series The series, as stored.
	 * @param string               $from   YYYY-MM-DD, inclusive.
	 * @param string               $to     YYYY-MM-DD, inclusive.
	 * @return array<int, array<string, mixed>>
	 */
	public static function occurrences( array $series, string $from, string $to ): array {
		// An ended series generates nothing new. Its past occurrences are
		// #153's, stored against it, and are not re-derived from here.
		if ( self::ACTIVE !== (string) ( $series['state'] ?? '' ) ) {
			return array();
		}

		$hours = Validate::hours_for( $series );

		return array_map(
			static function ( array $occurrence ) use ( $series, $hours ): array {
				$occurrence['series_id']     = (string) ( $series['id'] ?? '' );
				$occurrence['planned_hours'] = $hours;

				return $occurrence;
			},
			Recurrence::expand( $series, $from, $to )
		);
	}

	/**
	 * The fields an edit may set.
	 *
	 * Not the site, and not the client: moving a series between clients would
	 * move a commercial commitment with it, and there is no route here that
	 * does it by accident.
	 *
	 * @return array<int, string>
	 */
	private static function editable(): array {
		return array(
			'title',
			'frequency',
			'starts_on',
			'ends_on',
			'time_of_day',
			'duration_mins',
			'timezone',
			'host_user_id',
			'attendees',
			'planned_hours',
			'state',
		);
	}

	/**
	 * A stored row, with its numbers as numbers.
	 *
	 * @param array<string, mixed> $row As the database returned it.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		return array(
			'id'              => (string) $row['id'],
			'client_site_id'  => (string) $row['client_site_id'],
			'client_id'       => (string) $row['client_id'],
			'title'           => (string) $row['title'],
			'frequency'       => (string) $row['frequency'],
			'frequency_label' => Recurrence::label( (string) $row['frequency'] ),
			'starts_on'       => (string) $row['starts_on'],
			'ends_on'         => (string) $row['ends_on'],
			'time_of_day'     => (string) $row['time_of_day'],
			'duration_mins'   => (int) $row['duration_mins'],
			'timezone'        => (string) $row['timezone'],
			'host_user_id'    => (string) $row['host_user_id'],
			'attendees'       => (string) $row['attendees'],
			'planned_hours'   => (float) $row['planned_hours'],

			// What one occurrence actually costs, worked out rather than left
			// for every reader to decide what nought meant.
			'hours_each'      => Validate::hours_for( $row ),
			'state'           => (string) $row['state'],
			'created_at'      => (int) $row['created_at'],
			'updated_at'      => (int) $row['updated_at'],
			'created_by'      => (int) $row['created_by'],
			'record_version'  => (int) $row['record_version'],
		);
	}
}
