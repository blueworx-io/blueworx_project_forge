<?php
/**
 * The studio's two ways of changing a balance by hand.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Commerce;

/**
 * #157, COMM-3 and COMM-4. Selling more hours, and correcting the record.
 *
 * Two things that look similar and are not. **A top-up is hours somebody
 * bought**: it adds to the balance, it carries its own expiry, and it is a
 * transaction. **An adjustment is somebody's decision**: it goes either way, it
 * needs a reason, and it is the only route by which hours move without money
 * changing hands.
 *
 * Kept apart so the ledger can be read. A write-off entered as a negative
 * top-up and a top-up entered as a positive adjustment would both add up, and
 * the record a client is shown when they query a bill would no longer say what
 * actually happened.
 *
 * Neither of them is where the reason is enforced — {@see Entries::refuse()}
 * does that, for every entry type at once, so there is no second door. What is
 * here is the two defaults that belong to selling: a top-up's twelve-month
 * expiry (COMM-4), and the fact that an adjustment has none.
 */
final class Sales {

	/**
	 * How long bought hours last by default (COMM-4).
	 */
	public const TOP_UP_MONTHS = 12;

	/**
	 * When hours bought on a date run out.
	 *
	 * The awkward case is the one every date library gets asked about: hours
	 * bought on the thirty-first of August expire at the end of the following
	 * August, and adding twelve months to the thirty-first of a short month
	 * must not roll into the first of the next. So the day is clamped to the
	 * last of the target month, which for an expiry is the reading that favours
	 * the client — they get the whole month they paid for.
	 *
	 * @param string $from   YYYY-MM-DD the hours were bought.
	 * @param int    $months How long they last.
	 * @return int Unix time at the end of the last day, or 0 when the date is not one.
	 */
	public static function expiry_for( string $from, int $months = self::TOP_UP_MONTHS ): int {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || $months <= 0 ) {
			return 0;
		}

		[ $year, $month, $day ] = array_map( 'intval', explode( '-', $from ) );

		if ( ! checkdate( $month, $day, $year ) ) {
			return 0;
		}

		$target = $month + $months;
		$year  += intdiv( $target - 1, 12 );
		$month  = ( ( $target - 1 ) % 12 ) + 1;
		$last   = (int) gmdate( 't', (int) gmmktime( 0, 0, 0, $month, 1, $year ) );

		return (int) gmmktime( 23, 59, 59, $month, min( $day, $last ), $year );
	}

	/**
	 * Sells a site more hours.
	 *
	 * @param string $client_site_id The site.
	 * @param float  $hours          How many.
	 * @param string $reason         What was bought, for the record.
	 * @param int    $actor          Who sold it.
	 * @param string $on             YYYY-MM-DD it was bought, or '' for today.
	 * @return array<string, mixed>|null Null when it was refused.
	 */
	public static function top_up( string $client_site_id, float $hours, string $reason, int $actor, string $on = '' ): ?array {
		$on = '' === $on ? gmdate( 'Y-m-d' ) : $on;

		if ( $hours <= 0 ) {
			// A nought-hour top-up is not a sale, and a negative one is
			// somebody reaching for an adjustment and finding the wrong form.
			return null;
		}

		return Ledger::append(
			array(
				'client_site_id' => $client_site_id,
				'event_type'     => Entries::TOP_UP,
				'hours'          => $hours,
				'source_type'    => 'top-up',

				/*
				 * A top-up has no record of its own to point at, so it is its
				 * own source. The ledger refuses an entry that cannot say where
				 * it came from, and reconciling is the only reason the table
				 * exists — so this is a real id rather than a blank.
				 */
				'source_id'      => $client_site_id,
				'reason'         => $reason,
				'expires_at'     => self::expiry_for( $on ),
				'actor'          => $actor,
				'occurred_at'    => (int) strtotime( $on . ' 00:00:00 UTC' ),
			)
		);
	}

	/**
	 * Corrects a balance, in either direction, for a stated reason.
	 *
	 * @param string $client_site_id The site.
	 * @param float  $hours          Signed: negative takes hours away.
	 * @param string $reason         Why. Refused without one.
	 * @param int    $actor          Who decided.
	 * @param bool   $override       Whether a Primary administrator has allowed
	 *                               the balance to go negative (COMM-3).
	 * @return array<string, mixed>|null Null when it was refused.
	 */
	public static function adjust( string $client_site_id, float $hours, string $reason, int $actor, bool $override = false ): ?array {
		return Ledger::append(
			array(
				'client_site_id' => $client_site_id,
				'event_type'     => Entries::ADJUSTMENT,
				'hours'          => $hours,
				'source_type'    => 'adjustment',
				'source_id'      => $client_site_id,
				'reason'         => $reason,
				'actor'          => $actor,
			),
			$override
		);
	}
}
