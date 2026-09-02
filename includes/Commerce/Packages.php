<?php
/**
 * The packages on offer, and every version of them there has ever been.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Commerce;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Tenancy\Ids;

/**
 * #145, COMM-1: **editing a package never rewrites what anybody was sold.**
 *
 * Two rows for one idea, and the split is the whole feature. The catalogue row
 * is the package as it stands today — its name, whether it is still offered,
 * where it sits in the list — and it can be edited freely. The version rows are
 * what it *was*, each written once and never touched again. An assignment
 * (#146) will point at a version, so a price rise next spring cannot reach back
 * and change the terms of somebody who signed last autumn.
 *
 * The alternative — one editable row, with assignments pointing at the package
 * — is the obvious implementation and it is wrong in a way that only shows up
 * in an argument with a client. By then the number they agreed to is gone, and
 * there is nothing in the record to say what it had been.
 *
 * **A save that changes nothing does not mint a version.** Six identical
 * versions in a history is a history nobody reads, and "which one did they buy"
 * stops having a useful answer. {@see Terms::differ()} decides.
 *
 * The tables are global. A package is the studio's — every site is offered the
 * same ones — so nothing here is tenant-scoped, deliberately, and callers are
 * crossing that boundary knowingly.
 */
final class Packages {

	/**
	 * Id prefix for a catalogue entry.
	 */
	public const PREFIX = 'pkg';

	/**
	 * Id prefix for one frozen version of one.
	 */
	public const VERSION_PREFIX = 'pkv';

	/**
	 * How far apart consecutive packages sit in the list.
	 *
	 * Ten rather than one, so a package can be dropped between two others
	 * without renumbering the whole catalogue. Reordering rewrites them all
	 * anyway; the gap is what keeps a single insert cheap.
	 */
	public const POSITION_STEP = 10;

	/* ------------------------------------------------------------ writing */

	/**
	 * Adds a package, and its first version.
	 *
	 * Both or neither. A catalogue row with no version is a package nobody can
	 * be sold and nothing can explain, so if the version does not write the
	 * catalogue row goes with it.
	 *
	 * @param array<string, mixed> $values Terms; see {@see Terms::FIELDS}.
	 * @param int                  $author Who added it.
	 * @return array<string, mixed>|null Null when it could not be written or
	 *                                   the terms were refused.
	 */
	public static function create( array $values, int $author ): ?array {
		global $wpdb;

		$terms = Terms::sanitise( $values );

		if ( '' !== Terms::refuse( $terms ) ) {
			return null;
		}

		$now = bwx_forge_now();
		$row = array(
			'id'             => Ids::create( self::PREFIX ),
			'name'           => (string) $terms['name'],
			'status'         => Terms::ACTIVE,
			'position'       => self::next_position(),
			'retired_at'     => 0,
			'created_at'     => $now,
			'updated_at'     => $now,
			'created_by'     => $author,
			'record_version' => 1,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		if ( ! $wpdb->insert( Schema::packages_table(), $row, Formats::for_row( $row ) ) ) {
			return null;
		}

		if ( null === self::write_version( (string) $row['id'], 1, $terms, $author ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
			$wpdb->delete( Schema::packages_table(), array( 'id' => $row['id'] ), array( '%s' ) );

			return null;
		}

		return self::get( (string) $row['id'] );
	}

	/**
	 * Changes what a package offers, from now on.
	 *
	 * Appends the next version and leaves every earlier one exactly where it
	 * is. Returns the package either way — a save that changed nothing is not a
	 * failure, it simply did not need a version.
	 *
	 * @param string               $package_id The package.
	 * @param array<string, mixed> $values     Terms; see {@see Terms::FIELDS}.
	 * @param int                  $author     Who changed it.
	 * @return array<string, mixed>|null Null when there is no such package or
	 *                                   the terms were refused.
	 */
	public static function revise( string $package_id, array $values, int $author ): ?array {
		global $wpdb;

		$package = self::get( $package_id );
		$current = self::current_version( $package_id );

		if ( null === $package || null === $current ) {
			return null;
		}

		$terms = Terms::sanitise( $values );

		if ( '' !== Terms::refuse( $terms ) ) {
			return null;
		}

		if ( ! Terms::differ( $current, $terms ) ) {
			return $package;
		}

		if ( null === self::write_version( $package_id, (int) $current['version'] + 1, $terms, $author ) ) {
			return null;
		}

		/*
		 * The catalogue row's name follows the newest version, because that row
		 * is the list somebody browses. The version keeps its own copy, which
		 * is what a client's paperwork reads from — so renaming a package
		 * changes the shelf and not the receipt.
		 */
		$changes = array(
			'name'           => (string) $terms['name'],
			'updated_at'     => bwx_forge_now(),
			'record_version' => (int) $package['record_version'] + 1,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update( Schema::packages_table(), $changes, array( 'id' => $package_id ), Formats::for_row( $changes ), array( '%s' ) );

		return self::get( $package_id );
	}

	/**
	 * Takes a package off the shelf, or puts it back.
	 *
	 * Retiring sells no more of it and changes nothing for anybody already on
	 * it — which is the only reason retiring exists rather than deleting. Both
	 * directions, because a package retired by mistake is a mistake somebody
	 * should be able to undo in the same screen.
	 *
	 * @param string $package_id The package.
	 * @param string $status     One of {@see Terms::STATUSES}.
	 * @return array<string, mixed>|null Null when there is no such package or
	 *                                   the status is not one of the two.
	 */
	public static function set_status( string $package_id, string $status ): ?array {
		global $wpdb;

		$package = self::get( $package_id );

		if ( null === $package || ! Terms::is_status( $status ) ) {
			return null;
		}

		$now     = bwx_forge_now();
		$changes = array(
			'status'         => $status,

			// When it came off the shelf, kept so "how long has nobody been
			// able to buy this" is answerable. Cleared on the way back, so it
			// always means the current retirement rather than an old one.
			'retired_at'     => Terms::RETIRED === $status ? $now : 0,
			'updated_at'     => $now,
			'record_version' => (int) $package['record_version'] + 1,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update( Schema::packages_table(), $changes, array( 'id' => $package_id ), Formats::for_row( $changes ), array( '%s' ) );

		return self::get( $package_id );
	}

	/**
	 * Puts the catalogue in the given order.
	 *
	 * Anything left out keeps its place after everything named, in the order it
	 * already had. A caller that forgets a package should not have it vanish to
	 * the top of the list.
	 *
	 * @param array<int, string> $ordered_ids Package ids, first to last.
	 * @return int How many were moved.
	 */
	public static function reorder( array $ordered_ids ): int {
		global $wpdb;

		$known  = array_column( self::all(), 'id' );
		$wanted = array_values(
			array_filter(
				array_unique( array_map( 'strval', $ordered_ids ) ),
				static fn( string $id ): bool => in_array( $id, $known, true )
			)
		);

		$rest = array_values( array_diff( $known, $wanted ) );
		$at   = 0;
		$done = 0;

		foreach ( array_merge( $wanted, $rest ) as $id ) {
			$at += self::POSITION_STEP;

			$changes = array(
				'position'   => $at,
				'updated_at' => bwx_forge_now(),
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
			$moved = $wpdb->update( Schema::packages_table(), $changes, array( 'id' => $id ), Formats::for_row( $changes ), array( '%s' ) );

			$done += false === $moved ? 0 : 1;
		}

		return $done;
	}

	/* ------------------------------------------------------------ reading */

	/**
	 * One package.
	 *
	 * @param string $package_id The package.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $package_id ): ?array {
		global $wpdb;

		$table = Schema::packages_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $package_id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * The catalogue, in its own order.
	 *
	 * @param string|null $status One of {@see Terms::STATUSES}, or null for all.
	 * @return array<int, array<string, mixed>>
	 */
	public static function all( ?string $status = null ): array {
		global $wpdb;

		$table = Schema::packages_table();

		if ( null === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY position ASC, created_at ASC", ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY position ASC, created_at ASC", $status ), ARRAY_A );
		}

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * The version of a package in force now — the newest one.
	 *
	 * @param string $package_id The package.
	 * @return array<string, mixed>|null
	 */
	public static function current_version( string $package_id ): ?array {
		global $wpdb;

		$table = Schema::package_versions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE package_id = %s ORDER BY version DESC LIMIT 1", $package_id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate_version( $row ) : null;
	}

	/**
	 * One frozen version, by its own id.
	 *
	 * This is the read an assignment makes (#146), and it is why the version
	 * has an id of its own rather than being addressed by package and number.
	 * An assignment holds one string, and that string can only ever mean one
	 * set of terms.
	 *
	 * @param string $version_id The version.
	 * @return array<string, mixed>|null
	 */
	public static function version( string $version_id ): ?array {
		global $wpdb;

		$table = Schema::package_versions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $version_id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate_version( $row ) : null;
	}

	/**
	 * Every version of one package, newest first.
	 *
	 * @param string $package_id The package.
	 * @return array<int, array<string, mixed>>
	 */
	public static function versions_for( string $package_id ): array {
		global $wpdb;

		$table = Schema::package_versions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE package_id = %s ORDER BY version DESC", $package_id ), ARRAY_A );

		return array_map( array( self::class, 'hydrate_version' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * The current version of every package named, keyed by package id.
	 *
	 * One query rather than one per package, so a catalogue of twenty does not
	 * cost twenty round trips to draw.
	 *
	 * @param array<int, string> $package_ids The packages.
	 * @return array<string, array<string, mixed>>
	 */
	public static function current_versions( array $package_ids ): array {
		$current = array();

		foreach ( self::versions_of( $package_ids ) as $version ) {
			$of = (string) $version['package_id'];

			// Newest first out of the query, so the first one seen for a
			// package is the one in force and later rows are its history.
			if ( ! isset( $current[ $of ] ) ) {
				$current[ $of ] = $version;
			}
		}

		return $current;
	}

	/* ------------------------------------------------------------ private */

	/**
	 * Every version of several packages, newest first within each.
	 *
	 * @param array<int, string> $package_ids The packages.
	 * @return array<int, array<string, mixed>>
	 */
	private static function versions_of( array $package_ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'strval', $package_ids ) ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$table = Schema::package_versions_table();
		$slots = implode( ', ', array_fill( 0, count( $ids ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name cannot be a placeholder, and the id placeholders are built above from the ids themselves; every value is still prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE package_id IN ({$slots}) ORDER BY package_id ASC, version DESC", $ids ), ARRAY_A );

		return array_map( array( self::class, 'hydrate_version' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Writes one frozen version.
	 *
	 * @param string               $package_id The package.
	 * @param int                  $number     Which version this is.
	 * @param array<string, mixed> $terms      Sanitised terms.
	 * @param int                  $author     Who wrote it.
	 * @return array<string, mixed>|null Null when it could not be written.
	 */
	private static function write_version( string $package_id, int $number, array $terms, int $author ): ?array {
		global $wpdb;

		$row = array(
			'id'              => Ids::create( self::VERSION_PREFIX ),
			'package_id'      => $package_id,
			'version'         => $number,
			'name'            => (string) $terms['name'],
			'hours'           => (float) $terms['hours'],
			'price'           => (int) $terms['price'],
			'currency'        => (string) $terms['currency'],
			'validity_months' => (int) $terms['validity_months'],
			'terms'           => (string) $terms['terms'],
			'created_at'      => bwx_forge_now(),
			'created_by'      => $author,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$written = $wpdb->insert( Schema::package_versions_table(), $row, Formats::for_row( $row ) );

		return $written ? self::hydrate_version( $row ) : null;
	}

	/**
	 * Where the next package goes in the list.
	 *
	 * @return int
	 */
	private static function next_position(): int {
		global $wpdb;

		$table = Schema::packages_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder, and there is nothing to interpolate.
		$highest = (int) $wpdb->get_var( "SELECT MAX(position) FROM {$table}" );

		return $highest + self::POSITION_STEP;
	}

	/**
	 * A stored catalogue row, with its numbers as numbers.
	 *
	 * @param array<string, mixed> $row As the database returned it.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		return array(
			'id'             => (string) $row['id'],
			'name'           => (string) $row['name'],
			'status'         => (string) $row['status'],
			'position'       => (int) $row['position'],
			'retired_at'     => (int) $row['retired_at'],
			'created_at'     => (int) $row['created_at'],
			'updated_at'     => (int) $row['updated_at'],
			'created_by'     => (int) $row['created_by'],
			'record_version' => (int) $row['record_version'],
		);
	}

	/**
	 * A stored version, with its numbers as numbers.
	 *
	 * @param array<string, mixed> $row As the database returned it.
	 * @return array<string, mixed>
	 */
	private static function hydrate_version( array $row ): array {
		return array(
			'id'              => (string) $row['id'],
			'package_id'      => (string) $row['package_id'],
			'version'         => (int) $row['version'],
			'name'            => (string) $row['name'],
			'hours'           => (float) $row['hours'],
			'price'           => (int) $row['price'],
			'currency'        => (string) $row['currency'],
			'validity_months' => (int) $row['validity_months'],
			'terms'           => (string) $row['terms'],
			'created_at'      => (int) $row['created_at'],
			'created_by'      => (int) $row['created_by'],
		);
	}
}
