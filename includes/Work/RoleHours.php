<?php
/**
 * Where the reviewer's and deliverer's hours start from.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

/**
 * CAP-2's seeded defaults (#137).
 *
 * Hours are entered per role, because one total cannot say who is committed for
 * how long when three people are involved. That is right and it is also three
 * boxes to fill in for every piece of work, most of which follow the same
 * proportions — so the two supporting figures are seeded from the estimate and
 * left editable.
 *
 * Seeding, never forcing. A figure somebody has already set is left alone, and
 * that includes a zero: "this needs no review time" is something a person can
 * decide, and a default that overwrote it would quietly re-decide it every time
 * the estimate changed.
 */
final class RoleHours {

	/**
	 * The reviewer's share of the estimate.
	 */
	public const REVIEW_RATIO = 0.2;

	/**
	 * The deliverer's share of the estimate.
	 */
	public const DELIVERY_RATIO = 0.1;

	/**
	 * Fills in the two supporting figures where nobody has spoken about them.
	 *
	 * @param array<string, mixed> $changes What is being written.
	 * @param array<string, mixed> $current The item as it stands, when there is one.
	 * @return array<string, mixed> The changes, possibly with two more.
	 */
	public static function seed( array $changes, array $current = array() ): array {
		if ( ! array_key_exists( 'hours_primary', $changes ) ) {
			return $changes;
		}

		$primary = round( (float) $changes['hours_primary'], 2 );

		if ( $primary <= 0 ) {
			return $changes;
		}

		$ratios = array(
			'hours_review'   => self::REVIEW_RATIO,
			'hours_delivery' => self::DELIVERY_RATIO,
		);

		foreach ( $ratios as $column => $ratio ) {
			/*
			 * Named in this write, or already carrying a figure on the item:
			 * either way somebody has spoken, and this is not the place to
			 * argue with them.
			 */
			if ( array_key_exists( $column, $changes ) ) {
				continue;
			}

			if ( round( (float) ( $current[ $column ] ?? 0 ), 2 ) > 0 ) {
				continue;
			}

			$changes[ $column ] = round( $primary * $ratio, 2 );
		}

		return $changes;
	}
}
