<?php
/**
 * What a meeting costs a client, and when.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Meetings;

use Blueworx\Forge\Commerce\Entries;

/**
 * #154, MEET-4 and MEET-5. The meeting half of the hour lifecycle, with no
 * database in it.
 *
 * The same shape as {@see \Blueworx\Forge\Commerce\WorkHours}, and for the same
 * reason: it states a position rather than performing an action. Asked about a
 * meeting, it says what the ledger ought to be holding against it; {@see plan()}
 * then compares that with what the ledger shows and returns the difference.
 *
 * **Only a held meeting costs anything** (MEET-5). No late-cancellation charge,
 * no no-show charge, and clients are not required to cancel at all. Three of
 * the four ways a meeting can end therefore leave the balance exactly where it
 * was — and the fourth, the meeting everybody simply forgot, is the one that
 * matters most here, because *nothing happens* to trigger it. Nobody cancels
 * it. Nobody marks it held. There is no scheduled job in this plugin to notice.
 *
 * So its hours are released by the date having passed, not by an event. A
 * meeting that has been and gone unheld is already released as far as this
 * class is concerned, and the entry saying so is written the next time anybody
 * looks. A release that waited for a cron would be a balance that is wrong for
 * exactly as long as the cron was not running.
 *
 * **Reserved, not forecast, only inside two boundaries** (MEET-4). Twelve weeks
 * ahead, so a client's balance shows what is actually coming rather than every
 * meeting of the year; and inside the term, because hours reserved against a
 * package that ends first are hours nobody has bought.
 */
final class MeetingHours {

	/**
	 * What these entries are against, in the ledger's source column.
	 */
	public const SOURCE = 'meeting-occurrence';

	/**
	 * Far enough away that it is shown but not held (MEET-4).
	 */
	public const FORECAST = 'forecast';

	/**
	 * Coming up, and its hours are committed.
	 */
	public const RESERVED = 'reserved';

	/**
	 * It happened, and the hours are spent.
	 */
	public const USED = 'used';

	/**
	 * It is not going to happen, or it already did not.
	 */
	public const RELEASED = 'released';

	/**
	 * How far ahead hours are held, in days. Twelve weeks (MEET-4).
	 */
	public const HORIZON_DAYS = 84;

	/**
	 * The last day hours are held for, counting from today.
	 *
	 * @param string $today YYYY-MM-DD.
	 * @return string
	 */
	public static function horizon_end( string $today ): string {
		return gmdate( 'Y-m-d', (int) strtotime( $today . ' 00:00:00 UTC' ) + ( self::HORIZON_DAYS * DAY_IN_SECONDS ) );
	}

	/**
	 * Where a meeting stands, as far as the ledger is concerned.
	 *
	 * @param array<string, mixed> $meeting      One merged occurrence.
	 * @param string               $today        YYYY-MM-DD.
	 * @param string               $term_ends_on Last day of the active term, or
	 *                                           '' where there is no end.
	 * @return string One of FORECAST, RESERVED, USED, RELEASED.
	 */
	public static function state_of( array $meeting, string $today, string $term_ends_on ): string {
		$status = (string) ( $meeting['status'] ?? Occurrence::SCHEDULED );

		if ( Occurrence::draws_hours( $status ) ) {
			return self::USED;
		}

		// Called off, or nobody came. MEET-5: neither costs anything.
		if ( Occurrence::settled( $status ) ) {
			return self::RELEASED;
		}

		$on = (string) ( $meeting['on'] ?? '' );

		/*
		 * Been and gone, still scheduled. The meeting nobody did anything
		 * about, and the reason this is decided by date rather than by event.
		 * The comparison is against the whole day, because a meeting happening
		 * this afternoon has not been and gone this morning.
		 */
		if ( '' === $on || $on < $today ) {
			return self::RELEASED;
		}

		if ( $on > self::horizon_end( $today ) ) {
			return self::FORECAST;
		}

		// Past the end of the term the client has paid for. Inside twelve weeks
		// or not, those hours belong to a package nobody has renewed yet.
		if ( '' !== $term_ends_on && $on > $term_ends_on ) {
			return self::FORECAST;
		}

		return self::RESERVED;
	}

	/**
	 * What the ledger currently shows against one meeting.
	 *
	 * @param array<int, array<string, mixed>> $entries That meeting's entries.
	 * @return array{reserved: float, used: float}
	 */
	public static function position( array $entries ): array {
		$reserved = 0.0;
		$used     = 0.0;

		foreach ( $entries as $entry ) {
			$hours = abs( (float) ( $entry['hours'] ?? 0 ) );

			switch ( (string) ( $entry['event_type'] ?? '' ) ) {
				case Entries::MEETING_RESERVATION:
					$reserved += $hours;
					break;

				case Entries::MEETING_RELEASE:
					$reserved -= $hours;
					break;

				case Entries::MEETING_USAGE:
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
	 * The entries that would bring the ledger to where this meeting stands.
	 *
	 * Empty when it is already there, which is the usual answer and what makes
	 * this safe to run on every read.
	 *
	 * @param array<string, mixed>             $meeting      One merged occurrence.
	 * @param array<int, array<string, mixed>> $entries      Its ledger entries so far.
	 * @param string                           $today        YYYY-MM-DD.
	 * @param string                           $term_ends_on Last day of the term, or ''.
	 * @return array<int, array{event_type: string, hours: float}>
	 */
	public static function plan( array $meeting, array $entries, string $today, string $term_ends_on ): array {
		$hours = round( (float) ( $meeting['planned_hours'] ?? 0 ), 2 );

		if ( $hours <= 0 ) {
			return array();
		}

		$state    = self::state_of( $meeting, $today, $term_ends_on );
		$position = self::position( $entries );
		$plan     = array();

		$holds      = self::RESERVED === $state ? $hours : 0.0;
		$difference = round( $holds - $position['reserved'], 2 );

		/*
		 * The release comes first, and on the move that marks a meeting held
		 * that is what stops the balance dipping through a figure the client
		 * never owed.
		 */
		if ( $difference < 0 ) {
			$plan[] = self::entry( Entries::MEETING_RELEASE, -$difference );
		} elseif ( $difference > 0 ) {
			$plan[] = self::entry( Entries::MEETING_RESERVATION, $difference );
		}

		if ( self::USED !== $state ) {
			return $plan;
		}

		/*
		 * Spend is a floor, never a target. Cancelling a meeting after marking
		 * it held does not un-hold it: the time was spent, and giving it back
		 * is a decision with a reason on it rather than arithmetic.
		 */
		$short = round( $hours - $position['used'], 2 );

		if ( $short > 0 ) {
			$plan[] = self::entry( Entries::MEETING_USAGE, $short );
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
