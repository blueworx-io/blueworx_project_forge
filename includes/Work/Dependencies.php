<?php
/**
 * Work that waits on other work.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Tenancy\Ids;

/**
 * #103. A dependency between two work items, and — the part that matters — the
 * two states that mean it is not going to move on its own.
 *
 * A list that only says "waiting on three things" is the same as no list. The
 * question anybody actually has is whether any of it is going to happen, and
 * the two answers that mean it will not are **unscheduled** and **blocked**.
 * Those are surfaced by name; work that is merely in hand is counted as waited
 * on and left alone, because flagging everything is the same as flagging
 * nothing.
 *
 * A dependency lives on a site, like everything else (ARCH-3), and cannot cross
 * to another — an item waiting on another tenant's work would be reachable from
 * two tenants at once, which is the thing the boundary exists to prevent.
 */
final class Dependencies {

	/**
	 * Id prefix for a dependency.
	 */
	public const PREFIX = 'dep';

	/**
	 * Refused: work cannot wait on itself.
	 */
	public const SELF = 'self';

	/**
	 * Refused: the two would wait on each other, directly or round a loop.
	 */
	public const LOOP = 'loop';

	/**
	 * Refused: the two are not on the same site.
	 */
	public const ELSEWHERE = 'elsewhere';

	/**
	 * Records that one item waits on another.
	 *
	 * @param string $item_id        The work that waits.
	 * @param string $depends_on_id  The work it waits for.
	 * @param string $client_site_id The site both sit on.
	 * @param int    $author         WordPress user id.
	 * @return array<string, mixed>|null Null when the write failed.
	 */
	public static function add( string $item_id, string $depends_on_id, string $client_site_id, int $author ): ?array {
		global $wpdb;

		$row = array(
			'id'             => Ids::create( self::PREFIX ),
			'item_id'        => $item_id,
			'depends_on_id'  => $depends_on_id,
			'client_site_id' => $client_site_id,
			'created_at'     => bwx_forge_now(),
			'created_by'     => $author,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		$inserted = $wpdb->insert( Schema::dependencies_table(), $row, Formats::for_row( $row ) );

		return $inserted ? self::hydrate( $row ) : null;
	}

	/**
	 * Removes one.
	 *
	 * Unlike a person or a site, a dependency is genuinely removable: it is a
	 * statement about a plan rather than a record of access somebody held. What
	 * survives is the changelog entry the removal writes, which is the part
	 * anybody would want later.
	 *
	 * @param string $id Dependency id.
	 * @return bool
	 */
	public static function remove( string $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		return (bool) $wpdb->delete( Schema::dependencies_table(), array( 'id' => $id ), array( '%s' ) );
	}

	/**
	 * One dependency.
	 *
	 * @param string $id Dependency id.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		global $wpdb;

		$table = Schema::dependencies_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * What one item waits on.
	 *
	 * @param string $item_id The work that waits.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_item( string $item_id ): array {
		return self::query( 'item_id', $item_id );
	}

	/**
	 * What waits on one item — the other direction, for saying who is held up
	 * when something slips.
	 *
	 * @param string $item_id The work being waited for.
	 * @return array<int, array<string, mixed>>
	 */
	public static function waiting_on( string $item_id ): array {
		return self::query( 'depends_on_id', $item_id );
	}

	/**
	 * Every dependency on a site, as item id to the ids it waits on.
	 *
	 * Read in one query rather than one per item: this is what the loop check
	 * walks, and asking per item turns adding a dependency into a query per
	 * link in the chain.
	 *
	 * @param string $client_site_id Site id.
	 * @return array<string, array<int, string>>
	 */
	public static function chain_on_site( string $client_site_id ): array {
		global $wpdb;

		$table = Schema::dependencies_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT item_id, depends_on_id FROM {$table} WHERE client_site_id = %s", $client_site_id ), ARRAY_A );

		$chain = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$chain[ (string) $row['item_id'] ][] = (string) $row['depends_on_id'];
		}

		return $chain;
	}

	/**
	 * Whether one item may be made to wait on another.
	 *
	 * @param string                            $item_id       The work that would wait.
	 * @param string                            $depends_on_id The work it would wait for.
	 * @param array<string, array<int, string>> $chain        Existing dependencies,
	 *                                                        item id to the ids it
	 *                                                        waits on.
	 * @return string|null One of the refusal reasons, or null when it may.
	 */
	public static function refuse( string $item_id, string $depends_on_id, array $chain ): ?string {
		if ( $item_id === $depends_on_id ) {
			return self::SELF;
		}

		/*
		 * The loop check, from the other end. If the item is already somewhere
		 * upstream of the thing it would now wait for, adding this closes a ring
		 * — and neither item would ever be able to start.
		 */
		return self::reaches( $depends_on_id, $item_id, $chain, array() ) ? self::LOOP : null;
	}

	/**
	 * What a set of dependencies adds up to for the item waiting on them.
	 *
	 * @param array<int, array<string, mixed>> $upstream The items waited for.
	 * @return array<string, mixed>
	 */
	public static function summarise( array $upstream ): array {
		$waiting     = 0;
		$satisfied   = 0;
		$unscheduled = array();
		$blocked     = array();

		foreach ( $upstream as $item ) {
			if ( self::is_settled( $item ) ) {
				++$satisfied;
				continue;
			}

			// Counted once however many ways it is stuck. A total that
			// double-counts is a total nobody can act on.
			++$waiting;

			$id = (string) ( $item['id'] ?? '' );

			if ( Stages::BLOCKED === (string) ( $item['stage'] ?? '' ) ) {
				$blocked[] = $id;
			}

			if ( '' === (string) ( $item['planned_start'] ?? '' ) && '' === (string) ( $item['planned_due'] ?? '' ) ) {
				$unscheduled[] = $id;
			}
		}

		return array(
			'total'       => count( $upstream ),
			'waiting'     => $waiting,
			'satisfied'   => $satisfied,
			'unscheduled' => $unscheduled,
			'blocked'     => $blocked,
			'clear'       => 0 === $waiting,
		);
	}

	/**
	 * Whether a dependency is done with, one way or another.
	 *
	 * Work that ended without being finished counts as settled. Waiting forever
	 * for something somebody deliberately stopped is the failure this avoids.
	 *
	 * @param array<string, mixed> $item The item waited for.
	 * @return bool
	 */
	private static function is_settled( array $item ): bool {
		if ( '' !== (string) ( $item['terminal_outcome'] ?? '' ) ) {
			return true;
		}

		$stage = (string) ( $item['stage'] ?? '' );

		return Stages::exists( $stage )
			&& Stages::BLOCKED !== $stage
			&& Stages::position( $stage ) >= Stages::position( 'completed' );
	}

	/**
	 * Whether one item sits upstream of another, however many links away.
	 *
	 * @param string                            $from  Where to start.
	 * @param string                            $to    What is being looked for.
	 * @param array<string, array<int, string>> $chain The existing dependencies.
	 * @param array<int, string>                $seen  Ids already walked.
	 * @return bool
	 */
	private static function reaches( string $from, string $to, array $chain, array $seen ): bool {
		if ( $from === $to ) {
			return true;
		}

		// A ring already in the data would otherwise walk forever. It should not
		// be possible to store one, and this is not the place to find out.
		if ( in_array( $from, $seen, true ) ) {
			return false;
		}

		$seen[] = $from;

		foreach ( (array) ( $chain[ $from ] ?? array() ) as $next ) {
			if ( self::reaches( (string) $next, $to, $chain, $seen ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The shared read.
	 *
	 * @param string $column Column to match — never user input.
	 * @param string $value  Value to match.
	 * @return array<int, array<string, mixed>>
	 */
	private static function query( string $column, string $value ): array {
		global $wpdb;

		$table = Schema::dependencies_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column are this class's own literals.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = %s ORDER BY created_at ASC", $value ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Turns a stored row into the record the rest of the plugin uses.
	 *
	 * @param array<string, mixed> $row Row as stored.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		return array(
			'id'             => (string) $row['id'],
			'item_id'        => (string) $row['item_id'],
			'depends_on_id'  => (string) $row['depends_on_id'],
			'client_site_id' => (string) $row['client_site_id'],
			'created_at'     => (int) $row['created_at'],
			'created_by'     => (int) $row['created_by'],
		);
	}
}
