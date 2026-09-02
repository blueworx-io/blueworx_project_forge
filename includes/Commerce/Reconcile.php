<?php
/**
 * Whether the hour record still adds up.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Commerce;

/**
 * #158, COMM-3. The ledger, checked against itself.
 *
 * **A balance is the sum of its entries, and that is the only thing that makes
 * it trustworthy.** The rest of this namespace is careful never to store a
 * total; what is left to go wrong is subtler, and all of it happens one source
 * at a time:
 *
 * - a reservation released twice, so the site is credited hours it never held,
 * - a usage booked without its reservation being released, billing the client
 *   for the same work twice,
 * - spend given back, which the arithmetic allows and the rules do not.
 *
 * None of those makes a balance obviously wrong. Each makes it quietly wrong by
 * a few hours, on one client, discovered when somebody queries an invoice
 * months later — which is exactly the situation an append-only ledger exists to
 * make impossible to reach.
 *
 * So this reads a site's entries, groups them by what they were against, and
 * says which sources do not hold together. Pure: handed entries, it answers
 * without touching anything, which is what lets a test state each fault as a
 * sequence rather than build one in a database.
 */
final class Reconcile {

	/**
	 * More hours released than were ever reserved.
	 */
	public const OVER_RELEASED = 'over-released';

	/**
	 * Hours spent that were never given back to spend.
	 */
	public const DOUBLE_CHARGED = 'double-charged';

	/**
	 * Spend taken back, which only an adjustment may do.
	 */
	public const SPEND_REVERSED = 'spend-reversed';

	/**
	 * What one source has put through the ledger.
	 *
	 * @param array<int, array<string, mixed>> $entries One source's entries, oldest first.
	 * @return array{reserved: float, released: float, used: float, held: float}
	 */
	public static function position( array $entries ): array {
		$totals = array(
			'reserved' => 0.0,
			'released' => 0.0,
			'used'     => 0.0,
		);

		foreach ( $entries as $entry ) {
			$hours = abs( (float) ( $entry['hours'] ?? 0 ) );

			switch ( (string) ( $entry['event_type'] ?? '' ) ) {
				case Entries::WORK_RESERVATION:
				case Entries::MEETING_RESERVATION:
					$totals['reserved'] += $hours;
					break;

				case Entries::WORK_RELEASE:
				case Entries::MEETING_RELEASE:
					$totals['released'] += $hours;
					break;

				case Entries::WORK_USAGE:
				case Entries::MEETING_USAGE:
					$totals['used'] += $hours;
					break;
			}
		}

		return array(
			'reserved' => round( $totals['reserved'], 2 ),
			'released' => round( $totals['released'], 2 ),
			'used'     => round( $totals['used'], 2 ),
			'held'     => round( $totals['reserved'] - $totals['released'], 2 ),
		);
	}

	/**
	 * What is wrong with one source's entries, or empty when nothing is.
	 *
	 * @param array<int, array<string, mixed>> $entries One source's entries, oldest first.
	 * @return array<int, string>
	 */
	public static function faults( array $entries ): array {
		$position = self::position( $entries );
		$faults   = array();

		/*
		 * More given back than was ever taken. The site has been credited hours
		 * it never held, and the balance is higher than the truth — which is
		 * the direction nobody complains about and nobody notices.
		 */
		if ( $position['held'] < -0.001 ) {
			$faults[] = self::OVER_RELEASED;
		}

		/*
		 * Spend without the matching release. Converting a reservation is meant
		 * to give the hours back as it books them, so a source holding a
		 * reservation *and* carrying usage has been charged twice for one
		 * thing — the fault this whole design exists to prevent.
		 */
		if ( $position['used'] > 0 && $position['held'] > 0.001 ) {
			$faults[] = self::DOUBLE_CHARGED;
		}

		if ( self::spend_went_backwards( $entries ) ) {
			$faults[] = self::SPEND_REVERSED;
		}

		return $faults;
	}

	/**
	 * Every source on a site that does not hold together.
	 *
	 * @param array<int, array<string, mixed>> $entries A site's whole ledger, oldest first.
	 * @return array<int, array{source_type: string, source_id: string, faults: array<int, string>}>
	 */
	public static function check( array $entries ): array {
		$found = array();

		foreach ( self::by_source( $entries ) as $key => $group ) {
			$faults = self::faults( $group );

			if ( array() === $faults ) {
				continue;
			}

			[ $type, $id ] = explode( '|', (string) $key, 2 );

			$found[] = array(
				'source_type' => $type,
				'source_id'   => $id,
				'faults'      => $faults,
			);
		}

		return $found;
	}

	/**
	 * Whether a site's entries add up to the figure it is being shown.
	 *
	 * The one check that cannot be got wrong quietly, stated anyway: it is what
	 * both interfaces are ultimately claiming, and a test that asserts it is a
	 * test that fails the day somebody introduces a cached total.
	 *
	 * @param array<int, array<string, mixed>> $entries A site's whole ledger.
	 * @param float                            $shown   The balance being displayed.
	 * @return bool
	 */
	public static function agrees( array $entries, float $shown ): bool {
		return abs( Entries::balance( $entries ) - round( $shown, 2 ) ) < 0.001;
	}

	/**
	 * Whether usage ever went down.
	 *
	 * Walked in order rather than totalled, because the totals cannot show it:
	 * a usage of four followed by a usage of minus four sums to nothing and
	 * looks exactly like a source nobody ever charged.
	 *
	 * @param array<int, array<string, mixed>> $entries One source's entries, oldest first.
	 * @return bool
	 */
	private static function spend_went_backwards( array $entries ): bool {
		foreach ( $entries as $entry ) {
			$type = (string) ( $entry['event_type'] ?? '' );

			if ( Entries::WORK_USAGE !== $type && Entries::MEETING_USAGE !== $type ) {
				continue;
			}

			// Usage is a consuming type, so the ledger stores it negative. One
			// stored positive is spend being handed back under a name that has
			// no business doing it.
			if ( (float) ( $entry['hours'] ?? 0 ) > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A site's entries, grouped by what they were against.
	 *
	 * @param array<int, array<string, mixed>> $entries A site's whole ledger.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function by_source( array $entries ): array {
		$grouped = array();

		foreach ( $entries as $entry ) {
			$type = (string) ( $entry['source_type'] ?? '' );

			/*
			 * Only the two that reserve. A package allocation, a top-up and an
			 * adjustment are single entries by design — there is no pairing for
			 * them to get wrong, and grouping them would report every one of
			 * them as an unreleased reservation.
			 */
			if ( ! in_array( $type, array( 'work-item', 'meeting-occurrence' ), true ) ) {
				continue;
			}

			$grouped[ $type . '|' . (string) ( $entry['source_id'] ?? '' ) ][] = $entry;
		}

		return $grouped;
	}
}
