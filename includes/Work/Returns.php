<?php
/**
 * Which way back is a way back.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

/**
 * #108. Work goes backwards along one rule and no other: **to an earlier stage
 * it has actually occupied, with a reason** (WF-3).
 *
 * The "actually occupied" half is what makes this a return rather than a
 * correction. Sending an item back to a stage it has never been in is not
 * undoing anything — it is inventing history, and the specification requires the
 * WF-5 administrator override for that, precisely so it is marked on the item
 * and appears in the override report. The history is read from the changelog,
 * which is append-only, so the set of legitimate targets is a fact rather than
 * an intention.
 *
 * The reason is mandatory on every backwards move without exception. There is
 * no default, no "no reason given", and no route that omits it: a board full of
 * work that went backwards for reasons nobody wrote down is the thing this
 * exists to prevent.
 */
final class Returns {

	/**
	 * Longest a reason may be, matching the column it lands in.
	 */
	public const MAX_REASON = 191;

	/**
	 * The one return with extra requirements: a failed review.
	 */
	public const REVIEW_FROM = 'in-review';

	/**
	 * Where a failed review goes.
	 */
	public const REVIEW_TO = 'in-development';

	/**
	 * The stages an item has occupied in its current cycle, in stage order.
	 *
	 * @param array<int, array<string, mixed>> $history The item's changelog.
	 * @param int                              $cycle   The cycle to read.
	 * @return array<int, string>
	 */
	public static function occupied( array $history, int $cycle ): array {
		$seen = array();

		foreach ( $history as $event ) {
			if ( (int) ( $event['cycle'] ?? 1 ) !== $cycle ) {
				continue;
			}

			foreach ( array( 'from_stage', 'to_stage' ) as $key ) {
				$stage = (string) ( $event[ $key ] ?? '' );

				if ( '' !== $stage && Stages::exists( $stage ) ) {
					$seen[ $stage ] = true;
				}
			}
		}

		return array_values(
			array_filter(
				Stages::ALL,
				static function ( string $stage ) use ( $seen ): bool {
					return isset( $seen[ $stage ] );
				}
			)
		);
	}

	/**
	 * Where an item may be sent back to.
	 *
	 * Blocked is never among them: it is not a place on the path, and leaving it
	 * is its own move with its own gate (#109).
	 *
	 * @param array<string, mixed>             $item    The item, as read.
	 * @param array<int, array<string, mixed>> $history The item's changelog.
	 * @return array<int, string>
	 */
	public static function targets( array $item, array $history ): array {
		$from = (string) $item['stage'];

		if ( Stages::EXCEPTION === Stages::kind( $from ) ) {
			return array();
		}

		$here    = Stages::position( $from );
		$targets = array();

		foreach ( self::occupied( $history, (int) ( $item['cycle'] ?? 1 ) ) as $stage ) {
			if ( $stage === $from || Stages::position( $stage ) >= $here ) {
				continue;
			}

			if ( Stages::EXCEPTION === Stages::kind( $stage ) ) {
				continue;
			}

			// #110: a stage an item may not be in going forwards is not a stage
			// it may be sent back into either. A work type that changed after
			// Triage must not leave Bug Tracking reachable behind it.
			if ( ! Stages::may_hold( $stage, (string) $item['work_type'] ) ) {
				continue;
			}

			$targets[] = $stage;
		}

		return $targets;
	}

	/**
	 * Whether this particular return is permitted.
	 *
	 * @param array<string, mixed>             $item    The item, as read.
	 * @param string                           $to      Target stage.
	 * @param array<int, array<string, mixed>> $history The item's changelog.
	 * @return bool
	 */
	public static function allowed( array $item, string $to, array $history ): bool {
		return in_array( $to, self::targets( $item, $history ), true );
	}

	/**
	 * Whether this return is the failed-review one, which also needs the
	 * reviewer's feedback recorded with it.
	 *
	 * @param string $from Stage moving from.
	 * @param string $to   Stage moving to.
	 * @return bool
	 */
	public static function is_review_return( string $from, string $to ): bool {
		return self::REVIEW_FROM === $from && self::REVIEW_TO === $to;
	}
}
