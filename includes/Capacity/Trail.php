<?php
/**
 * What a change to somebody's time leaves behind on the work.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Capacity;

use Blueworx\Forge\Work\Events;

/**
 * #144, and CAP-E5 in the enforcement design.
 *
 * The issue asks for "recalculation across both interfaces on any hours or
 * dates change". There is nothing to recalculate: every capacity figure in the
 * product is read from the work items and the availability records at the
 * moment it is asked for, and the client's availability answer is derived the
 * same way. None of them can be stale, and a stored figure would need
 * invalidating from every write path that could affect it — which is all of
 * them.
 *
 * What was genuinely missing is the other half. Somebody's week turns red
 * because a person's hours were cut or a fortnight of leave went in, and until
 * now nothing in the item's own history said so. A person looking at the item
 * had no way to find out what had moved underneath it except by guessing.
 *
 * So this writes the record and nothing else. It does not notify, does not flag
 * a new state on the item, and does not refuse the change that caused it —
 * refusing would be a system declining to record a fact about the real world,
 * which is worse than an uncomfortable figure. Chasing belongs to M10's
 * notification work, and a half version built here would be built twice.
 */
final class Trail {

	/**
	 * How far ahead an open-ended change is taken to reach.
	 *
	 * A change to somebody's hours has no end date — it stands until the next
	 * one. A year covers anything anybody has actually planned, and stops the
	 * query reading the whole future to find work that does not exist yet.
	 */
	public const OPEN_ENDED = '+1 year';

	/**
	 * The dates a change disturbs.
	 *
	 * @param string $from YYYY-MM-DD the change starts.
	 * @param string $to   YYYY-MM-DD it ends, or '' when it is open-ended.
	 * @return array<int, string> from and to.
	 */
	public static function window( string $from, string $to ): array {
		if ( '' !== $to ) {
			return array( $from, $to );
		}

		return array( $from, gmdate( 'Y-m-d', (int) strtotime( $from . ' ' . self::OPEN_ENDED ) ) );
	}

	/**
	 * The live work one person's time change affects.
	 *
	 * Keyed by item, so an item where somebody holds two seats is recorded
	 * once. Its picture changed once, and two entries would read as two things
	 * having happened.
	 *
	 * @param array<int, array<string, mixed>> $allocations Live allocations over the window.
	 * @param string                           $user_id     Whose time changed.
	 * @return array<string, string> Item id to client site id.
	 */
	public static function work_touching( array $allocations, string $user_id ): array {
		$out = array();

		foreach ( $allocations as $allocation ) {
			if ( (string) ( $allocation['user_id'] ?? '' ) !== $user_id ) {
				continue;
			}

			$out[ (string) ( $allocation['item_id'] ?? '' ) ] = (string) ( $allocation['client_site_id'] ?? '' );
		}

		return $out;
	}

	/**
	 * Notes, against every piece of live work it affects, that somebody's time
	 * changed.
	 *
	 * @param string $user_id Whose time changed.
	 * @param string $from    YYYY-MM-DD the change starts.
	 * @param string $to      YYYY-MM-DD it ends, or '' when it is open-ended.
	 * @param string $what    What changed, in the words the history will show.
	 * @return int How many pieces of work were noted.
	 */
	public static function record( string $user_id, string $from, string $to, string $what ): int {
		if ( '' === $user_id || '' === $from ) {
			return 0;
		}

		list( $start, $end ) = self::window( $from, $to );

		$touched = self::work_touching( Commitments::live( $start, $end ), $user_id );

		foreach ( $touched as $item_id => $site_id ) {
			Events::append(
				array(
					'item_id'        => $item_id,
					'client_site_id' => $site_id,
					'action'         => Events::EDITED,
					'field'          => 'capacity',
					'reason'         => $what,
					'actor'          => get_current_user_id(),
				)
			);
		}

		return count( $touched );
	}
}
