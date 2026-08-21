<?php
/**
 * People, once each.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;

/**
 * AUTH-6: one person, one account, one identity across every client. A user who
 * works with three clients is one row here and three memberships next door — not
 * three people who happen to share a name.
 *
 * That matters beyond tidiness. Capacity counts a person's committed hours
 * across everything they work on, so a duplicated person shows as two people at
 * half load. Attribution follows the row, so a duplicate splits somebody's
 * history in two. And offboarding is one action against one row; against
 * duplicates it is one action per copy, done correctly the first time and
 * forgotten the second.
 *
 * The rules that make it true are the unique index on the address and the check
 * below. Neither is enough alone: the check gives a usable refusal, the index
 * makes it true when two requests arrive at once.
 */
final class Users {

	/**
	 * Id prefix for a user.
	 */
	public const PREFIX = 'usr';

	/**
	 * Stores a new user.
	 *
	 * @param array<string, mixed> $values Validated values.
	 * @param int                  $author WordPress user id of the author.
	 * @return array<string, mixed>|null Null when the insert failed — most
	 *                                   likely the address is already somebody's.
	 */
	public static function create( array $values, int $author ): ?array {
		global $wpdb;

		$now = bwx_forge_now();

		$row = array(
			'id'             => Ids::create( self::PREFIX ),
			'email'          => (string) ( $values['email'] ?? '' ),
			'display_name'   => (string) ( $values['display_name'] ?? '' ),
			'status'         => (string) ( $values['status'] ?? 'active' ),
			'grants'         => Grants::format( (array) ( $values['grants'] ?? array() ) ),
			'wp_user_id'     => (int) ( $values['wp_user_id'] ?? 0 ),
			'created_at'     => $now,
			'updated_at'     => $now,
			'created_by'     => $author,
			'record_version' => 1,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		$inserted = $wpdb->insert( Schema::users_table(), $row, Formats::for_row( $row ) );

		if ( ! $inserted ) {
			return null;
		}

		return self::hydrate( $row );
	}

	/**
	 * One user.
	 *
	 * @param string $id User id.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		global $wpdb;

		$table = Schema::users_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * The person at an address, whatever their status.
	 *
	 * Inactive people are found too, deliberately: somebody coming back is the
	 * same person, and creating a second row for them is the failure this whole
	 * class exists to prevent.
	 *
	 * @param string $email Address, lower-cased.
	 * @return array<string, mixed>|null
	 */
	public static function by_email( string $email ): ?array {
		global $wpdb;

		$table = Schema::users_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", strtolower( $email ) ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * The Forge person behind a WordPress account.
	 *
	 * The join between the two is one column and no guessing. Matching on the
	 * email address instead would quietly make somebody who changed their
	 * WordPress address into a different person.
	 *
	 * @param int $wp_user_id WordPress user id.
	 * @return array<string, mixed>|null
	 */
	public static function by_wp_user( int $wp_user_id ): ?array {
		global $wpdb;

		if ( $wp_user_id <= 0 ) {
			return null;
		}

		$table = Schema::users_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE wp_user_id = %d", $wp_user_id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Every user, newest first.
	 *
	 * @param string|null $status Status to filter by, or null for all of them.
	 * @return array<int, array<string, mixed>>
	 */
	public static function all( ?string $status = 'active' ): array {
		global $wpdb;

		$table = Schema::users_table();

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
	 * @param string               $id           User id.
	 * @param array<string, mixed> $values       Validated values.
	 * @param int                  $sent_version Version the edit was made against.
	 * @return array<string, mixed>|null Null when the version did not match.
	 */
	public static function update( string $id, array $values, int $sent_version ): ?array {
		global $wpdb;

		$changes = array();

		foreach ( array( 'email', 'display_name', 'status' ) as $field ) {
			if ( array_key_exists( $field, $values ) ) {
				$changes[ $field ] = (string) $values[ $field ];
			}
		}

		if ( array_key_exists( 'wp_user_id', $values ) ) {
			$changes['wp_user_id'] = (int) $values['wp_user_id'];
		}

		/*
		 * #93. Written through Grants::format() rather than taken as given, so
		 * a value nobody defined cannot reach the column — a string stored here
		 * would be found later by something reading the column loosely, and
		 * would be authority nobody granted.
		 */
		if ( array_key_exists( 'grants', $values ) ) {
			$changes['grants'] = Grants::format( (array) $values['grants'] );
		}

		$changes['updated_at']     = bwx_forge_now();
		$changes['record_version'] = $sent_version + 1;

		// The version is in the WHERE, not checked and then written: two writes
		// arriving together would both read the same version and both believe
		// themselves current.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$changed = $wpdb->update(
			Schema::users_table(),
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
	 * Offboards somebody: the account is deactivated and every membership they
	 * hold goes with it (AUTH-6). Nothing is deleted, so everything they ever
	 * did is still attributed to them.
	 *
	 * Takes the rest of the edit with it, like Clients::deactivate(): a PATCH
	 * that deactivates is still a write of everything else it named, not a write
	 * of the status column alone.
	 *
	 * @param string               $id           User id.
	 * @param int                  $sent_version Version the change was made against.
	 * @param array<string, mixed> $values       The rest of the validated values.
	 * @return array<string, mixed>|null Null when the version did not match.
	 */
	public static function deactivate( string $id, int $sent_version, array $values = array() ): ?array {
		$values['status'] = 'inactive';

		$updated = self::update( $id, $values, $sent_version );

		if ( null === $updated ) {
			return null;
		}

		// After the user's own write, not before: a refused write must not have
		// already revoked somebody's access on the way to being refused.
		Memberships::deactivate_for_user( $id );

		return $updated;
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
			'email'          => (string) $row['email'],
			'display_name'   => (string) $row['display_name'],
			'status'         => (string) $row['status'],
			'grants'         => (string) ( $row['grants'] ?? '' ),
			'wp_user_id'     => (int) $row['wp_user_id'],
			'created_at'     => (int) $row['created_at'],
			'updated_at'     => (int) $row['updated_at'],
			'created_by'     => (int) $row['created_by'],
			'record_version' => (int) $row['record_version'],
		);
	}
}
