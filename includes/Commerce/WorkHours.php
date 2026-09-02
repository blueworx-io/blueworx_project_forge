<?php
/**
 * What a piece of work should be holding, and what it should have spent.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Commerce;

use Blueworx\Forge\Work\Outcomes;
use Blueworx\Forge\Work\Stages;

/**
 * #149, COMM-3. The work half of the hour lifecycle, with no database in it.
 *
 * **This class does not perform moves. It states positions.** Asked about an
 * item, it says how many hours the ledger ought to be holding reserved against
 * it and how many it ought to have spent — both read from where the item is
 * standing, never from how it got there. {@see plan()} then compares that with
 * what the ledger actually shows and returns the entries that close the gap.
 *
 * The difference matters more than it sounds. The obvious version of this
 * feature hangs three actions off three moves: reserve on the way into Up Next,
 * convert on the way into In Development, release on cancellation. Those three
 * are right and they are not the whole board. An item can be sent back out of
 * Up Next, blocked and unblocked, re-planned twice before it starts, cancelled
 * from Blocked, or reclassified as a free bug after it was planned — and each
 * of those is a path nobody wrote a branch for, each one leaving hours held
 * against work that is not going to happen. Nothing afterwards notices, because
 * a reservation that should have been released looks exactly like one that
 * should not.
 *
 * Stating the position instead makes the whole class idempotent: running it on
 * every move, twice, or on a move that has nothing to do with hours, appends
 * nothing. That is what lets Work\Transition call it once, from the one place
 * every stage change goes through, rather than from the handful somebody
 * remembered.
 *
 * **Two rules it will not fold together.** Reserved hours track the item in
 * both directions; spent hours only ever go up. Cutting the plan after the work
 * started does not un-work it — that is a write-off, which COMM-3 says is an
 * adjustment with somebody's reason on it, and this class deliberately cannot
 * make one.
 */
final class WorkHours {

	/**
	 * What these entries are against, in the ledger's source column.
	 */
	public const SOURCE = 'work-item';

	/**
	 * Where planned work commits its hours.
	 */
	public const RESERVES_AT = 'up-next';

	/**
	 * Where committed hours become spend.
	 */
	public const SPENDS_AT = 'in-development';

	/**
	 * The one commercial class the client pays for.
	 */
	public const CHARGEABLE = 'chargeable';

	/**
	 * The hours a piece of work commits: all three seats, added up.
	 *
	 * Not the estimate, and not the Primary User's figure alone. Three people
	 * are booked and the client is paying for all three (CAP-2).
	 *
	 * @param array<string, mixed> $item The item, as read.
	 * @return float
	 */
	public static function planned( array $item ): float {
		$total = (float) ( $item['hours_primary'] ?? 0 )
			+ (float) ( $item['hours_review'] ?? 0 )
			+ (float) ( $item['hours_delivery'] ?? 0 );

		return round( max( 0.0, $total ), 2 );
	}

	/**
	 * Whether this work draws on the client's hours at all.
	 *
	 * Only work somebody has classified as chargeable. A free bug is COMM-5's:
	 * something Forge delivered and broke, fixed at nobody's cost. Unclassified
	 * is the column's default and means nobody has said — and the safe reading
	 * of silence is not "bill them".
	 *
	 * @param array<string, mixed> $item The item, as read.
	 * @return bool
	 */
	public static function chargeable( array $item ): bool {
		return self::CHARGEABLE === (string) ( $item['commercial_class'] ?? '' );
	}

	/**
	 * Where an item stands in the flow, seeing through Blocked.
	 *
	 * Blocked is a state, not a position: an item blocked out of Up Next is
	 * still planned work with people booked on it, and one blocked out of In
	 * Development is still work that started. Reading the stage column alone
	 * would release the first item's hours and re-take them on unblock, leaving
	 * two entries in the client's record for nothing having happened.
	 *
	 * @param array<string, mixed> $item The item, as read.
	 * @return string
	 */
	public static function effective_stage( array $item ): string {
		$stage = (string) ( $item['stage'] ?? '' );

		if ( Stages::BLOCKED !== $stage ) {
			return $stage;
		}

		return (string) ( $item['prior_stage'] ?? '' );
	}

	/**
	 * The hours the ledger should be holding reserved against this item.
	 *
	 * Planned work holds its hours; everything else holds none. Work that has
	 * started holds none because the reservation became spend, and closed work
	 * holds none because it is not going to happen.
	 *
	 * @param array<string, mixed> $item The item, as read.
	 * @return float
	 */
	public static function holds( array $item ): float {
		if ( ! self::chargeable( $item ) || Outcomes::is_closed( $item ) ) {
			return 0.0;
		}

		return self::RESERVES_AT === self::effective_stage( $item ) ? self::planned( $item ) : 0.0;
	}

	/**
	 * Whether the work has started, and so has hours to answer for.
	 *
	 * Position rather than stage name, so a stage added between In Development
	 * and Released counts as started without this having to be told.
	 *
	 * @param array<string, mixed> $item The item, as read.
	 * @return bool
	 */
	public static function started( array $item ): bool {
		$reached = Stages::position( self::effective_stage( $item ) );

		return $reached >= 0 && $reached >= Stages::position( self::SPENDS_AT );
	}

	/**
	 * What the ledger currently shows for one item's entries.
	 *
	 * Reservations net of releases, and usage — as magnitudes, because the
	 * ledger stores them signed and the arithmetic here is about size.
	 *
	 * @param array<int, array<string, mixed>> $entries This item's ledger entries.
	 * @return array{reserved: float, used: float}
	 */
	public static function position( array $entries ): array {
		$reserved = 0.0;
		$used     = 0.0;

		foreach ( $entries as $entry ) {
			$hours = abs( (float) ( $entry['hours'] ?? 0 ) );

			switch ( (string) ( $entry['event_type'] ?? '' ) ) {
				case Entries::WORK_RESERVATION:
					$reserved += $hours;
					break;

				case Entries::WORK_RELEASE:
					$reserved -= $hours;
					break;

				case Entries::WORK_USAGE:
					$used += $hours;
					break;
			}
		}

		return array(
			'reserved' => round( $reserved, 2 ),
			'used'     => round( $used, 2 ),
		);
	}

	/**
	 * The entries that would bring the ledger to where this item now stands.
	 *
	 * Empty when it is already there, which is the usual answer and the reason
	 * this is safe to ask on every move.
	 *
	 * @param array<string, mixed>             $item    The item, as it now stands.
	 * @param array<int, array<string, mixed>> $entries Its ledger entries so far.
	 * @return array<int, array{event_type: string, hours: float}>
	 */
	public static function plan( array $item, array $entries ): array {
		$position = self::position( $entries );
		$plan     = array();

		$difference = round( self::holds( $item ) - $position['reserved'], 2 );

		/*
		 * Never the new total: COMM-3 says nothing written is ever changed, so
		 * a bigger plan is another entry for the gap. Re-reserving the whole
		 * figure would hold twenty-six hours against a twenty-hour job.
		 *
		 * The release comes first in the returned list, and on the move that
		 * starts work that is what keeps the balance from dipping through a
		 * figure the site never owed — a gate somewhere else would one day
		 * refuse it.
		 */
		if ( $difference < 0 ) {
			$plan[] = self::entry( Entries::WORK_RELEASE, -$difference );
		} elseif ( $difference > 0 ) {
			$plan[] = self::entry( Entries::WORK_RESERVATION, $difference );
		}

		if ( ! self::chargeable( $item ) || ! self::started( $item ) ) {
			return $plan;
		}

		/*
		 * Spend is a floor, never a target. Hours added after the work started
		 * are charged as they are added; hours taken away are not refunded,
		 * because the time was worked. Giving them back is a decision with a
		 * reason on it, and this is not the place that makes it.
		 */
		$short = round( self::planned( $item ) - $position['used'], 2 );

		if ( $short > 0 ) {
			$plan[] = self::entry( Entries::WORK_USAGE, $short );
		}

		return $plan;
	}

	/**
	 * One planned entry, as the ledger's caller shape.
	 *
	 * @param string $type  Event type.
	 * @param float  $hours Magnitude; the ledger gives it its sign.
	 * @return array{event_type: string, hours: float}
	 */
	private static function entry( string $type, float $hours ): array {
		return array(
			'event_type' => $type,
			'hours'      => round( $hours, 2 ),
		);
	}
}
