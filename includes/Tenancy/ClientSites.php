<?php
/**
 * Client site records.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;

/**
 * A site beneath a client (ARCH-3). Everything scoped to a single engagement —
 * work, hours, packages, onboarding — belongs here rather than on the client
 * above it.
 *
 * Two rules live here rather than in callers. There is no delete: a site is
 * deactivated and kept (NOTIF-5). And every update quotes the version it was
 * made against, refused in the UPDATE's own WHERE so two writes racing cannot
 * both believe they were current (ARCH-5).
 */
final class ClientSites {

	/**
	 * Id prefix for a client site.
	 */
	public const PREFIX = 'cst';

	/**
	 * Stores a new client site.
	 *
	 * @param string               $client_id Owning client id.
	 * @param array<string, mixed> $values    Validated values.
	 * @param int                  $author    WordPress user id of the author.
	 * @return array<string, mixed>|null The stored row, or null when the insert
	 *                                   itself failed and nothing was written.
	 */
	public static function create( string $client_id, array $values, int $author ): ?array {
		global $wpdb;

		$now = bwx_forge_now();
		$id  = Ids::create( self::PREFIX );

		$row = array(
			'id'             => $id,
			'client_id'      => $client_id,
			'name'           => (string) ( $values['name'] ?? '' ),
			'url'            => (string) ( $values['url'] ?? '' ),
			'status'         => (string) ( $values['status'] ?? 'active' ),
			'created_at'     => $now,
			'updated_at'     => $now,
			'created_by'     => $author,
			'record_version' => 1,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		$inserted = $wpdb->insert( Schema::sites_table(), $row, Formats::for_row( $row ) );

		if ( ! $inserted ) {
			return null;
		}

		return self::hydrate( $row );
	}

	/**
	 * One client site.
	 *
	 * @param string $id Client site id.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		global $wpdb;

		$table = Schema::sites_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Every site on every client, newest first.
	 *
	 * The board needs one list to choose from, and building it by asking each
	 * client in turn is a query per client on a screen that has not yet drawn
	 * anything. One read instead.
	 *
	 * @param string|null $status Status to filter by, or null for all of them.
	 * @return array<int, array<string, mixed>>
	 */
	public static function all( ?string $status = 'active' ): array {
		global $wpdb;

		$table = Schema::sites_table();

		if ( null === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC", $status ), ARRAY_A );
		}

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Every site under a client, newest first.
	 *
	 * @param string      $client_id Owning client id.
	 * @param string|null $status    Status to filter by, or null for all of them.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_client( string $client_id, ?string $status = 'active' ): array {
		global $wpdb;

		$table = Schema::sites_table();

		if ( null === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_id = %s ORDER BY created_at DESC", $client_id ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_id = %s AND status = %s ORDER BY created_at DESC", $client_id, $status ), ARRAY_A );
		}

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Applies an edit, refusing one made against a version that has moved.
	 *
	 * @param string               $id           Client site id.
	 * @param array<string, mixed> $values       Validated values.
	 * @param int                  $sent_version Version the edit was made against.
	 * @return array<string, mixed>|null Null when the version did not match, or
	 *                                   there is no such site.
	 */
	public static function update( string $id, array $values, int $sent_version ): ?array {
		global $wpdb;

		$changes = self::writable( $values );

		$changes['updated_at']     = bwx_forge_now();
		$changes['record_version'] = $sent_version + 1;

		/*
		 * The version is in the WHERE, not checked and then written: two writes
		 * arriving together would both read the same current version and both
		 * believe themselves current. Here the second changes no rows.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$changed = $wpdb->update(
			Schema::sites_table(),
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

		/*
		 * The cascade lives here rather than in deactivate(), because a site is
		 * closed both ways: by that method and by a PATCH that happens to set
		 * status. Putting it there would mean one of the two doors quietly left
		 * people holding access to a closed site.
		 *
		 * Only memberships scoped to this site alone. Ones covering the whole
		 * client were never about this site, and ending them would cut somebody
		 * off from the client's other sites because one of them closed.
		 */
		if ( 'inactive' === (string) ( $changes['status'] ?? '' ) ) {
			Memberships::deactivate_for_site( $id );
		}

		return self::get( $id );
	}

	/**
	 * Deactivates a single site.
	 *
	 * @param string $id           Client site id.
	 * @param int    $sent_version Version the change was made against.
	 * @return array<string, mixed>|null Null when the version did not match.
	 */
	public static function deactivate( string $id, int $sent_version ): ?array {
		return self::update( $id, array( 'status' => 'inactive' ), $sent_version );
	}

	/**
	 * Deactivates every site under a client.
	 *
	 * No version is quoted: this is not somebody's edit of a site, it is the
	 * consequence of their edit of the client, and it must not be refusable
	 * because a site moved underneath it.
	 *
	 * @param string $client_id Client id.
	 * @return int Rows changed.
	 */
	public static function deactivate_for_client( string $client_id ): int {
		global $wpdb;

		$table = Schema::sites_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$changed = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
				"UPDATE {$table} SET status = 'inactive', updated_at = %d, record_version = record_version + 1 WHERE client_id = %s AND status = 'active'",
				bwx_forge_now(),
				$client_id
			)
		);

		return (int) $changed;
	}

	/**
	 * The columns an edit may set.
	 *
	 * @param array<string, mixed> $values Validated values.
	 * @return array<string, mixed>
	 */
	private static function writable( array $values ): array {
		$changes = array();

		foreach ( array( 'name', 'url', 'status' ) as $field ) {
			if ( array_key_exists( $field, $values ) ) {
				$changes[ $field ] = (string) $values[ $field ];
			}
		}

		return $changes;
	}

	/**
	 * Turns a database row into the record the rest of the plugin uses.
	 *
	 * @param array<string, mixed> $row Row as stored.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		return array(
			'id'             => (string) $row['id'],
			'client_id'      => (string) $row['client_id'],
			'name'           => (string) $row['name'],
			'url'            => (string) $row['url'],
			'status'         => (string) $row['status'],
			'created_at'     => (int) $row['created_at'],
			'updated_at'     => (int) $row['updated_at'],
			'created_by'     => (int) $row['created_by'],
			'record_version' => (int) $row['record_version'],
		);
	}
}
