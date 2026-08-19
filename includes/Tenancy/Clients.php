<?php
/**
 * Client records.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

use Blueworx\Forge\Data\Schema;

/**
 * The client: identity, people and memberships (ARCH-3). It groups sites and
 * owns nothing that is scoped — no work, no hours, no packages, no onboarding.
 * Those belong to the site beneath it, and a field for one of them appearing
 * here later is the mistake ARCH-3 exists to prevent.
 *
 * Two rules live here rather than in callers. There is no delete: a client is
 * deactivated and kept (NOTIF-5). And every update quotes the version it was
 * made against, refused in the UPDATE's own WHERE so two writes racing cannot
 * both believe they were current (ARCH-5).
 */
final class Clients {

	/**
	 * Id prefix for a client.
	 */
	public const PREFIX = 'cli';

	/**
	 * Stores a new client.
	 *
	 * @param array<string, mixed> $values Validated values.
	 * @param int                  $author WordPress user id of the author.
	 * @return array<string, mixed> The stored row.
	 */
	public static function create( array $values, int $author ): array {
		global $wpdb;

		$now = bwx_forge_now();
		$id  = Ids::create( self::PREFIX );

		$row = array(
			'id'             => $id,
			'display_name'   => (string) ( $values['display_name'] ?? '' ),
			'legal_name'     => (string) ( $values['legal_name'] ?? '' ),
			'status'         => (string) ( $values['status'] ?? 'active' ),
			'timezone'       => (string) ( $values['timezone'] ?? 'UTC' ),
			'email_domains'  => wp_json_encode( $values['email_domains'] ?? array() ),
			'created_at'     => $now,
			'updated_at'     => $now,
			'created_by'     => $author,
			'record_version' => 1,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		$wpdb->insert( Schema::clients_table(), $row );

		return self::hydrate( $row );
	}

	/**
	 * One client.
	 *
	 * @param string $id Client id.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		global $wpdb;

		$table = Schema::clients_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Every client, newest first.
	 *
	 * @param string|null $status Status to filter by, or null for all of them.
	 * @return array<int, array<string, mixed>>
	 */
	public static function all( ?string $status = 'active' ): array {
		global $wpdb;

		$table = Schema::clients_table();

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
	 * Applies an edit, refusing one made against a version that has moved.
	 *
	 * @param string               $id           Client id.
	 * @param array<string, mixed> $values       Validated values.
	 * @param int                  $sent_version Version the edit was made against.
	 * @return array<string, mixed>|null Null when the version did not match, or
	 *                                   there is no such client.
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
			Schema::clients_table(),
			$changes,
			array(
				'id'             => $id,
				'record_version' => $sent_version,
			)
		);

		if ( ! $changed ) {
			return null;
		}

		return self::get( $id );
	}

	/**
	 * Deactivates a client, and every site under it.
	 *
	 * @param string $id           Client id.
	 * @param int    $sent_version Version the change was made against.
	 * @return array<string, mixed>|null Null when the version did not match.
	 */
	public static function deactivate( string $id, int $sent_version ): ?array {
		$client = self::update( $id, array( 'status' => 'inactive' ), $sent_version );

		if ( null === $client ) {
			return null;
		}

		// A client nobody works for has no site anybody works on. Leaving the
		// sites active would leave their work in default views.
		ClientSites::deactivate_for_client( $id );

		return $client;
	}

	/**
	 * The columns an edit may set.
	 *
	 * @param array<string, mixed> $values Validated values.
	 * @return array<string, mixed>
	 */
	private static function writable( array $values ): array {
		$changes = array();

		foreach ( array( 'display_name', 'legal_name', 'status', 'timezone' ) as $field ) {
			if ( array_key_exists( $field, $values ) ) {
				$changes[ $field ] = (string) $values[ $field ];
			}
		}

		if ( array_key_exists( 'email_domains', $values ) ) {
			$changes['email_domains'] = wp_json_encode( $values['email_domains'] );
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
		$domains = json_decode( (string) ( $row['email_domains'] ?? '[]' ), true );

		return array(
			'id'             => (string) $row['id'],
			'display_name'   => (string) $row['display_name'],
			'legal_name'     => (string) $row['legal_name'],
			'status'         => (string) $row['status'],
			'timezone'       => (string) $row['timezone'],
			'email_domains'  => is_array( $domains ) ? $domains : array(),
			'created_at'     => (int) $row['created_at'],
			'updated_at'     => (int) $row['updated_at'],
			'created_by'     => (int) $row['created_by'],
			'record_version' => (int) $row['record_version'],
		);
	}
}
