<?php
/**
 * The way round the workflow, and what it costs.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

/**
 * #114, the WF-5 override: the Primary administrator may move an item from any
 * stage to any stage.
 *
 * It exists because the alternative is worse. Without it, an item put in the
 * wrong place by a mistake nobody can undo sits there for ever, and the real
 * workaround becomes a second item and a note in a spreadsheet — which is the
 * state of affairs this product exists to end. So the override is real, and it
 * is expensive:
 *
 * - **A reason is mandatory**, and it is stored on the item rather than only in
 *   the changelog, so the item itself says it was moved by hand.
 * - **The mark is permanent.** `override_used` is never cleared. An item that
 *   was overridden once is an item whose history has a hole in it, for ever,
 *   and a later reader needs to know that.
 * - **Every use is countable.** The changelog entry and the flag are both
 *   queryable, so the override report is a query rather than a memory.
 *
 * Two things it cannot do, and both are the point:
 *
 * - **It cannot open the client transition lock.** That is a security boundary
 *   rather than a workflow gate (WF-5), and it is refused before this class is
 *   reached — Tenancy\Capabilities answers a client role with CLIENT_LOCK
 *   whatever else they hold.
 * - **It cannot put work where its type may never go.** Bug Tracking exists
 *   only for bugs (#110, WF-1), and that is a property of the stage rather than
 *   a rule about who may move things. An override that could place a Feature
 *   there would leave it in a stage whose gate assumes it is a bug, which is
 *   not a correction — it is a worse mistake than the one being corrected.
 */
final class Override {

	/**
	 * Longest a reason may be, matching the column it lands in.
	 */
	public const MAX_REASON = 191;

	/**
	 * Whether an override could move this item to this stage.
	 *
	 * Note what is *not* checked: whether the move is forward, backwards, a
	 * skip, or somewhere the item has never been. All of those are exactly what
	 * an override is for.
	 *
	 * @param array<string, mixed> $item The item, as read.
	 * @param string               $to   Target stage.
	 * @return bool
	 */
	public static function allowed( array $item, string $to ): bool {
		if ( ! Stages::exists( $to ) || (string) $item['stage'] === $to ) {
			return false;
		}

		if ( ! empty( $item['archived'] ) ) {
			// Archived work is out of the way by request. Bring it back first,
			// so that un-archiving is its own decision with its own record.
			return false;
		}

		return Stages::may_hold( $to, (string) $item['work_type'] );
	}

	/**
	 * What the override writes onto the item, so that it says so afterwards.
	 *
	 * @param string $reason Why it was needed.
	 * @return array<string, mixed>
	 */
	public static function mark( string $reason ): array {
		return array(
			'override_used'   => 1,
			'override_reason' => mb_substr( trim( $reason ), 0, self::MAX_REASON ),
		);
	}
}
