<?php
/**
 * Whether a site can pay for a piece of work.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Commerce;

/**
 * #150, COMM-3. The support-hours half of the gate at Up Next, with no database
 * in it.
 *
 * **It is a separate question from capacity and it stays separate.** Capacity
 * asks whether our people have the time; this asks whether the client has the
 * hours. They are answered from different records, fixed by different people,
 * and one is not evidence about the other — which is why the CAP-4
 * over-allocation reason, a decision about our own week, does nothing here.
 * Letting it would mean a studio administrator authorising a client's spend by
 * explaining our own overtime.
 *
 * **Two ways to fail, and they are not the same conversation.** A site short of
 * hours needs a top-up sold to it. A site that may not spend at all — lapsed,
 * suspended, or never on a package — needs its package sorting out, and its
 * balance is beside the point: COMM-4 freezes a lapsed site's remaining hours
 * rather than voiding them, so they are still on the ledger and still not
 * spendable. A check that read the balance alone would spend them.
 */
final class HoursGate {

	/**
	 * Nothing is in the way.
	 */
	public const CLEAR = '';

	/**
	 * The site has hours but not enough of them.
	 */
	public const NOT_ENOUGH = 'not_enough';

	/**
	 * The site is not on a package it can spend from.
	 */
	public const NO_PACKAGE = 'no_package';

	/**
	 * Whether this work can be paid for, and the figures behind the answer.
	 *
	 * @param array<string, mixed> $item        The work, as read.
	 * @param array<string, mixed> $entitlement The site's position today, from
	 *                                          {@see Support::entitlement_on()}.
	 * @param float                $balance     What the site has left.
	 * @param float                $held        What this item has already
	 *                                          reserved of that.
	 * @return array<string, mixed>
	 */
	public static function assess( array $item, array $entitlement, float $balance, float $held ): array {
		$needed = WorkHours::chargeable( $item ) ? WorkHours::planned( $item ) : 0.0;

		/*
		 * The item's own reservation is added back before the comparison.
		 * Planned work has already taken its hours out of the balance, so a
		 * check against the balance alone would refuse exactly the work whose
		 * reservation emptied it — and the refusal would be unfixable, because
		 * the only way to free the hours would be to unplan the work.
		 */
		$available = round( $balance + $held, 2 );
		$state     = (string) ( $entitlement['state'] ?? Support::NONE );

		$because = self::because( $needed, $available, (bool) ( $entitlement['may_use_hours'] ?? false ) );

		return array(
			'needed'     => $needed,
			'held'       => round( $held, 2 ),
			'available'  => $available,
			'shortfall'  => self::NOT_ENOUGH === $because ? round( $needed - $available, 2 ) : 0.0,
			'state'      => $state,
			'because'    => $because,
			'sufficient' => self::CLEAR === $because,
		);
	}

	/**
	 * The answer for work that costs nothing, worked out without reading anything.
	 *
	 * Free bugs and unplanned work ask nothing of a package, so their answer is
	 * known before any of the three figures are fetched. That matters for more
	 * than tidiness: the standup board evaluates the next gate for every card it
	 * draws, and a ledger read per card is a query count that grows with the
	 * studio's work.
	 *
	 * @return array<string, mixed>
	 */
	public static function free(): array {
		return array(
			'needed'     => 0.0,
			'held'       => 0.0,
			'available'  => 0.0,
			'shortfall'  => 0.0,
			'state'      => '',
			'because'    => self::CLEAR,
			'sufficient' => true,
		);
	}

	/**
	 * Whether this work costs the client anything, and so has to be asked about.
	 *
	 * @param array<string, mixed> $item The work, as read.
	 * @return bool
	 */
	public static function chargeable( array $item ): bool {
		return WorkHours::chargeable( $item ) && WorkHours::planned( $item ) > 0;
	}

	/**
	 * Why this work cannot be paid for, or '' when it can.
	 *
	 * @param float $needed    Hours the work costs.
	 * @param float $available Hours it could draw on.
	 * @param bool  $may_use   Whether the site may spend at all.
	 * @return string
	 */
	private static function because( float $needed, float $available, bool $may_use ): string {
		// Work that costs nothing asks nothing of the package. A free bug on a
		// lapsed site is still fixed, because the client is not paying for it.
		if ( $needed <= 0 ) {
			return self::CLEAR;
		}

		if ( ! $may_use ) {
			return self::NO_PACKAGE;
		}

		return $available >= $needed ? self::CLEAR : self::NOT_ENOUGH;
	}

	/**
	 * How a refusal reads to a person.
	 *
	 * @param array<string, mixed> $assessment What {@see self::assess()} found.
	 * @return string
	 */
	public static function label( array $assessment ): string {
		switch ( (string) ( $assessment['because'] ?? self::CLEAR ) ) {
			case self::NO_PACKAGE:
				return sprintf(
					/* translators: %s: how the site's support position reads. */
					__( 'This site cannot draw on support hours: %s.', 'blueworx-forge' ),
					Support::label( (string) $assessment['state'] )
				);

			case self::NOT_ENOUGH:
				return sprintf(
					/* translators: 1: hours short, 2: hours the work needs. */
					__( '%1$s hours short of the %2$s this work needs.', 'blueworx-forge' ),
					number_format( (float) $assessment['shortfall'], 2 ),
					number_format( (float) $assessment['needed'], 2 )
				);

			default:
				return __( 'The site has the hours for this work.', 'blueworx-forge' );
		}
	}
}
