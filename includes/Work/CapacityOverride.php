<?php
/**
 * The way round the capacity check, and what it costs.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

/**
 * CAP-4, and CAP-E3 in the enforcement design (#143): over-allocating somebody
 * requires a reason from a studio administrator, and does not hard block.
 *
 * It is not the WF-5 override, and the difference matters more than the
 * similarity. Work\Override's mark says an item's history has a hole in it —
 * somebody put it where the workflow would not have. That is rare, and the
 * override report exists to surface it. Over-booking somebody is not that: it
 * is a manager deciding, on information the model does not have, that a week
 * will take more than the arithmetic says. CAP-4 chose that over a hard block
 * precisely because a capacity model with no actuals in it should not overrule
 * a person on worse information. Marking those with the WF-5 flag would fill
 * the report with routine decisions and bury the ones it was built to show.
 *
 * What the two do share is who: any studio administrator, so a real week is
 * never waiting on one person.
 *
 * Two things it cannot do:
 *
 * - **It cannot be given by a client.** The transition lock is a security
 *   boundary rather than a workflow gate, and Tenancy\Capabilities refuses a
 *   client role before this class is reached.
 * - **It cannot be given once and cover everything after.** The permission is
 *   for one crossing. The reason travels with the move and is read from there,
 *   never from the item — which is what makes the check ask again rather than
 *   remember an answer about a picture that has since changed (#142).
 */
final class CapacityOverride {

	/**
	 * Longest a reason may be, matching the column it lands in.
	 */
	public const MAX_REASON = 191;

	/**
	 * What the override writes onto the item, so that it says so afterwards.
	 *
	 * The column carries the most recent reason. Every reason ever given is
	 * kept as its own history entry, so the item reads as current while the
	 * trail stays complete.
	 *
	 * @param string $reason Why the week will take it.
	 * @return array<string, mixed>
	 */
	public static function mark( string $reason ): array {
		return array(
			'capacity_override_used'   => 1,
			'capacity_override_reason' => mb_substr( trim( $reason ), 0, self::MAX_REASON ),
		);
	}
}
