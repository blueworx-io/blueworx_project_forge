<?php
/**
 * The weeks a capacity range is read in.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Capacity;

/**
 * A date range, cut into weeks (#139).
 *
 * Weeks rather than days because that is how somebody plans, and rather than
 * months because a month hides the week where three things land together. They
 * start on Monday, so a week reads as a working week rather than as a fortnight
 * split down the middle.
 *
 * The first and last weeks are clipped to the range asked for. A view that
 * quietly widened the range would report commitments outside the period
 * somebody is looking at, and the total would not match the cells.
 */
final class Periods {

	/**
	 * The most weeks one call will produce, matching Availability's own guard
	 * against a mistyped year.
	 */
	public const MAX_WEEKS = 160;

	/**
	 * The weeks a range covers.
	 *
	 * @param string $from YYYY-MM-DD, inclusive.
	 * @param string $to   YYYY-MM-DD, inclusive.
	 * @return array<int, array{from: string, to: string}>
	 */
	public static function weeks( string $from, string $to ): array {
		if ( '' === $from || '' === $to || $to < $from ) {
			return array();
		}

		$weeks = array();
		$start = $from;
		$made  = 0;

		while ( $start <= $to && $made < self::MAX_WEEKS ) {
			$end = self::sunday_of( $start );

			$weeks[] = array(
				'from' => $start,
				'to'   => $end > $to ? $to : $end,
			);

			$start = self::next_day( $end );
			++$made;
		}

		return $weeks;
	}

	/**
	 * The Sunday ending the week a date falls in.
	 *
	 * @param string $date YYYY-MM-DD.
	 * @return string
	 */
	private static function sunday_of( string $date ): string {
		$midnight = (int) strtotime( $date . ' 00:00:00 UTC' );
		$weekday  = (int) gmdate( 'N', $midnight );

		return gmdate( 'Y-m-d', $midnight + ( ( 7 - $weekday ) * DAY_IN_SECONDS ) );
	}

	/**
	 * The day after a date.
	 *
	 * @param string $date YYYY-MM-DD.
	 * @return string
	 */
	private static function next_day( string $date ): string {
		return gmdate( 'Y-m-d', (int) strtotime( $date . ' 00:00:00 UTC' ) + DAY_IN_SECONDS );
	}
}
