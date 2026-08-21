<?php
/**
 * Record ids.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

/**
 * One id shape for every canonical record: a short prefix saying what the
 * record is, then the moment it was made, then randomness.
 *
 * The prefix is there so an id in a log line or a support message says what it
 * belongs to without a lookup.
 *
 * The time comes first because ids settle ties. Every history in this plugin is
 * read oldest first and ordered by a timestamp held in whole seconds, with the
 * id breaking the tie — so several events written in the same second are put in
 * order by their ids alone. A purely random id made that order random, and one
 * edit touching four fields writes four events in the same second, so a history
 * genuinely came back shuffled.
 *
 * It is still not a sequence anybody can walk. Sites\Registry gives the reason
 * ids are not counters: an id appears in URLs and logs, and a sequential one
 * advertises how many records exist and lets a caller guess the next. Neither
 * is true here — the tail stays random, so knowing one id gives you no other,
 * and nothing in an id says how many came before it. What an id now admits is
 * when its record was made, which anything holding the record can already see.
 */
final class Ids {

	/**
	 * Microseconds, as hex. Fourteen digits carry them past the year 4000; the
	 * width is fixed so ids compare as plain strings.
	 */
	private const TIME_DIGITS = 14;

	/**
	 * The last microsecond an id was stamped with, so that two ids asked for
	 * inside the same microsecond still come out in the order they were asked
	 * for rather than tying.
	 *
	 * @var int
	 */
	private static $last = 0;

	/**
	 * A new id under a prefix.
	 *
	 * @param string $prefix Short record-type prefix, e.g. 'cli'.
	 * @return string
	 */
	public static function create( string $prefix ): string {
		return $prefix . '_' . self::stamp() . bin2hex( random_bytes( 6 ) );
	}

	/**
	 * The current microsecond, never repeating and never going backwards.
	 *
	 * A clock that steps back — an NTP correction, a host resuming — would
	 * otherwise hand out an id that sorts before one already stored.
	 *
	 * @return string
	 */
	private static function stamp(): string {
		$now = (int) ( microtime( true ) * 1000000 );

		if ( $now <= self::$last ) {
			$now = self::$last + 1;
		}

		self::$last = $now;

		return str_pad( dechex( $now ), self::TIME_DIGITS, '0', STR_PAD_LEFT );
	}
}
