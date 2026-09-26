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
	 * Every WordPress account somebody here already holds (#292).
	 *
	 * Offboarded people are counted too. Their account is still theirs, and
	 * offering it to somebody else to claim is how one person's history becomes
	 * another person's.
	 *
	 * @return array<int, int> WordPress user ids.
	 */
	public static function linked_wp_ids(): array {
		global $wpdb;

		$table = Schema::users_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$ids = $wpdb->get_col( "SELECT wp_user_id FROM {$table} WHERE wp_user_id > 0" );

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Every user, by name.
	 *
	 * @param string|null $status Status to filter by, or null for all of them.
	 * @return array<int, array<string, mixed>>
	 */
	public static function all( ?string $status = 'active' ): array {
		global $wpdb;

		$table = Schema::users_table();

		if ( null === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY display_name ASC, created_at DESC", ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY display_name ASC, created_at DESC", $status ), ARRAY_A );
		}

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Our own people: everyone active who is not one of a client's people.
	 *
	 * The seats, the chores and the meetings offer these (2026-09-20). Before
	 * this every picker listed everybody, and a client's administrator could
	 * be put down as the person doing the work. Somebody is a client's person
	 * when every membership they hold is on the client's side; a person with
	 * a staff or administrator membership anywhere is ours, and so is one
	 * added on the People screen who has not been given access yet.
	 *
	 * @param array<string, array<int, array<string, mixed>>>|null $by_client Active memberships grouped by
	 *                                                                  client, when the caller has
	 *                                                                  already read them.
	 * @return array<int, array<string, mixed>>
	 */
	public static function ours( ?array $by_client = null ): array {
		$client_side = array();
		$our_side    = array();

		foreach ( $by_client ?? Memberships::by_client( 'active' ) as $held ) {
			foreach ( $held as $membership ) {
				$user_id = (string) $membership['user_id'];

				if ( Roles::is_client_side( (string) $membership['role'] ) ) {
					$client_side[ $user_id ] = true;
				} else {
					$our_side[ $user_id ] = true;
				}
			}
		}

		return array_values(
			array_filter(
				self::all( 'active' ),
				static fn( array $person ): bool => ! isset( $client_side[ (string) $person['id'] ] ) || isset( $our_side[ (string) $person['id'] ] )
			)
		);
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
	 * Whether anything has happened under this person's name.
	 *
	 * Work they hold a seat on, an onboarding step they review, a meeting they
	 * host, a client contact they are: each is a record that would point at
	 * nobody if the row went. Memberships and availability are not counted —
	 * they are the person's own settings, and go with them.
	 *
	 * @param string $id User id.
	 * @return bool
	 */
	public static function has_history( string $id ): bool {
		global $wpdb;

		$columns = array(
			Schema::work_items_table()       => array( 'primary_user_id', 'reviewer_id', 'deliverer_id' ),
			Schema::onboarding_steps_table() => array( 'reviewer_id' ),
			Schema::meeting_series_table()   => array( 'host_user_id' ),
			Schema::contacts_table()         => array( 'user_id' ),
		);

		foreach ( $columns as $table => $fields ) {
			foreach ( $fields as $field ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own tables and column names from the list above, never from input.
				$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$field} = %s", $id ) );

				if ( $found > 0 ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Removes a person who was never really here.
	 *
	 * NOTIF-5 says nothing goes while it carries history, and this is the one
	 * case that carries none: somebody added by mistake, or twice, or before
	 * they had an account and never used since. Offboarding is still the answer
	 * for everyone else. Their WordPress account is untouched either way.
	 *
	 * The memberships and availability that were theirs go with the row: they
	 * describe nobody once the row has gone.
	 *
	 * @param string $id User id.
	 * @return bool False when they carry history, or the row was not there.
	 */
	public static function delete( string $id ): bool {
		global $wpdb;

		if ( self::has_history( $id ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		$removed = (bool) $wpdb->delete( Schema::users_table(), array( 'id' => $id ), array( '%s' ) );

		if ( ! $removed ) {
			return false;
		}

		foreach ( array( Schema::memberships_table(), Schema::availability_patterns_table(), Schema::unavailability_table() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
			$wpdb->delete( $table, array( 'user_id' => $id ), array( '%s' ) );
		}

		return true;
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
