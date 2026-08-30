<?php
/**
 * What happened to an onboarding step, and who did it.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Onboarding;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Tenancy\Ids;

/**
 * #161's second half: every status change is attributable and permanent.
 *
 * **An entry with nobody's name on it is refused**, the same rule and for the
 * same reason as Work\GateRecords: a change nobody is recorded as having made
 * is indistinguishable from no change at all, and a history that can be
 * anonymous proves nothing after the fact — which is precisely when anybody
 * reads it.
 *
 * Its own table rather than the work changelog. An onboarding step is not a
 * work item, has no cycle or review attempt, and folding it in would leave an
 * `item_id` that sometimes means a work item and sometimes does not — which
 * every reader of that table would then have to know about.
 *
 * Nothing here is ever edited or deleted. A correction is a further entry.
 */
final class StepEvents {

	/**
	 * Id prefix for a step event.
	 */
	public const PREFIX = 'obe';

	/**
	 * The step was created, as part of assigning a checklist.
	 */
	public const CREATED = 'created';

	/**
	 * Its status changed.
	 */
	public const MOVED = 'moved';

	/**
	 * Somebody answered it.
	 */
	public const ANSWERED = 'answered';

	/**
	 * Who it belongs to changed.
	 */
	public const REASSIGNED = 'reassigned';

	/**
	 * Every action an entry may record.
	 *
	 * @var array<int, string>
	 */
	public const ACTIONS = array(
		self::CREATED,
		self::MOVED,
		self::ANSWERED,
		self::REASSIGNED,
	);

	/**
	 * Longest a reason may be. It lands in a text column and comes from a
	 * person typing into a box, so it is bounded rather than unbounded.
	 */
	public const MAX_REASON = 2000;

	/**
	 * Builds an entry, or refuses it.
	 *
	 * Separate from writing it so that what makes an entry attributable can be
	 * read, and tested, without a database.
	 *
	 * @param array<string, mixed> $entry step_id, client_site_id, action,
	 *                                    from_status, to_status, reason, actor,
	 *                                    actor_site, source_interface.
	 * @return array<string, mixed> Empty when it may not be written.
	 */
	public static function row_from( array $entry ): array {
		$actor  = (int) ( $entry['actor'] ?? 0 );
		$site   = trim( (string) ( $entry['actor_site'] ?? '' ) );
		$action = (string) ( $entry['action'] ?? '' );

		/*
		 * Somebody, or something, has to be named. See the class comment: this
		 * is the rule rather than a tidiness check, and it is enforced here
		 * rather than at each caller so that no second one can get round it.
		 *
		 * A client site counts, because it is not a person here — it holds a
		 * key, not an account — and #162 lets one answer a step. Work\Comments
		 * met the same problem first and this is deliberately the same answer,
		 * down to refusing a row that claims to be both: two different stories
		 * could be told about such an entry, and the point of a history is that
		 * only one can.
		 */
		if ( ( $actor <= 0 && '' === $site ) || ( $actor > 0 && '' !== $site ) ) {
			return array();
		}

		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return array();
		}

		$step_id = (string) ( $entry['step_id'] ?? '' );

		if ( '' === $step_id ) {
			return array();
		}

		return array(
			'id'               => Ids::create( self::PREFIX ),
			'step_id'          => $step_id,
			'client_site_id'   => (string) ( $entry['client_site_id'] ?? '' ),
			'action'           => $action,
			'from_status'      => (string) ( $entry['from_status'] ?? '' ),
			'to_status'        => (string) ( $entry['to_status'] ?? '' ),
			'reason'           => mb_substr( (string) ( $entry['reason'] ?? '' ), 0, self::MAX_REASON ),
			'actor'            => $actor,
			'actor_site'       => mb_substr( $site, 0, 32 ),
			'source_interface' => (string) ( $entry['source_interface'] ?? '' ),
			'occurred_at'      => bwx_forge_now(),
		);
	}

	/**
	 * Appends an entry.
	 *
	 * @param array<string, mixed> $entry As {@see self::row_from()} takes.
	 * @return bool Whether it was written.
	 */
	public static function append( array $entry ): bool {
		global $wpdb;

		$row = self::row_from( $entry );

		if ( array() === $row ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		return (bool) $wpdb->insert( Schema::onboarding_step_events_table(), $row, Formats::for_row( $row ) );
	}

	/**
	 * One step's history, oldest first — the order it happened in, which is the
	 * order anybody reading it wants.
	 *
	 * @param string $step_id The step.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_step( string $step_id ): array {
		global $wpdb;

		$table = Schema::onboarding_step_events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE step_id = %s ORDER BY occurred_at ASC", $step_id ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * A row, as the rest of the product reads it.
	 *
	 * @param array<string, mixed> $row The row.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		return array(
			'id'               => (string) $row['id'],
			'step_id'          => (string) $row['step_id'],
			'client_site_id'   => (string) ( $row['client_site_id'] ?? '' ),
			'action'           => (string) ( $row['action'] ?? '' ),
			'from_status'      => (string) ( $row['from_status'] ?? '' ),
			'to_status'        => (string) ( $row['to_status'] ?? '' ),
			'reason'           => (string) ( $row['reason'] ?? '' ),
			'actor'            => (int) ( $row['actor'] ?? 0 ),
			'actor_site'       => (string) ( $row['actor_site'] ?? '' ),
			'from_client'      => '' !== (string) ( $row['actor_site'] ?? '' ),
			'source_interface' => (string) ( $row['source_interface'] ?? '' ),
			'occurred_at'      => (int) ( $row['occurred_at'] ?? 0 ),
		);
	}
}
