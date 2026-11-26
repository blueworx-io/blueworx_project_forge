<?php
/**
 * Reads shared by every card on one list.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

/**
 * #702. The board, all work and the standup each work out, card by card,
 * whether the work is ready to move — and for work at Up Next and in design
 * that means a capacity check and a support-hours check. Each of those reads
 * the same rows for every card: every booking over the same days, the same
 * site's balance. This keeps each read for the rest of one list request, so
 * forty cards on one site read its balance once rather than forty times.
 *
 * Off unless a list turns it on, and only lists that read and never write do:
 * a move reads, writes and may read again, and must never be handed what it
 * read before it wrote.
 */
final class ListMemo {

	/**
	 * Whether this request is drawing a list.
	 *
	 * @var bool
	 */
	private static $on = false;

	/**
	 * What has been read, by key.
	 *
	 * @var array<string, mixed>
	 */
	private static $kept = array();

	/**
	 * Starts keeping reads, for the rest of this request.
	 */
	public static function start(): void {
		self::$on   = true;
		self::$kept = array();
	}

	/**
	 * Stops keeping reads and forgets them.
	 */
	public static function stop(): void {
		self::$on   = false;
		self::$kept = array();
	}

	/**
	 * Whether a list is being drawn, so a read may be made for every card at
	 * once instead of one card at a time (#712).
	 *
	 * @return bool
	 */
	public static function is_on(): bool {
		return self::$on;
	}

	/**
	 * A read, once per list.
	 *
	 * @param string   $key  What is being read, and for what.
	 * @param callable $read Reads it.
	 * @return mixed
	 */
	public static function remember( string $key, callable $read ) {
		if ( ! self::$on ) {
			return $read();
		}

		if ( ! array_key_exists( $key, self::$kept ) ) {
			self::$kept[ $key ] = $read();
		}

		return self::$kept[ $key ];
	}
}
