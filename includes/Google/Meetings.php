<?php
/**
 * Meetings that came from Google Calendar.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Google;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Meetings\Occurrence;
use Blueworx\Forge\Tenancy\Ids;
use Blueworx\Forge\Tenancy\Studio;

/**
 * #606. One row per meeting, however many connected calendars it is on, and
 * one row per person's copy of it.
 *
 * **Google owns the time; Forge owns the settlement.** A sync rewrites the
 * title, the day and the length every time, and never touches the client,
 * the support choice or what became of the meeting — so a meeting Google
 * moves keeps its settlement and its hours follow the new length.
 *
 * Deleted in Google means removed here, unless it was marked held: hours
 * charged for a meeting that happened stay charged, and the meeting stays so
 * the charge has something to name.
 */
final class Meetings {

	/**
	 * Id prefix for a synced meeting.
	 */
	public const PREFIX = 'gmt';

	/**
	 * Id prefix for a person's copy.
	 */
	public const PERSON_PREFIX = 'gmp';

	/**
	 * A client filled in from the series' remembered choice (#657).
	 */
	public const PREFILL_SERIES = 'series';

	/**
	 * Studio time filled in for an all-staff meeting (#657).
	 */
	public const PREFILL_STUDIO = 'studio';

	/**
	 * Brings one event from one person's calendar into Forge.
	 *
	 * @param string               $user_id Forge person id.
	 * @param array<string, mixed> $event   From {@see Events::normalise()}.
	 * @return string|null The meeting's id, or null when nothing is kept.
	 */
	public static function apply( string $user_id, array $event ): ?string {
		global $wpdb;

		$event_id = (string) ( $event['event_id'] ?? '' );

		if ( '' === $user_id || '' === $event_id ) {
			return null;
		}

		if ( ! empty( $event['cancelled'] ) || '' === (string) ( $event['on'] ?? '' ) ) {
			self::drop( $user_id, $event_id );

			return null;
		}

		$copy    = self::copy( $user_id, $event_id );
		$meeting = self::by_share_key( (string) $event['share_key'] );

		// The same copy now belongs to a different meeting: let go of the old.
		if ( null !== $copy && ( null === $meeting || (string) $copy['meeting_id'] !== (string) $meeting['id'] ) ) {
			self::drop( $user_id, $event_id );
			$copy = null;
		}

		$now    = bwx_forge_now();
		$fields = array(
			'series_key'    => (string) ( $event['series_key'] ?? '' ),
			'title'         => (string) $event['title'],
			'description'   => (string) $event['description'],
			'attendees'     => (string) wp_json_encode( (array) $event['attendees'] ),
			'on_date'       => (string) $event['on'],
			'ends_on'       => (string) $event['ends_on'],
			'at_time'       => (string) $event['at'],
			'starts_at'     => (int) $event['starts_at'],
			'ends_at'       => (int) $event['ends_at'],
			'all_day'       => empty( $event['all_day'] ) ? 0 : 1,
			'planned_hours' => (string) round( (float) $event['planned_hours'], 2 ),
			'updated_at'    => $now,
		);

		if ( null === $meeting ) {
			$row = array_merge(
				array(
					'id'        => Ids::create( self::PREFIX ),
					'share_key' => (string) $event['share_key'],
				),
				$fields,
				array(
					'private'        => empty( $event['private'] ) ? 0 : 1,
					'client_site_id' => '',
					'client_id'      => '',
					'off_support'    => 0,
					'settled_by'     => 0,
					'settled_at'     => 0,
					'status'         => Occurrence::SCHEDULED,
					'ledger_state'   => 'forecast',
					'held_marked_by' => 0,
					'held_at'        => 0,
					'created_at'     => $now,
					'created_by'     => 0,
					'record_version' => 1,
				)
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
			if ( ! $wpdb->insert( Schema::google_meetings_table(), $row, Formats::for_row( $row ) ) ) {
				// Another request made it first: use theirs.
				$meeting = self::by_share_key( (string) $event['share_key'] );

				if ( null === $meeting ) {
					return null;
				}
			} else {
				$meeting = array( 'id' => $row['id'] );
			}
		} else {
			$fields['record_version'] = (int) $meeting['record_version'] + 1;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
			$wpdb->update( Schema::google_meetings_table(), $fields, array( 'id' => (string) $meeting['id'] ), Formats::for_row( $fields ), array( '%s' ) );
		}

		$id     = (string) $meeting['id'];
		$person = array(
			'meeting_id' => $id,
			'user_id'    => $user_id,
			'event_id'   => mb_substr( $event_id, 0, 191 ),
			'response'   => mb_substr( (string) $event['response'], 0, 20 ),
			'counts'     => empty( $event['counts'] ) ? 0 : 1,
			'private'    => empty( $event['private'] ) ? 0 : 1,
			'updated_at' => $now,
		);

		if ( null === $copy ) {
			$person = array( 'id' => Ids::create( self::PERSON_PREFIX ) ) + $person;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
			$wpdb->insert( Schema::google_people_table(), $person, Formats::for_row( $person ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
			$wpdb->update( Schema::google_people_table(), $person, array( 'id' => (string) $copy['id'] ), Formats::for_row( $person ), array( '%s' ) );
		}

		self::refresh_private( $id );

		// #657. Fill in the series' client, or studio time; the hours follow
		// on the next reconcile, which the sync route runs.
		$stored = self::by_share_key( (string) $event['share_key'] );

		if ( null !== $stored ) {
			$fill = self::prefill_changes(
				$stored,
				SeriesChoice::get( (string) $stored['series_key'] ),
				false,
				Studio::site_id(),
				Studio::client_id()
			);

			if ( array() !== $fill ) {
				self::fill( $stored, $fill );
			}
		}

		return $id;
	}

	/**
	 * Takes one person's copy away: deleted or cancelled in Google, or no
	 * longer on their calendar.
	 *
	 * @param string $user_id  Forge person id.
	 * @param string $event_id Their copy's Google id.
	 */
	public static function drop( string $user_id, string $event_id ): void {
		$copy = self::copy( $user_id, $event_id );

		if ( null === $copy ) {
			return;
		}

		$meeting = self::get( (string) $copy['meeting_id'] );

		// Held: it happened, and stays with everyone on it.
		if ( null !== $meeting && Occurrence::HELD === (string) $meeting['status'] ) {
			return;
		}

		self::delete_copy( (string) $copy['id'] );

		if ( null !== $meeting && array() === self::people_of( array( (string) $meeting['id'] ) ) ) {
			self::remove( $meeting );
		}
	}

	/**
	 * After a full read: every copy of this person's in the window that
	 * Google did not return has gone.
	 *
	 * @param string             $user_id Forge person id.
	 * @param array<int, string> $seen    The event ids Google returned.
	 * @param string             $from    YYYY-MM-DD.
	 * @param string             $to      YYYY-MM-DD.
	 */
	public static function drop_unseen( string $user_id, array $seen, string $from, string $to ): void {
		$seen = array_flip( array_map( 'strval', $seen ) );

		foreach ( self::copies_of( $user_id ) as $copy ) {
			$on = (string) $copy['on_date'];

			if ( $on < $from || $on > $to || isset( $seen[ (string) $copy['event_id'] ] ) ) {
				continue;
			}

			self::drop( $user_id, (string) $copy['event_id'] );
		}
	}

	/**
	 * On disconnecting: the person's unsettled copies go; settled ones stay,
	 * because their hours may already be held or charged.
	 *
	 * @param string $user_id Forge person id.
	 */
	public static function forget_unsettled( string $user_id ): void {
		foreach ( self::copies_of( $user_id ) as $copy ) {
			$meeting = self::get( (string) $copy['meeting_id'] );

			if ( null !== $meeting && $meeting['settled'] ) {
				continue;
			}

			self::delete_copy( (string) $copy['id'] );

			if ( null !== $meeting && array() === self::people_of( array( (string) $meeting['id'] ) ) ) {
				self::remove( $meeting );
			}
		}
	}

	/**
	 * A person's copies from before their start date go, unless the meeting
	 * has been settled (#657): that time was accounted for by hand.
	 *
	 * @param string $user_id Forge person id.
	 * @param string $start   YYYY-MM-DD.
	 */
	public static function forget_before( string $user_id, string $start ): void {
		foreach ( self::copies_of( $user_id ) as $copy ) {
			if ( (string) $copy['on_date'] >= $start ) {
				continue;
			}

			$meeting = self::get( (string) $copy['meeting_id'] );

			if ( null !== $meeting && $meeting['settled'] ) {
				continue;
			}

			self::delete_copy( (string) $copy['id'] );

			if ( null !== $meeting && array() === self::people_of( array( (string) $meeting['id'] ) ) ) {
				self::remove( $meeting );
			}
		}
	}

	/**
	 * Settles a meeting: its client site, whether its time comes off
	 * support, and optionally what became of it.
	 *
	 * @param array<string, mixed> $meeting     The meeting, as read.
	 * @param string               $site_id     The client site.
	 * @param string               $client_id   That site's client.
	 * @param bool                 $off_support Whether its time comes off support.
	 * @param string|null          $status      One of Occurrence::STATUSES, or null to leave it.
	 * @param int                  $actor       Who did it.
	 * @return array<string, mixed>|null The meeting afterwards, or null when stale.
	 */
	public static function settle( array $meeting, string $site_id, string $client_id, bool $off_support, ?string $status, int $actor ): ?array {
		global $wpdb;

		$now     = bwx_forge_now();
		$changes = array(
			'client_site_id' => $site_id,
			'client_id'      => $client_id,
			'off_support'    => $off_support ? 1 : 0,
			// A person's pick is never a pre-fill.
			'prefill'        => '',
			'settled_by'     => $actor,
			'settled_at'     => $now,
			'updated_at'     => $now,
			'record_version' => (int) $meeting['record_version'] + 1,
		);

		if ( null !== $status && Occurrence::exists( $status ) ) {
			$changes['status'] = $status;

			if ( Occurrence::HELD === $status && Occurrence::HELD !== (string) $meeting['status'] ) {
				$changes['held_marked_by'] = $actor;
				$changes['held_at']        = $now;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$changed = $wpdb->update(
			Schema::google_meetings_table(),
			$changes,
			array(
				'id'             => (string) $meeting['id'],
				'record_version' => (int) $meeting['record_version'],
			),
			Formats::for_row( $changes ),
			array( '%s', '%d' )
		);

		return $changed ? self::get( (string) $meeting['id'] ) : null;
	}

	/**
	 * What Forge fills in by itself on a meeting (#657). Pure.
	 *
	 * Only a meeting nobody has dealt with is touched: still scheduled, and
	 * either with no client or one Forge filled in. The series' remembered
	 * client wins, then studio time for an all-staff meeting, else nothing —
	 * so studio time goes again once somebody from outside is invited.
	 *
	 * @param array<string, mixed>      $row           The stored row.
	 * @param array<string, mixed>|null $choice        The series' choice, or null.
	 * @param bool                      $internal      Whether only studio staff are on it.
	 * @param string                    $studio_site   The studio's site.
	 * @param string                    $studio_client The studio client.
	 * @return array<string, mixed> Only the columns that change.
	 */
	public static function prefill_changes( array $row, ?array $choice, bool $internal, string $studio_site, string $studio_client ): array {
		$fillable = Occurrence::SCHEDULED === (string) ( $row['status'] ?? '' )
			&& ( '' === (string) ( $row['client_site_id'] ?? '' ) || '' !== (string) ( $row['prefill'] ?? '' ) );

		if ( ! $fillable ) {
			return array();
		}

		if ( null !== $choice && '' !== (string) ( $choice['client_site_id'] ?? '' ) ) {
			$wanted = array(
				'client_site_id' => (string) $choice['client_site_id'],
				'client_id'      => (string) $choice['client_id'],
				'off_support'    => empty( $choice['off_support'] ) ? 0 : 1,
				'prefill'        => self::PREFILL_SERIES,
			);
		} elseif ( $internal && '' !== $studio_site ) {
			$wanted = array(
				'client_site_id' => $studio_site,
				'client_id'      => $studio_client,
				'off_support'    => 0,
				'prefill'        => self::PREFILL_STUDIO,
			);
		} else {
			$wanted = array(
				'client_site_id' => '',
				'client_id'      => '',
				'off_support'    => 0,
				'prefill'        => '',
			);
		}

		$changes = array();

		foreach ( $wanted as $column => $value ) {
			if ( (string) $value !== (string) ( $row[ $column ] ?? '' ) ) {
				$changes[ $column ] = $value;
			}
		}

		return $changes;
	}

	/**
	 * Writes what Forge filled in, against the version it read (#657).
	 *
	 * @param array<string, mixed> $row     The stored row, as read.
	 * @param array<string, mixed> $changes From {@see self::prefill_changes()}.
	 * @return bool|null True when written, null when the row had moved on.
	 */
	public static function fill( array $row, array $changes ): ?bool {
		global $wpdb;

		$changes['updated_at']     = bwx_forge_now();
		$changes['record_version'] = (int) $row['record_version'] + 1;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$changed = $wpdb->update(
			Schema::google_meetings_table(),
			$changes,
			array(
				'id'             => (string) $row['id'],
				'record_version' => (int) $row['record_version'],
			),
			Formats::for_row( $changes ),
			array( '%s', '%d' )
		);

		return $changed ? true : null;
	}

	/**
	 * Records what the ledger now holds against a meeting.
	 *
	 * @param string $id    The meeting.
	 * @param string $state One of MeetingHours' four.
	 */
	public static function set_ledger_state( string $id, string $state ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update(
			Schema::google_meetings_table(),
			array( 'ledger_state' => $state ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * One meeting, with who is on it.
	 *
	 * @param string $id The meeting.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		global $wpdb;

		$table = Schema::google_meetings_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A );

		return is_array( $row ) ? self::with_people( array( $row ) )[0] : null;
	}

	/**
	 * Every meeting touching a window, with who is on each.
	 *
	 * @param string $from YYYY-MM-DD, inclusive.
	 * @param string $to   YYYY-MM-DD, inclusive.
	 * @return array<int, array<string, mixed>>
	 */
	public static function between( string $from, string $to ): array {
		global $wpdb;

		if ( '' === $from || '' === $to || $to < $from ) {
			return array();
		}

		$table = Schema::google_meetings_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE on_date <= %s AND ( on_date >= %s OR ends_on >= %s ) ORDER BY on_date ASC, at_time ASC, id ASC",
				$to,
				$from,
				$from
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return self::with_people( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Settled meetings whose hours might need moving: still holding, or
	 * coming up inside the horizon.
	 *
	 * @param string $today YYYY-MM-DD.
	 * @param string $until YYYY-MM-DD.
	 * @return array<int, array<string, mixed>>
	 */
	public static function worth_asking( string $today, string $until ): array {
		global $wpdb;

		$table = Schema::google_meetings_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE client_site_id <> '' AND ( ledger_state = %s OR ( on_date >= %s AND on_date <= %s ) ) ORDER BY on_date ASC, id ASC",
				'reserved',
				$today,
				$until
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return self::with_people( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Removes a meeting outright, giving back any hours it was holding first.
	 *
	 * @param array<string, mixed> $meeting The meeting, as read.
	 */
	public static function remove( array $meeting ): void {
		global $wpdb;

		Hours::release( $meeting );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own tables.
		$wpdb->delete( Schema::google_people_table(), array( 'meeting_id' => (string) $meeting['id'] ), array( '%s' ) );
		$wpdb->delete( Schema::google_meetings_table(), array( 'id' => (string) $meeting['id'] ), array( '%s' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Whether a meeting needs anybody to say what became of it: somebody on
	 * it is counting its time. Declined and all-day ones never do.
	 *
	 * @param array<string, mixed> $meeting Hydrated.
	 * @return bool
	 */
	public static function needs_settling( array $meeting ): bool {
		return ! $meeting['all_day'] && array() !== $meeting['counting'];
	}

	/**
	 * One person's copy of an event.
	 *
	 * @param string $user_id  Forge person id.
	 * @param string $event_id Google event id.
	 * @return array<string, mixed>|null
	 */
	private static function copy( string $user_id, string $event_id ): ?array {
		global $wpdb;

		$table = Schema::google_people_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %s AND event_id = %s", $user_id, $event_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Every copy a person holds, with its meeting's day.
	 *
	 * @param string $user_id Forge person id.
	 * @return array<int, array<string, mixed>>
	 */
	private static function copies_of( string $user_id ): array {
		global $wpdb;

		$people   = Schema::google_people_table();
		$meetings = Schema::google_meetings_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names cannot be placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.*, m.on_date FROM {$people} p LEFT JOIN {$meetings} m ON m.id = p.meeting_id WHERE p.user_id = %s", $user_id ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * A meeting by the key its copies share.
	 *
	 * @param string $share_key The key.
	 * @return array<string, mixed>|null The raw row.
	 */
	private static function by_share_key( string $share_key ): ?array {
		global $wpdb;

		$table = Schema::google_meetings_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE share_key = %s", $share_key ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Removes one copy.
	 *
	 * @param string $id The copy's id.
	 */
	private static function delete_copy( string $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->delete( Schema::google_people_table(), array( 'id' => $id ), array( '%s' ) );
	}

	/**
	 * A meeting is private when any copy of it is.
	 *
	 * @param string $id The meeting.
	 */
	private static function refresh_private( string $id ): void {
		global $wpdb;

		$private = 0;

		foreach ( self::people_of( array( $id ) ) as $person ) {
			$private = max( $private, (int) $person['private'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update( Schema::google_meetings_table(), array( 'private' => $private ), array( 'id' => $id ), array( '%d' ), array( '%s' ) );
	}

	/**
	 * The copies of some meetings.
	 *
	 * @param array<int, string> $ids Meeting ids.
	 * @return array<int, array<string, mixed>>
	 */
	private static function people_of( array $ids ): array {
		global $wpdb;

		if ( array() === $ids ) {
			return array();
		}

		$table = Schema::google_people_table();
		$slots = implode( ', ', array_fill( 0, count( $ids ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name cannot be a placeholder; the slots are counted above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE meeting_id IN ({$slots}) ORDER BY user_id ASC", array_values( $ids ) ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Rows, hydrated, with their people, in two reads.
	 *
	 * @param array<int, array<string, mixed>> $rows Meeting rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function with_people( array $rows ): array {
		$by_meeting = array();

		foreach ( self::people_of( array_map( static fn( array $row ): string => (string) $row['id'], $rows ) ) as $person ) {
			$by_meeting[ (string) $person['meeting_id'] ][] = $person;
		}

		return array_map(
			static fn( array $row ): array => self::hydrate( $row, $by_meeting[ (string) $row['id'] ] ?? array() ),
			$rows
		);
	}

	/**
	 * A stored meeting, as everything else reads it.
	 *
	 * @param array<string, mixed>             $row    The meeting row.
	 * @param array<int, array<string, mixed>> $people Its copies.
	 * @return array<string, mixed>
	 */
	public static function hydrate( array $row, array $people = array() ): array {
		$attendees = json_decode( (string) ( $row['attendees'] ?? '' ), true );
		$status    = Occurrence::exists( (string) $row['status'] ) ? (string) $row['status'] : Occurrence::SCHEDULED;
		$on        = array();
		$counting  = array();

		foreach ( $people as $person ) {
			$on[] = array(
				'user_id'  => (string) $person['user_id'],
				'response' => (string) $person['response'],
				'counts'   => 1 === (int) $person['counts'],
			);

			if ( 1 === (int) $person['counts'] ) {
				$counting[] = (string) $person['user_id'];
			}
		}

		return array(
			'id'             => (string) $row['id'],
			'share_key'      => (string) $row['share_key'],
			'series_key'     => (string) ( $row['series_key'] ?? '' ),
			'prefill'        => (string) ( $row['prefill'] ?? '' ),
			'title'          => (string) $row['title'],
			'description'    => (string) ( $row['description'] ?? '' ),
			'attendees'      => is_array( $attendees ) ? $attendees : array(),
			'on'             => (string) $row['on_date'],
			'ends_on'        => (string) $row['ends_on'],
			'at'             => (string) $row['at_time'],
			'starts_at'      => (int) $row['starts_at'],
			'ends_at'        => (int) $row['ends_at'],
			'all_day'        => 1 === (int) $row['all_day'],
			'private'        => 1 === (int) $row['private'],
			'planned_hours'  => (float) $row['planned_hours'],
			'client_site_id' => (string) $row['client_site_id'],
			'client_id'      => (string) $row['client_id'],
			'off_support'    => 1 === (int) $row['off_support'],
			// Settled: somebody picked a client, or said what became of it. A
			// client Forge filled in by itself is nobody's pick (#657).
			'settled'        => ( '' !== (string) $row['client_site_id'] && '' === (string) ( $row['prefill'] ?? '' ) ) || Occurrence::SCHEDULED !== $status,
			'status'         => $status,
			'status_label'   => Occurrence::label( $status ),
			'ledger_state'   => (string) $row['ledger_state'],
			'held_marked_by' => (int) $row['held_marked_by'],
			'held_at'        => (int) $row['held_at'],
			'record_version' => (int) $row['record_version'],
			'people'         => $on,
			'counting'       => array_values( array_unique( $counting ) ),
		);
	}
}
