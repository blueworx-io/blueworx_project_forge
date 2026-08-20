<?php
/**
 * Who works with whom, and as what.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;

/**
 * The join between a global user (AUTH-6) and a client, carrying the access
 * role. Nothing about somebody's access lives on their user row: that is what
 * lets one person be a client administrator here and a viewer there without
 * being two people.
 *
 * A membership names a client always and a site sometimes. No site named means
 * every site under that client; a named site means that site alone. Both exist
 * because ARCH-3 makes the site the scoping unit while AUTH-6 describes
 * membership per client — a client administrator over a two-site client should
 * not need two rows that can drift apart, and somebody brought in for one site
 * should not quietly gain the other.
 *
 * There is no delete (NOTIF-5). Access ends by deactivation, and the row stays
 * so that what somebody did while they held it still resolves.
 */
final class Memberships {

	/**
	 * Id prefix for a membership.
	 */
	public const PREFIX = 'mem';

	/**
	 * Stores a new membership.
	 *
	 * @param string               $user_id   The person.
	 * @param string               $client_id The client.
	 * @param array<string, mixed> $values    Validated values.
	 * @param int                  $author    WordPress user id of the author.
	 * @return array<string, mixed>|null Null when the insert failed — most
	 *                                   likely they already hold one here.
	 */
	public static function create( string $user_id, string $client_id, array $values, int $author ): ?array {
		global $wpdb;

		$now = bwx_forge_now();

		$row = array(
			'id'             => Ids::create( self::PREFIX ),
			'user_id'        => $user_id,
			'client_id'      => $client_id,
			'client_site_id' => (string) ( $values['client_site_id'] ?? '' ),
			'role'           => (string) ( $values['role'] ?? '' ),
			'status'         => (string) ( $values['status'] ?? 'active' ),
			'created_at'     => $now,
			'updated_at'     => $now,
			'created_by'     => $author,
			'record_version' => 1,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		$inserted = $wpdb->insert( Schema::memberships_table(), $row, Formats::for_row( $row ) );

		if ( ! $inserted ) {
			return null;
		}

		return self::hydrate( $row );
	}

	/**
	 * One membership.
	 *
	 * @param string $id Membership id.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		global $wpdb;

		$table = Schema::memberships_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Everything one person holds, across every client.
	 *
	 * This is the query #90 exists to make answerable, and the one a per-client
	 * account model cannot answer at all.
	 *
	 * @param string      $user_id User id.
	 * @param string|null $status  Status to filter by, or null for all of them.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_user( string $user_id, ?string $status = 'active' ): array {
		return self::query( 'user_id', $user_id, $status );
	}

	/**
	 * Everyone who holds something on one client.
	 *
	 * @param string      $client_id Client id.
	 * @param string|null $status    Status to filter by, or null for all of them.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_client( string $client_id, ?string $status = 'active' ): array {
		return self::query( 'client_id', $client_id, $status );
	}

	/**
	 * Every membership there is, grouped by client.
	 *
	 * For the clients screen, which shows every client at once: asking per
	 * client turned one page into a query per row, and a studio with eighty
	 * clients felt it immediately.
	 *
	 * @param string|null $status Status to filter by, or null for all of them.
	 * @return array<string, array<int, array<string, mixed>>> Client id to memberships.
	 */
	public static function by_client( ?string $status = 'active' ): array {
		global $wpdb;

		$table = Schema::memberships_table();

		if ( null === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC", $status ), ARRAY_A );
		}

		$grouped = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$record = self::hydrate( $row );

			$grouped[ $record['client_id'] ][] = $record;
		}

		return $grouped;
	}

	/**
	 * Applies an edit, refusing one made against a version that has moved.
	 *
	 * The user and the client are not editable. Moving a membership to another
	 * person or another client is not an edit of that membership — it is ending
	 * one and starting another, and doing it as an edit would quietly rewrite
	 * who had access to what, with no record that it had ever been otherwise.
	 *
	 * @param string               $id           Membership id.
	 * @param array<string, mixed> $values       Validated values.
	 * @param int                  $sent_version Version the edit was made against.
	 * @return array<string, mixed>|null Null when the version did not match.
	 */
	public static function update( string $id, array $values, int $sent_version ): ?array {
		global $wpdb;

		$changes = array();

		foreach ( array( 'role', 'status', 'client_site_id' ) as $field ) {
			if ( array_key_exists( $field, $values ) ) {
				$changes[ $field ] = (string) $values[ $field ];
			}
		}

		$changes['updated_at']     = bwx_forge_now();
		$changes['record_version'] = $sent_version + 1;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$changed = $wpdb->update(
			Schema::memberships_table(),
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
	 * Ends one membership.
	 *
	 * @param string $id           Membership id.
	 * @param int    $sent_version Version the change was made against.
	 * @return array<string, mixed>|null Null when the version did not match.
	 */
	public static function deactivate( string $id, int $sent_version ): ?array {
		return self::update( $id, array( 'status' => 'inactive' ), $sent_version );
	}

	/**
	 * Ends every membership one person holds. This is AUTH-6 offboarding: one
	 * action, every client.
	 *
	 * @param string $user_id User id.
	 * @return int Rows changed.
	 */
	public static function deactivate_for_user( string $user_id ): int {
		return self::deactivate_where( 'user_id = %s', $user_id );
	}

	/**
	 * Ends every membership on one client, when that client is closed.
	 *
	 * @param string $client_id Client id.
	 * @return int Rows changed.
	 */
	public static function deactivate_for_client( string $client_id ): int {
		return self::deactivate_where( 'client_id = %s', $client_id );
	}

	/**
	 * Ends the memberships scoped to one site, when that site is closed.
	 *
	 * Memberships covering the whole client are deliberately untouched: they
	 * were never about this site, and ending them would quietly cut somebody off
	 * from a client's other sites because one of them closed.
	 *
	 * @param string $client_site_id Client site id.
	 * @return int Rows changed.
	 */
	public static function deactivate_for_site( string $client_site_id ): int {
		if ( '' === $client_site_id ) {
			return 0;
		}

		return self::deactivate_where( 'client_site_id = %s', $client_site_id );
	}

	/**
	 * The shared read.
	 *
	 * @param string      $column Column to match — never user input.
	 * @param string      $value  Value to match.
	 * @param string|null $status Status filter, or null for all.
	 * @return array<int, array<string, mixed>>
	 */
	private static function query( string $column, string $value, ?string $status ): array {
		global $wpdb;

		$table = Schema::memberships_table();

		if ( null === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column are this class's own literals.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = %s ORDER BY created_at DESC", $value ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column are this class's own literals.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = %s AND status = %s ORDER BY created_at DESC", $value, $status ), ARRAY_A );
		}

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * The shared cascade.
	 *
	 * No version is quoted, matching the site cascade in #88: this is the
	 * consequence of somebody's edit elsewhere rather than an edit of its own,
	 * and must not be refusable because a row moved underneath it.
	 *
	 * @param string $where A WHERE fragment with one %s — never user input.
	 * @param string $value The value for it.
	 * @return int Rows changed.
	 */
	private static function deactivate_where( string $where, string $value ): int {
		global $wpdb;

		$table = Schema::memberships_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$changed = $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The WHERE fragment carries the second placeholder, which the sniff cannot see.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and WHERE fragment are this class's own literals, never input.
				"UPDATE {$table} SET status = 'inactive', updated_at = %d, record_version = record_version + 1 WHERE {$where} AND status = 'active'",
				bwx_forge_now(),
				$value
			)
		);

		return (int) $changed;
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
			'user_id'        => (string) $row['user_id'],
			'client_id'      => (string) $row['client_id'],
			'client_site_id' => (string) $row['client_site_id'],
			'role'           => (string) $row['role'],
			'role_label'     => Roles::label( (string) $row['role'] ),
			'status'         => (string) $row['status'],
			'created_at'     => (int) $row['created_at'],
			'updated_at'     => (int) $row['updated_at'],
			'created_by'     => (int) $row['created_by'],
			'record_version' => (int) $row['record_version'],
		);
	}
}
