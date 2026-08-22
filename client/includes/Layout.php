<?php
/**
 * Where a date goes on the client timeline and calendar.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * The arithmetic behind the two dated views (#128).
 *
 * Separate from the screens that draw them because it is the part that can be
 * wrong without looking wrong. A bar three pixels off is invisible in review
 * and obvious to a client comparing the chart with the date on the card.
 *
 * Two rules run through all of it. Work with no dates is never dropped — it is
 * handed back separately so a screen can list it, the same promise the studio
 * calendar makes (#120). And a span of one day still has width, because a bar
 * nobody can see is the same as work that is not on the chart at all.
 */
final class Layout {

	/**
	 * The dates a timeline bar is drawn from, in the order they are preferred.
	 *
	 * @var array<int, string>
	 */
	private const SPAN = array( 'planned_start', 'planned_due' );

	/**
	 * Every date an item can carry, and what each one means on a calendar.
	 *
	 * @var array<string, string>
	 */
	public const KINDS = array(
		'planned_start'  => 'starts',
		'planned_due'    => 'due',
		'review_target'  => 'review',
		'release_target' => 'release',
	);

	/**
	 * The span of dates a set of items covers.
	 *
	 * @param array<int, array<string, mixed>> $items Board items.
	 * @return array<string, mixed> Empty when nothing is dated.
	 */
	public static function axis( array $items ): array {
		$dates = array();

		foreach ( $items as $item ) {
			foreach ( self::SPAN as $field ) {
				$date = (string) ( $item[ $field ] ?? '' );

				if ( '' !== $date ) {
					$dates[] = $date;
				}
			}
		}

		if ( array() === $dates ) {
			return array();
		}

		$from = min( $dates );
		$to   = max( $dates );

		return array(
			'from' => $from,
			'to'   => $to,
			'days' => self::days_between( $from, $to ) + 1,
		);
	}

	/**
	 * Where one item's bar sits on an axis, as percentages.
	 *
	 * A start with no due date runs to its start, and a due date with no start
	 * runs from its due date: one day is honest about what is known, where
	 * stretching the bar to today would invent a commitment nobody made.
	 *
	 * @param array<string, mixed> $item Board item.
	 * @param array<string, mixed> $axis As axis() returned it.
	 * @return array<string, float> Empty when the item has no dates.
	 */
	public static function place( array $item, array $axis ): array {
		if ( array() === $axis ) {
			return array();
		}

		$start = (string) ( $item['planned_start'] ?? '' );
		$due   = (string) ( $item['planned_due'] ?? '' );

		if ( '' === $start && '' === $due ) {
			return array();
		}

		$start = '' === $start ? $due : $start;
		$due   = '' === $due ? $start : $due;

		$days   = max( 1, (int) $axis['days'] );
		$offset = self::days_between( (string) $axis['from'], $start );
		$length = max( 1, self::days_between( $start, $due ) + 1 );

		return array(
			'left'  => round( $offset / $days * 100, 4 ),
			'width' => round( $length / $days * 100, 4 ),
		);
	}

	/**
	 * The items with no dates at all, kept rather than dropped (#120).
	 *
	 * @param array<int, array<string, mixed>> $items Board items.
	 * @return array<int, array<string, mixed>>
	 */
	public static function undated( array $items ): array {
		return array_values(
			array_filter(
				$items,
				static function ( array $item ): bool {
					foreach ( array_keys( self::KINDS ) as $field ) {
						if ( '' !== (string) ( $item[ $field ] ?? '' ) ) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}

	/**
	 * The days of the calendar grid an anchor date falls in: whole weeks from
	 * Monday, covering the whole of that month.
	 *
	 * @param string $anchor Any date in the month, as YYYY-MM-DD.
	 * @return array<int, string>
	 */
	public static function month( string $anchor ): array {
		$first = gmdate( 'Y-m-01', self::stamp( $anchor ) );
		$last  = gmdate( 'Y-m-t', self::stamp( $anchor ) );

		$start = self::monday_of( $first );
		$days  = array();
		$day   = $start;

		while ( $day <= $last || 0 !== count( $days ) % 7 ) {
			$days[] = $day;
			$day    = gmdate( 'Y-m-d', self::stamp( $day ) + DAY_IN_SECONDS );
		}

		return $days;
	}

	/**
	 * Every date every item carries, gathered by the day it falls on.
	 *
	 * A day nothing happens on is absent rather than empty, so a caller reads
	 * "is there anything here" as the presence of a key.
	 *
	 * @param array<int, array<string, mixed>> $items Board items.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function entries( array $items ): array {
		$byday = array();

		foreach ( $items as $item ) {
			foreach ( self::KINDS as $field => $kind ) {
				$date = (string) ( $item[ $field ] ?? '' );

				if ( '' === $date ) {
					continue;
				}

				$byday[ $date ][] = array(
					'kind' => $kind,
					'item' => $item,
				);
			}
		}

		ksort( $byday );

		return $byday;
	}

	/**
	 * The Monday of the week a date falls in.
	 *
	 * @param string $date YYYY-MM-DD.
	 * @return string
	 */
	private static function monday_of( string $date ): string {
		$stamp = self::stamp( $date );
		$back  = (int) gmdate( 'N', $stamp ) - 1;

		return gmdate( 'Y-m-d', $stamp - ( $back * DAY_IN_SECONDS ) );
	}

	/**
	 * Whole days from one date to another.
	 *
	 * Both are read as UTC midnight rather than as local times, so a site whose
	 * timezone happens to be an hour off does not lose or gain a day.
	 *
	 * @param string $from YYYY-MM-DD.
	 * @param string $to   YYYY-MM-DD.
	 * @return int
	 */
	private static function days_between( string $from, string $to ): int {
		return (int) round( ( self::stamp( $to ) - self::stamp( $from ) ) / DAY_IN_SECONDS );
	}

	/**
	 * A date as a UTC midnight timestamp.
	 *
	 * @param string $date YYYY-MM-DD.
	 * @return int
	 */
	private static function stamp( string $date ): int {
		return (int) strtotime( $date . ' 00:00:00 UTC' );
	}
}
