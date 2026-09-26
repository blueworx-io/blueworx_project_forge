<?php
/**
 * The client as a task's reviewer.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

/**
 * #391 (2026-09-26). Some work needs the client to sign it off, so "the
 * client" can sit in the reviewer seat.
 *
 * It is stored as `reviewer_id = 'client'`. No `usr_` prefix, so it never
 * matches a person, and no staff member can approve as the reviewer. The
 * client approves from their own site, or an admin records it for them.
 *
 * Their review time counts against nobody: the review hours are held at 0,
 * which keeps capacity and the support allowance right without either
 * knowing about this.
 *
 * Pure.
 */
final class ClientReviewer {

	/**
	 * What the reviewer seat holds when the client reviews.
	 */
	public const ID = 'client';

	/**
	 * The client can be chosen from here on, and not before.
	 */
	public const FROM = Stages::UP_NEXT;

	/**
	 * The client approves.
	 */
	public const APPROVE = 'approve';

	/**
	 * The client sends it back.
	 */
	public const SEND_BACK = 'send_back';

	/**
	 * Refused: the client is not this item's reviewer.
	 */
	public const NOT_THEIRS = 'bwx_forge_not_client_review';

	/**
	 * Refused: the review is not open, usually because it was already decided.
	 */
	public const DECIDED = 'bwx_forge_already_decided';

	/**
	 * Whether the client reviews this item.
	 *
	 * @param array<string, mixed> $item The item, or the values being written.
	 * @return bool
	 */
	public static function is( array $item ): bool {
		return self::ID === (string) ( $item['reviewer_id'] ?? '' );
	}

	/**
	 * Whether the client may be chosen at this stage.
	 *
	 * @param string $stage The stage the item is at.
	 * @return bool
	 */
	public static function may_choose( string $stage ): bool {
		return Stages::exists( $stage )
			&& Stages::BLOCKED !== $stage
			&& Stages::position( $stage ) >= Stages::position( self::FROM );
	}

	/**
	 * The stage that decides it: where the item is, or for blocked work where
	 * it was.
	 *
	 * @param array<string, mixed> $item The item, as read.
	 * @return string
	 */
	public static function stage_of( array $item ): string {
		$stage = (string) ( $item['stage'] ?? '' );

		return Stages::BLOCKED === $stage ? (string) ( $item['prior_stage'] ?? '' ) : $stage;
	}

	/**
	 * The refusal for choosing the client too early.
	 *
	 * @return string
	 */
	public static function too_early(): string {
		return __( 'The client can be the reviewer from Up Next onwards.', 'blueworx-forge' );
	}

	/**
	 * Whether a move takes the client out of the reviewer seat (2026-09-26):
	 * the client reviews from Up Next on, so work going back before Up Next
	 * has no reviewer until somebody picks one. Blocked keeps its place, so
	 * it keeps the seat. Pure.
	 *
	 * @param array<string, mixed> $item The item, as read.
	 * @param string               $to   The stage it is moving to.
	 * @return bool
	 */
	public static function leaves( array $item, string $to ): bool {
		return self::is( $item ) && Stages::BLOCKED !== $to && ! self::may_choose( $to );
	}

	/**
	 * The history line for the seat being cleared.
	 *
	 * @return string
	 */
	public static function cleared(): string {
		return __( 'The client is no longer the reviewer, because the task went back before Up Next.', 'blueworx-forge' );
	}

	/**
	 * Whether the client already decided the review this item is on. Pure.
	 *
	 * An approval keeps the review attempt; a send-back starts the next one.
	 * So the last client decision in this cycle, on this attempt or the one
	 * before, is the decision a second click is repeating.
	 *
	 * @param array<string, mixed> $item          The item, as read.
	 * @param int|null             $last_attempt  The review attempt of the last
	 *                                            client decision this cycle, or
	 *                                            null when there is none.
	 * @return bool
	 */
	public static function decided( array $item, ?int $last_attempt ): bool {
		if ( null === $last_attempt ) {
			return false;
		}

		$attempt = max( 1, (int) ( $item['review_attempt'] ?? 1 ) );

		return $last_attempt === $attempt || $last_attempt === $attempt - 1;
	}

	/**
	 * Whether the item is waiting on the client's review right now.
	 *
	 * @param array<string, mixed> $item The item, as read.
	 * @return bool
	 */
	public static function awaiting( array $item ): bool {
		return self::is( $item )
			&& 'in-review' === (string) ( $item['stage'] ?? '' )
			&& ! Outcomes::is_closed( $item )
			&& empty( $item['archived'] );
	}

	/**
	 * The history line for a decision.
	 *
	 * @param string $decision APPROVE or SEND_BACK.
	 * @param string $client   Who on the client's side, where known.
	 * @param string $admin    The admin who recorded it for them, if one did.
	 * @return string
	 */
	public static function entry( string $decision, string $client, string $admin ): string {
		$said = self::APPROVE === $decision
			? __( 'Approved by the client', 'blueworx-forge' )
			: __( 'Sent back by the client', 'blueworx-forge' );

		$admin  = trim( $admin );
		$client = trim( $client );

		if ( '' !== $admin ) {
			/* translators: 1: what the client decided, 2: the admin's name. */
			return sprintf( __( '%1$s, recorded by %2$s on the client\'s behalf', 'blueworx-forge' ), $said, $admin );
		}

		return '' === $client ? $said : sprintf( '%1$s (%2$s)', $said, $client );
	}

	/**
	 * What a write becomes when the client reviews: no review hours and no
	 * stand-in. Only adds what would change, so an edit that changes nothing
	 * still writes nothing.
	 *
	 * @param array<string, mixed> $changes What is being written.
	 * @param array<string, mixed> $current The item as it stands.
	 * @return array<string, mixed>
	 */
	public static function settle( array $changes, array $current = array() ): array {
		if ( ! self::is( array_merge( $current, $changes ) ) ) {
			return $changes;
		}

		if ( array_key_exists( 'hours_review', $changes ) || (float) ( $current['hours_review'] ?? 0 ) > 0 ) {
			$changes['hours_review'] = 0.0;
		}

		if ( array_key_exists( 'reviewer_substitute_id', $changes ) || '' !== (string) ( $current['reviewer_substitute_id'] ?? '' ) ) {
			$changes['reviewer_substitute_id'] = '';
		}

		return $changes;
	}
}
