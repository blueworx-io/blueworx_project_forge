<?php
/**
 * Part-year hours and price, worked out on exact dates.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Commerce;

/**
 * #147, COMM-2. Part-year assignments produce exactly the right hours.
 *
 * Pure, and every figure a client is shown comes from one method here. That is
 * the acceptance criterion read as a design: *the preview matches what the
 * ledger receives, to the hour.* The only way to be sure of that is for there
 * to be one calculation, done once, whose answer is carried forward — so
 * {@see self::preview()} produces the number, and whoever writes the allocation
 * writes that number rather than recalculating from the dates. A second
 * calculation is a second answer, and the two disagree the first time either
 * one's rounding changes.
 *
 * **Exact days, never months.** A whole-month approximation is out by up to a
 * month's hours, which on a ten-hour package is most of it. So a ratio is days
 * over days, counted on the calendar, and February has whatever length it
 * actually had that year.
 *
 * **Rounded once, at the end.** Hours to the nearest half hour and price to the
 * nearest whole currency unit, per COMM-2, applied to the full figure rather
 * than accumulated — rounding the ratio first and multiplying afterwards is how
 * a figure comes out a quarter of an hour wrong and nobody can say why.
 *
 * Pro-rata is the exception rather than the rule (COMM-1): an ordinary
 * assignment starts its own twelve-month term and gets the whole package. This
 * is for the client who asked to renew alongside everything else.
 */
final class ProRata {

	/**
	 * A day, in seconds. Dates here are calendar dates in UTC and never
	 * local times, so a day is always exactly this long.
	 */
	private const DAY = 86400;

	/**
	 * Whole days from one date to another, counting both ends.
	 *
	 * Both ends, because a term is the set of days a client has cover on: from
	 * the first of January to the thirty-first of December is three hundred and
	 * sixty-five days of cover, not three hundred and sixty-four. Getting this
	 * off by one costs a day's hours on every part-year assignment ever made,
	 * and nobody notices until a client counts.
	 *
	 * @param string $from YYYY-MM-DD.
	 * @param string $to   YYYY-MM-DD.
	 * @return int Zero when the range runs backwards or a date is unreadable.
	 */
	public static function days( string $from, string $to ): int {
		$start = self::midnight( $from );
		$end   = self::midnight( $to );

		if ( null === $start || null === $end || $end < $start ) {
			return 0;
		}

		return (int) round( ( $end - $start ) / self::DAY ) + 1;
	}

	/**
	 * The last day of a term that starts on a date and runs for some months.
	 *
	 * The day *before* the anniversary, so a twelve-month term from the first
	 * of March ends on the last day of the following February rather than
	 * overlapping its own renewal by a day.
	 *
	 * The end of a month is clamped rather than allowed to overflow, which is
	 * the leap-year trap: PHP's own month arithmetic turns the thirty-first of
	 * January plus one month into the second or third of March, and a term that
	 * quietly gains two days gains two days of hours with it.
	 *
	 * @param string $from   YYYY-MM-DD.
	 * @param int    $months How long.
	 * @return string YYYY-MM-DD, or '' when the date is unreadable.
	 */
	public static function term_end( string $from, int $months ): string {
		$start = self::midnight( $from );

		if ( null === $start || $months < 1 ) {
			return '';
		}

		$year  = (int) gmdate( 'Y', $start );
		$month = (int) gmdate( 'n', $start );
		$day   = (int) gmdate( 'j', $start );

		$total = ( $month - 1 ) + $months;
		$year += intdiv( $total, 12 );
		$month = ( $total % 12 ) + 1;

		// Clamped to the month's real length. The twenty-ninth of February plus
		// twelve months is the twenty-eighth in an ordinary year, not the first
		// of March.
		$day = min( $day, (int) gmdate( 't', (int) gmmktime( 0, 0, 0, $month, 1, $year ) ) );

		return gmdate( 'Y-m-d', (int) gmmktime( 0, 0, 0, $month, $day, $year ) - self::DAY );
	}

	/**
	 * What share of a full term a part of it is.
	 *
	 * @param string $from      YYYY-MM-DD, the first day covered.
	 * @param string $to        YYYY-MM-DD, the last day covered.
	 * @param int    $term_days How many days a full term would be.
	 * @return float Between 0 and 1. Capped, because a part cannot exceed the
	 *               whole and a caller who has muddled the dates should get a
	 *               full package rather than a bill for more than one.
	 */
	public static function ratio( string $from, string $to, int $term_days ): float {
		if ( $term_days < 1 ) {
			return 0.0;
		}

		return min( 1.0, max( 0.0, self::days( $from, $to ) / $term_days ) );
	}

	/**
	 * Hours, to the nearest half hour (COMM-2).
	 *
	 * @param float $full  A full term's hours.
	 * @param float $ratio The share.
	 * @return float
	 */
	public static function hours( float $full, float $ratio ): float {
		return round( $full * $ratio * 2 ) / 2;
	}

	/**
	 * Price, to the nearest whole currency unit (COMM-2).
	 *
	 * @param int   $full  A full term's price.
	 * @param float $ratio The share.
	 * @return int
	 */
	public static function price( int $full, float $ratio ): int {
		return (int) round( $full * $ratio );
	}

	/**
	 * What a part-term assignment comes to, and how it was arrived at.
	 *
	 * Everything a person needs to check the sum before agreeing to it, which
	 * is what COMM-2's preview is for — and the same array is what the
	 * allocation is written from, so the two cannot differ.
	 *
	 * @param array<string, mixed> $version   A package version's terms.
	 * @param string               $from      YYYY-MM-DD, the first day covered.
	 * @param string               $to        YYYY-MM-DD, the last day covered.
	 * @param int                  $term_days The full term to measure against,
	 *                                        or 0 to take it from $from. Given
	 *                                        only by {@see self::unused()},
	 *                                        which is measuring part of a term
	 *                                        that started somewhere else.
	 * @return array<string, mixed>
	 */
	public static function preview( array $version, string $from, string $to, int $term_days = 0 ): array {
		$months = max( 1, (int) ( $version['validity_months'] ?? Terms::DEFAULT_VALIDITY_MONTHS ) );

		/*
		 * A full term measured from the same start date as the part, so the
		 * denominator is the term this client would otherwise have had. Using a
		 * fixed 365 instead would over-pay every client whose part-term
		 * happens to straddle a leap day and under-pay the rest.
		 */
		$term_days = $term_days > 0 ? $term_days : self::days( $from, self::term_end( $from, $months ) );
		$ratio     = self::ratio( $from, $to, $term_days );

		return array(
			'from'       => $from,
			'to'         => $to,
			'days'       => self::days( $from, $to ),
			'term_days'  => $term_days,
			'ratio'      => round( $ratio, 6 ),
			'hours'      => self::hours( (float) ( $version['hours'] ?? 0 ), $ratio ),
			'price'      => self::price( (int) ( $version['price'] ?? 0 ), $ratio ),
			'currency'   => (string) ( $version['currency'] ?? 'GBP' ),
			'full_hours' => round( (float) ( $version['hours'] ?? 0 ), 2 ),
			'full_price' => (int) ( $version['price'] ?? 0 ),
		);
	}

	/**
	 * What the unused part of an outgoing package is worth (COMM-2).
	 *
	 * For an upgrade mid-term: the client has paid for a year and is a few
	 * months in, so the rest of it is credited against the new package. Days
	 * again, and the same rounding, because a credit worked out a different way
	 * from the charge is a credit somebody will dispute.
	 *
	 * Downgrades are not this. COMM-2 does not permit one mid-term, so there is
	 * no method here that would work one out — a calculation nobody may use is
	 * a calculation somebody eventually uses.
	 *
	 * @param array<string, mixed> $version  The outgoing package's terms.
	 * @param string               $term_from YYYY-MM-DD, when its term started.
	 * @param string               $unused_from YYYY-MM-DD, the first day no longer wanted.
	 * @return array<string, mixed> days, ratio, price and hours of the unused part.
	 */
	public static function unused( array $version, string $term_from, string $unused_from ): array {
		$months   = max( 1, (int) ( $version['validity_months'] ?? Terms::DEFAULT_VALIDITY_MONTHS ) );
		$term_end = self::term_end( $term_from, $months );

		/*
		 * Measured against the term that is actually running, not against a
		 * hypothetical one starting on the day of the upgrade. Those are
		 * different lengths whenever a leap day falls in one and not the other,
		 * and the credit has to be a share of what the client bought.
		 */
		return self::preview( $version, $unused_from, $term_end, self::days( $term_from, $term_end ) );
	}

	/**
	 * A date's midnight, in UTC.
	 *
	 * @param string $date YYYY-MM-DD.
	 * @return int|null Null when it is not a date.
	 */
	private static function midnight( string $date ): ?int {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return null;
		}

		$at = strtotime( $date . ' 00:00:00 UTC' );

		return false === $at ? null : (int) $at;
	}
}
