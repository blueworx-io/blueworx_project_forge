<?php
/**
 * The client remembered for a recurring Google meeting.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Google;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Meetings\Occurrence;

/**
 * #657. Once somebody picks a client for one meeting in a series, the later
 * ones arrive with it filled in. Only a change of client carries forward: a
 * change to the support tick alone stays on that one meeting (Luke,
 * 2026-10-26). Only a person's pick is remembered, never studio time Forge
 * filled in by itself.
 */
final class SeriesChoice {

	/**
	 * A series' remembered choice.
	 *
	 * @param string $series_key The series.
	 * @return array{client_site_id: string, client_id: string, off_support: bool}|null
	 */
	public static function get( string $series_key ): ?array {
		global $wpdb;

		if ( '' === $series_key ) {
			return null;
		}

		$table = Schema::google_series_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $series_key ), ARRAY_A );

		return is_array( $row )
			? array(
				'client_site_id' => (string) $row['client_site_id'],
				'client_id'      => (string) $row['client_id'],
				'off_support'    => 1 === (int) $row['off_support'],
			)
			: null;
	}

	/**
	 * Remembers a person's pick for a meeting's series, and fills it in on
	 * that series' later meetings nobody has dealt with.
	 *
	 * @param array<string, mixed> $meeting     The meeting picked for, hydrated.
	 * @param string               $site_id     The site picked.
	 * @param string               $client_id   That site's client.
	 * @param bool                 $off_support Whether its time comes off support.
	 * @param int                  $actor       Who picked it.
	 * @return int How many later meetings changed.
	 */
	public static function remember( array $meeting, string $site_id, string $client_id, bool $off_support, int $actor ): int {
		global $wpdb;

		$series_key = (string) ( $meeting['series_key'] ?? '' );
		$known      = self::get( $series_key );

		if ( '' === $series_key || ( null !== $known && $known['client_site_id'] === $site_id ) ) {
			return 0;
		}

		$now   = bwx_forge_now();
		$table = Schema::google_series_table();

		if ( null === $known ) {
			$row = array(
				'id'             => $series_key,
				'client_site_id' => $site_id,
				'client_id'      => $client_id,
				'off_support'    => $off_support ? 1 : 0,
				'created_at'     => $now,
				'updated_at'     => $now,
				'created_by'     => $actor,
				'record_version' => 1,
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
			$wpdb->insert( $table, $row, Formats::for_row( $row ) );
		} else {
			$changes = array(
				'client_site_id' => $site_id,
				'client_id'      => $client_id,
				'off_support'    => $off_support ? 1 : 0,
				'updated_at'     => $now,
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET client_site_id = %s, client_id = %s, off_support = %d, updated_at = %d, record_version = record_version + 1 WHERE id = %s", $changes['client_site_id'], $changes['client_id'], $changes['off_support'], $now, $series_key ) );
		}

		$choice  = self::get( $series_key );
		$changed = 0;

		foreach ( self::later( $series_key, (string) $meeting['on'], (string) $meeting['id'] ) as $later ) {
			$fill = Meetings::prefill_changes( $later, $choice, false, '', '' );

			if ( array() === $fill || null === Meetings::fill( $later, $fill ) ) {
				continue;
			}

			$fresh = Meetings::get( (string) $later['id'] );

			if ( null !== $fresh ) {
				Hours::settle_one( $fresh, $actor );
			}

			++$changed;
		}

		return $changed;
	}

	/**
	 * The series' meetings on or after a day, other than one.
	 *
	 * @param string $series_key The series.
	 * @param string $from       YYYY-MM-DD.
	 * @param string $except     A meeting id to leave out.
	 * @return array<int, array<string, mixed>> Raw rows.
	 */
	private static function later( string $series_key, string $from, string $except ): array {
		global $wpdb;

		$table = Schema::google_meetings_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE series_key = %s AND on_date >= %s AND id <> %s AND status = %s ORDER BY on_date ASC", $series_key, $from, $except, Occurrence::SCHEDULED ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}
}
