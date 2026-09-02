<?php
/**
 * Putting a work item's hours into the ledger, and keeping them there.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Commerce;

/**
 * #149, COMM-3. The writing half of {@see WorkHours}, and the only thing that
 * puts a work item's hours into the ledger.
 *
 * It does one thing: read what the ledger holds for an item, ask WorkHours what
 * it ought to hold, and append the difference. Every rule is in the pure class;
 * what is here is the read, the write, and the answer to whether the write
 * happened.
 *
 * **It is called on every move, not on the moves that sound commercial.** That
 * only works because it is idempotent: an item whose ledger already agrees with
 * it produces no entries at all, so calling it from Work\Transition's single
 * commit point costs one indexed read on a move that changes nothing. The
 * alternative — calling it from the three or four places somebody identifies as
 * hour-related — is how the fifth place gets missed.
 *
 * **A refused entry is a refused move.** #149 asks that the ledger and the item
 * never disagree, and the only way to keep that promise when the ledger says no
 * is for the move to not have happened either. So this reports failure and its
 * caller rolls back, rather than leaving the stage changed and the hours short.
 */
final class WorkLedger {

	/**
	 * Brings an item's ledger position into line with where the item stands.
	 *
	 * @param array<string, mixed> $item  The item, as it now stands.
	 * @param int                  $actor Who made the change that caused this.
	 * @return bool False when an entry was refused, and nothing may be kept.
	 */
	public static function reconcile( array $item, int $actor ): bool {
		$id   = (string) ( $item['id'] ?? '' );
		$site = (string) ( $item['client_site_id'] ?? '' );

		if ( '' === $id || '' === $site ) {
			return false;
		}

		foreach ( WorkHours::plan( $item, self::entries( $id ) ) as $entry ) {
			$written = Ledger::append(
				array(
					'client_site_id' => $site,
					'event_type'     => (string) $entry['event_type'],
					'hours'          => (float) $entry['hours'],
					'source_type'    => WorkHours::SOURCE,
					'source_id'      => $id,
					'actor'          => $actor,
				)
			);

			if ( null === $written ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether an item's hours could be brought into line, without doing it.
	 *
	 * For the caller that has to decide before it writes rather than after. It
	 * asks the ledger the same question {@see Ledger::append()} would — would
	 * this take the site below nought — rather than guessing at the answer.
	 *
	 * @param array<string, mixed> $item The item as it would stand.
	 * @return bool
	 */
	public static function affordable( array $item ): bool {
		$id      = (string) ( $item['id'] ?? '' );
		$site    = (string) ( $item['client_site_id'] ?? '' );
		$balance = Ledger::balance( $site );

		foreach ( WorkHours::plan( $item, self::entries( $id ) ) as $entry ) {
			$balance = round( $balance + Entries::signed( (string) $entry['event_type'], (float) $entry['hours'] ), 2 );

			if ( $balance < 0 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * What the ledger holds against one item.
	 *
	 * @param string $item_id The item.
	 * @return array{reserved: float, used: float}
	 */
	public static function position( string $item_id ): array {
		return WorkHours::position( self::entries( $item_id ) );
	}

	/**
	 * The support-hours answer for one item, for the gate at Up Next (#150).
	 *
	 * The three readings {@see HoursGate} needs and cannot take for itself: what
	 * the site is entitled to today, what it has left, and what this item is
	 * already holding of that.
	 *
	 * @param array<string, mixed> $item The work, as read.
	 * @return array<string, mixed>
	 */
	public static function gate( array $item ): array {
		// Work that costs nothing has its answer already, and the three reads
		// below are per item on a screen that draws many of them.
		if ( ! HoursGate::chargeable( $item ) ) {
			return HoursGate::free();
		}

		$site = (string) ( $item['client_site_id'] ?? '' );

		return HoursGate::assess(
			$item,
			Assignments::entitlement_on( $site, gmdate( 'Y-m-d' ) ),
			Ledger::balance( $site ),
			(float) self::position( (string) ( $item['id'] ?? '' ) )['reserved']
		);
	}

	/**
	 * One item's ledger entries.
	 *
	 * @param string $item_id The item.
	 * @return array<int, array<string, mixed>>
	 */
	private static function entries( string $item_id ): array {
		return Ledger::for_source( WorkHours::SOURCE, $item_id );
	}
}
