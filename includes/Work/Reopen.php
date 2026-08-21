<?php
/**
 * Picking finished work back up.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

/**
 * #113. Work that is Completed or Released can be picked up again, and doing so
 * **never erases the fact that it was finished**.
 *
 * WF-4 makes a reopen a new *cycle* rather than a rewind. The earlier
 * completion and release records stay exactly where they are, attached to the
 * cycle they belong to, and the new cycle starts empty — so an item reopened
 * three times has three complete accounts of being built rather than one
 * account that has been overwritten twice.
 *
 * That is also why the gate records are scoped by cycle
 * (Work\GateRecords::current_for()). A reopened item has not undone its
 * documentation approval; it has started a new round that needs its own.
 */
final class Reopen {

	/**
	 * The stages work can be reopened from.
	 */
	public const FROM = array( 'completed', 'released' );

	/**
	 * Where a reopen may go, per the state machine's returns table. Both are
	 * places work is actually done — reopening to Triage would be asking
	 * whether to do something that has already been delivered.
	 */
	public const TO = array( 'documentation-period', 'in-development' );

	/**
	 * Longest a reason may be, matching the column it lands in.
	 */
	public const MAX_REASON = 191;

	/**
	 * Whether an item is in a state that can be reopened at all.
	 *
	 * @param array<string, mixed> $item The item, as read.
	 * @return bool
	 */
	public static function possible( array $item ): bool {
		if ( ! empty( $item['archived'] ) || Outcomes::is_closed( $item ) ) {
			return false;
		}

		return in_array( (string) $item['stage'], self::FROM, true );
	}

	/**
	 * Where this item may be reopened to.
	 *
	 * @param array<string, mixed> $item The item, as read.
	 * @return array<int, string>
	 */
	public static function targets( array $item ): array {
		if ( ! self::possible( $item ) ) {
			return array();
		}

		return array_values(
			array_filter(
				self::TO,
				static function ( string $stage ) use ( $item ): bool {
					// #110 still holds here. A stage this work type may never
					// occupy is not made occupiable by arriving at it sideways.
					return Stages::may_hold( $stage, (string) $item['work_type'] );
				}
			)
		);
	}

	/**
	 * Whether this particular reopen is permitted.
	 *
	 * @param array<string, mixed> $item The item, as read.
	 * @param string               $to   Target stage.
	 * @return bool
	 */
	public static function allowed( array $item, string $to ): bool {
		return in_array( $to, self::targets( $item ), true );
	}

	/**
	 * The cycle a reopen starts.
	 *
	 * @param array<string, mixed> $item The item, as read.
	 * @return int
	 */
	public static function next_cycle( array $item ): int {
		return max( 1, (int) ( $item['cycle'] ?? 1 ) ) + 1;
	}
}
