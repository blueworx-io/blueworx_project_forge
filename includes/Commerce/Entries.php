<?php
/**
 * What can happen to a site's hours, and what each of those means.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Commerce;

/**
 * #148, COMM-3 and COMM-4. The ledger's vocabulary, and nothing about storing it.
 *
 * Pure, so the rules that matter can be argued with in a test rather than
 * against a database. Three of them carry the whole thing:
 *
 * - **A balance is the sum of its entries.** There is no stored total here and
 *   no method that produces one any other way. Everything else follows from
 *   that: a correction is another entry, an expiry is another entry, and there
 *   is no figure anywhere that the entries do not add up to.
 * - **A reservation and its usage are not the same hours twice.** Work reserves
 *   at Up Next and converts at In Development, and converting releases the
 *   reservation as it books the usage. Charging both without the release is
 *   the obvious implementation and it bills every client double.
 * - **Anything discretionary needs a reason.** An adjustment, and any entry
 *   that would take a balance below zero, is somebody's decision rather than
 *   the machine's — and a decision with no reason recorded is one nobody can
 *   answer for six months later.
 */
final class Entries {

	/* --------------------------------------------------------- what adds */

	/**
	 * A package's hours, granted for its term.
	 */
	public const ALLOCATION = 'allocation';

	/**
	 * Hours bought on top, with their own expiry (COMM-4).
	 */
	public const TOP_UP = 'top-up';

	/**
	 * Work that will not now happen, giving its reservation back.
	 */
	public const WORK_RELEASE = 'work-release';

	/**
	 * A meeting that was not held, giving its reservation back (MEET-5).
	 */
	public const MEETING_RELEASE = 'meeting-release';

	/**
	 * Hours carried into a new term.
	 */
	public const ROLLOVER = 'rollover';

	/* ----------------------------------------------------- what consumes */

	/**
	 * Work scheduled: its hours are committed from now on (COMM-3).
	 */
	public const WORK_RESERVATION = 'work-reservation';

	/**
	 * Work started: the reservation becomes real spend.
	 */
	public const WORK_USAGE = 'work-usage';

	/**
	 * A meeting inside the horizon, holding its hours (MEET-4).
	 */
	public const MEETING_RESERVATION = 'meeting-reservation';

	/**
	 * A meeting marked held (MEET-5).
	 */
	public const MEETING_USAGE = 'meeting-usage';

	/**
	 * Hours reaching their expiry date unspent.
	 */
	public const EXPIRY = 'expiry';

	/* ------------------------------------------------------ what does both */

	/**
	 * Somebody's decision, in either direction, with a reason.
	 *
	 * Includes the CAP-3 post-review adjustment: time spent after review is
	 * charged here with its reason attached, and the client sees it.
	 */
	public const ADJUSTMENT = 'adjustment';

	/**
	 * Every event type, in the order the data model lists them.
	 *
	 * @var array<int, string>
	 */
	public const TYPES = array(
		self::ALLOCATION,
		self::TOP_UP,
		self::WORK_RESERVATION,
		self::WORK_USAGE,
		self::WORK_RELEASE,
		self::MEETING_RESERVATION,
		self::MEETING_USAGE,
		self::MEETING_RELEASE,
		self::ADJUSTMENT,
		self::EXPIRY,
		self::ROLLOVER,
	);

	/**
	 * The ones that can only put hours in.
	 *
	 * @var array<int, string>
	 */
	public const ADDS = array(
		self::ALLOCATION,
		self::TOP_UP,
		self::WORK_RELEASE,
		self::MEETING_RELEASE,
		self::ROLLOVER,
	);

	/**
	 * The ones that can only take hours out.
	 *
	 * @var array<int, string>
	 */
	public const CONSUMES = array(
		self::WORK_RESERVATION,
		self::WORK_USAGE,
		self::MEETING_RESERVATION,
		self::MEETING_USAGE,
		self::EXPIRY,
	);

	/**
	 * The ones that must say why.
	 *
	 * Only the adjustment, by type. An entry that takes a balance below zero
	 * also has to, whatever its type — see {@see self::refuse()}.
	 *
	 * @var array<int, string>
	 */
	public const NEEDS_REASON = array( self::ADJUSTMENT );

	/**
	 * Longest a reason may be, matching the column.
	 */
	public const MAX_REASON = 191;

	/**
	 * Whether this is one of the eleven.
	 *
	 * @param string $type Candidate.
	 * @return bool
	 */
	public static function exists( string $type ): bool {
		return in_array( $type, self::TYPES, true );
	}

	/**
	 * The hours an entry of this type carries, given a magnitude.
	 *
	 * The sign belongs to the type rather than to the caller, so an allocation
	 * cannot be entered as a negative by a mistyped minus and a reservation
	 * cannot quietly credit a site. An adjustment is the one exception, because
	 * it is the one that genuinely goes both ways.
	 *
	 * @param string $type  One of {@see self::TYPES}.
	 * @param float  $hours How many, signed only for an adjustment.
	 * @return float
	 */
	public static function signed( string $type, float $hours ): float {
		if ( self::ADJUSTMENT === $type ) {
			return round( $hours, 2 );
		}

		$size = round( abs( $hours ), 2 );

		return in_array( $type, self::CONSUMES, true ) ? -$size : $size;
	}

	/**
	 * Why this entry cannot be written, or '' when it can.
	 *
	 * @param array<string, mixed> $entry   type, hours, source_id, actor, reason.
	 * @param float                $balance The balance before it.
	 * @param bool                 $override Whether a Primary administrator has allowed a negative balance.
	 * @return string
	 */
	public static function refuse( array $entry, float $balance, bool $override = false ): string {
		$type = (string) ( $entry['event_type'] ?? '' );

		if ( ! self::exists( $type ) ) {
			return 'unknown_event_type';
		}

		/*
		 * Signed here rather than trusted from the caller, so this cannot be
		 * asked the wrong question. Handed a bare 12 for a reservation, a
		 * version that trusted the sign would report a balance going *up* and
		 * wave through a site spending hours it does not have.
		 */
		$hours  = self::signed( $type, (float) ( $entry['hours'] ?? 0 ) );
		$reason = trim( (string) ( $entry['reason'] ?? '' ) );

		// A nought-hour entry says nothing and adds a row to a record people
		// read. Whatever was meant by it, it was not this.
		if ( 0.0 === round( $hours, 2 ) ) {
			return 'no_hours';
		}

		/*
		 * Never null, per the data model. An entry that cannot say where it
		 * came from cannot be reconciled against anything, and reconciling is
		 * the only reason this table exists.
		 */
		if ( '' === (string) ( $entry['source_id'] ?? '' ) ) {
			return 'no_source';
		}

		// Never system-anonymous, also per the data model. Every hour that
		// moved, moved because somebody did something.
		if ( 0 >= (int) ( $entry['actor'] ?? 0 ) ) {
			return 'no_actor';
		}

		if ( in_array( $type, self::NEEDS_REASON, true ) && '' === $reason ) {
			return 'no_reason';
		}

		/*
		 * COMM-3: balances may not go negative without the Primary
		 * administrator's override, and the override is recorded. Recorded
		 * means a reason, so an override with nothing said for it is refused
		 * exactly like an adjustment with nothing said for it.
		 */
		if ( round( $balance + $hours, 2 ) < 0 ) {
			if ( ! $override ) {
				return 'would_go_negative';
			}

			if ( '' === $reason ) {
				return 'no_reason';
			}
		}

		return '';
	}

	/**
	 * A balance from its entries, and only from its entries.
	 *
	 * @param array<int, array<string, mixed>> $entries The entries.
	 * @return float
	 */
	public static function balance( array $entries ): float {
		$total = 0.0;

		foreach ( $entries as $entry ) {
			$total += (float) $entry['hours'];
		}

		return round( $total, 2 );
	}

	/**
	 * The order hours are drawn down in (COMM-4).
	 *
	 * Soonest to expire first, and where two expire together the package's
	 * hours go before a top-up's. The reason is the client's: hours that are
	 * about to lapse are worth using, and hours with no expiry at all are worth
	 * keeping — an order that spent the durable ones first would burn what
	 * cannot be replaced and let what was already paid for lapse.
	 *
	 * Credits with no expiry sort last, which is the same rule read at its
	 * limit: never expiring is later than any date.
	 *
	 * @param array<int, array<string, mixed>> $credits Entries that added hours.
	 * @return array<int, array<string, mixed>>
	 */
	public static function consumption_order( array $credits ): array {
		usort(
			$credits,
			static function ( array $a, array $b ): int {
				$a_at = (int) ( $a['expires_at'] ?? 0 );
				$b_at = (int) ( $b['expires_at'] ?? 0 );

				// Zero means never, so it sorts after every real date rather
				// than before every one of them.
				$a_at = 0 === $a_at ? PHP_INT_MAX : $a_at;
				$b_at = 0 === $b_at ? PHP_INT_MAX : $b_at;

				if ( $a_at !== $b_at ) {
					return $a_at <=> $b_at;
				}

				return self::tie_rank( (string) $a['event_type'] ) <=> self::tie_rank( (string) $b['event_type'] );
			}
		);

		return $credits;
	}

	/**
	 * Which credit goes first when two expire on the same day.
	 *
	 * @param string $type The event type.
	 * @return int Lower goes first.
	 */
	private static function tie_rank( string $type ): int {
		return self::TOP_UP === $type ? 1 : 0;
	}
}
