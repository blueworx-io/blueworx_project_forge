<?php
/**
 * What a client is told about whether there is room.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Capacity;

use Blueworx\Forge\Tenancy\Users;

/**
 * The privacy-safe availability result (#140).
 *
 * A client asking "have you got room in September" is asking a fair question
 * with a dangerous answer. Remaining hours move week to week for reasons that
 * are entirely about other clients, so a number tells them what everybody else
 * is doing by inference — slowly, but permanently, and there is no taking it
 * back once they have watched it for a month.
 *
 * So the answer is a band and a date. Enough to plan around, and nothing to
 * reverse-engineer.
 *
 * **The answer does not depend on which client is asking.** That is deliberate,
 * and it is the strongest privacy property here: two clients asking the same
 * question on the same day get the same sentence, so nothing in the answer can
 * be about either of them. It is also why nothing in this file takes a client
 * id.
 *
 * Written as an explicit construction rather than as a filtered position, the
 * same direction Work\ClientView is written in. A field added to Position
 * cannot appear here by accident; somebody has to name it, in a diff.
 */
final class ClientAnswer {

	/**
	 * There is room to take something on.
	 */
	public const ROOM = 'room';

	/**
	 * There is some, but not much.
	 */
	public const TIGHT = 'tight';

	/**
	 * There is none in the period asked about.
	 */
	public const NONE = 'none';

	/**
	 * How far ahead the answer looks when the caller does not say.
	 */
	public const DEFAULT_DAYS = 55;

	/**
	 * The studio's position over a window, as one word.
	 *
	 * The aggregate, not anybody's. One person being free does not mean there
	 * is room — the work needs whoever can do it — and one person being buried
	 * does not mean there is none, or a studio with three people idle would
	 * report itself closed.
	 *
	 * @param array<string, array<string, mixed>> $positions Position::calculate results.
	 * @return string
	 */
	public static function band( array $positions ): string {
		$available = 0.0;
		$committed = 0.0;
		$recorded  = false;

		foreach ( $positions as $position ) {
			if ( Position::UNRECORDED === (string) ( $position['band'] ?? '' ) ) {
				/*
				 * Somebody nobody has set up contributes nothing either way.
				 * Counting their zero hours as zero capacity would make the
				 * studio look full; counting them as free would promise time
				 * nobody has said exists.
				 */
				continue;
			}

			$recorded   = true;
			$available += (float) ( $position['available'] ?? 0 );
			$committed += (float) ( $position['committed'] ?? 0 );
		}

		if ( ! $recorded ) {
			/*
			 * Nothing is known about anybody's hours, so there is nothing to
			 * promise. "None" is the honest answer to a question the studio
			 * cannot yet answer, and it fails towards a conversation rather
			 * than towards a commitment.
			 */
			return self::NONE;
		}

		$aggregate = Position::calculate( $available, $committed, true );

		if ( Position::OVER === $aggregate['band'] ) {
			return self::NONE;
		}

		return Position::TIGHT === $aggregate['band'] ? self::TIGHT : self::ROOM;
	}

	/**
	 * The first week in a window with room in it.
	 *
	 * @param array<int, array<string, mixed>> $weeks Each with `from` and `band`.
	 * @return string YYYY-MM-DD, or empty when there is none in the window.
	 */
	public static function earliest( array $weeks ): string {
		foreach ( $weeks as $week ) {
			if ( self::ROOM === (string) ( $week['band'] ?? '' ) ) {
				return (string) ( $week['from'] ?? '' );
			}
		}

		// Nothing invented beyond the window. A date the studio has not looked
		// at is a promise it has not made.
		return '';
	}

	/**
	 * The answer, and only the answer.
	 *
	 * Four keys, listed here and nowhere else, so that what a client site
	 * receives is a decision in this file rather than a consequence of what
	 * some other file happens to return.
	 *
	 * @param string $availability One of the three bands.
	 * @param string $earliest     YYYY-MM-DD, or empty.
	 * @param string $from         Window start, as asked for.
	 * @param string $to           Window end, as asked for.
	 * @return array<string, string>
	 */
	public static function compose( string $availability, string $earliest, string $from, string $to ): array {
		return array(
			'availability' => $availability,
			'earliest'     => $earliest,
			'from'         => $from,
			'to'           => $to,
		);
	}

	/**
	 * The studio's answer for a window.
	 *
	 * @param string $from YYYY-MM-DD, inclusive.
	 * @param string $to   YYYY-MM-DD, inclusive.
	 * @return array<string, string>
	 */
	public static function for_window( string $from, string $to ): array {
		$ids = array_map(
			static fn( array $person ): string => (string) $person['id'],
			Users::all( 'active' )
		);

		$periods = Periods::weeks( $from, $to );
		$grid    = Position::grid( $ids, $periods, $from, $to );
		$weeks   = array();

		foreach ( $periods as $index => $period ) {
			$column = array();

			foreach ( $ids as $user_id ) {
				$column[ $user_id ] = $grid[ $user_id ]['weeks'][ $index ];
			}

			$weeks[] = array(
				'from' => $period['from'],
				'band' => self::band( $column ),
			);
		}

		$totals = array();

		foreach ( $ids as $user_id ) {
			$totals[ $user_id ] = $grid[ $user_id ]['total'];
		}

		return self::compose( self::band( $totals ), self::earliest( $weeks ), $from, $to );
	}
}
