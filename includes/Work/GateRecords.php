<?php
/**
 * Who satisfied which requirement, and when.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Tenancy\Ids;

/**
 * #105's second half: a gate requirement is only satisfied by a record, and a
 * record without an actor and a completion time cannot be stored.
 *
 * That refusal is the point of the class. A completion with nobody's name on it
 * is indistinguishable from nobody having done it, and a gate whose records can
 * be anonymous is a gate that proves nothing after the fact — which is exactly
 * when anybody looks.
 *
 * Records are scoped to a **cycle** and an **attempt**. A reopen (WF-4) starts
 * a new cycle and a failed review starts a new attempt; in both cases the older
 * records stay exactly where they are and stop counting towards the gate. That
 * is how #108 keeps the earlier review attempt without letting it satisfy the
 * next one.
 */
final class GateRecords {

	/**
	 * Id prefix for a gate record.
	 */
	public const PREFIX = 'gat';

	/**
	 * Longest a recorded value may be. It lands in a text column, but a person
	 * typing into a box does not need a novel's worth of room.
	 */
	public const MAX_VALUE = 2000;

	/**
	 * Records a requirement as complete.
	 *
	 * @param array<string, mixed> $entry item_id, client_site_id, requirement,
	 *                                    value, evidence, cycle, attempt, actor.
	 * @return array<string, mixed>|null Null when it was refused or not written.
	 */
	public static function complete( array $entry ): ?array {
		global $wpdb;

		$actor = (int) ( $entry['actor'] ?? 0 );

		// No actor, no record. See the class comment: this is the rule, not a
		// tidiness check, and it is enforced here rather than at the route so
		// that no second caller can get round it.
		if ( $actor <= 0 ) {
			return null;
		}

		$requirement_id = (string) ( $entry['requirement'] ?? '' );
		$requirement    = Gates::requirement( $requirement_id );

		if ( null === $requirement ) {
			return null;
		}

		$evidence = trim( (string) ( $entry['evidence'] ?? '' ) );

		// A requirement whose specification says "Evidence: Yes" is not
		// satisfied by somebody ticking it.
		if ( ! empty( $requirement['evidence'] ) && '' === $evidence ) {
			return null;
		}

		$row = array(
			'id'             => Ids::create( self::PREFIX ),
			'item_id'        => (string) ( $entry['item_id'] ?? '' ),
			'client_site_id' => (string) ( $entry['client_site_id'] ?? '' ),
			'gate'           => Gates::gate_of( $requirement_id ),
			'requirement'    => $requirement_id,
			'value'          => mb_substr( trim( (string) ( $entry['value'] ?? '' ) ), 0, self::MAX_VALUE ),
			'evidence'       => mb_substr( $evidence, 0, self::MAX_VALUE ),
			'cycle'          => max( 1, (int) ( $entry['cycle'] ?? 1 ) ),
			'attempt'        => max( 1, (int) ( $entry['attempt'] ?? 1 ) ),
			'actor'          => $actor,
			'completed_at'   => bwx_forge_now(),
		);

		if ( '' === $row['item_id'] || 0 === $row['completed_at'] ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		$written = $wpdb->insert( Schema::gate_records_table(), $row, Formats::for_row( $row ) );

		return $written ? self::hydrate( $row ) : null;
	}

	/**
	 * The records that count towards an item's gates right now: this cycle, and
	 * for the review gate this attempt.
	 *
	 * Keyed by requirement id, because that is the question every caller asks —
	 * "is G-UP-NEXT-4 done" — and because the newest record for a requirement is
	 * the one that answers it.
	 *
	 * @param array<string, mixed> $item The item, as read.
	 * @return array<string, array<string, mixed>>
	 */
	public static function current_for( array $item ): array {
		$cycle   = max( 1, (int) ( $item['cycle'] ?? 1 ) );
		$attempt = max( 1, (int) ( $item['review_attempt'] ?? 1 ) );
		$current = array();

		foreach ( self::for_item( (string) $item['id'] ) as $record ) {
			if ( $record['cycle'] !== $cycle ) {
				continue;
			}

			// Only the review gate resets per attempt. Everything else survives
			// a failed review, because a returned item has not undone its
			// documentation or its design.
			if ( 'G-IN-REVIEW' === $record['gate'] && $record['attempt'] !== $attempt ) {
				continue;
			}

			$current[ $record['requirement'] ] = $record;
		}

		return $current;
	}

	/**
	 * Every record on an item, oldest first.
	 *
	 * @param string $item_id Item id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_item( string $item_id ): array {
		global $wpdb;

		$table = Schema::gate_records_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE item_id = %s ORDER BY completed_at ASC, id ASC", $item_id ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Turns a row into the record the rest of the plugin uses.
	 *
	 * @param array<string, mixed> $row Row as stored.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		return array(
			'id'           => (string) $row['id'],
			'item_id'      => (string) $row['item_id'],
			'gate'         => (string) $row['gate'],
			'requirement'  => (string) $row['requirement'],
			'value'        => (string) $row['value'],
			'evidence'     => (string) $row['evidence'],
			'cycle'        => (int) $row['cycle'],
			'attempt'      => (int) $row['attempt'],
			'actor'        => (int) $row['actor'],
			'completed_at' => (int) $row['completed_at'],
		);
	}
}
