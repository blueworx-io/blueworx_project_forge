<?php
/**
 * How far through the signals one person has got.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Signals;

/**
 * #175. One timestamp per person, and nothing else.
 *
 * The obvious design is a row per person per signal, marked read. It is also
 * the design that turns a derived list into a stored one: every signal would
 * have to be fanned out to everybody entitled to it at the moment it happened,
 * which fixes that entitlement for ever — somebody who joins a client next week
 * would never see what happened this week, and somebody who leaves would keep
 * seeing it. Both are wrong, and neither is visible until it bites.
 *
 * So instead: the list is worked out fresh from records the reader may see
 * right now, and the only thing kept is how far they had got. "What has
 * happened since I last looked" is the only question anybody asks of a list
 * like this, and a single number answers it.
 *
 * It lives in user meta rather than in a table of our own because it is
 * genuinely a preference of a WordPress user, it has no tenancy, and nothing
 * ever reports on it.
 */
final class Seen {

	/**
	 * The user meta key.
	 */
	public const META = 'bwx_forge_signals_seen_at';

	/**
	 * When this person last looked.
	 *
	 * Zero for somebody who never has, which is right: their first visit shows
	 * everything in the window as new, and that is the honest answer.
	 *
	 * @param int $user_id WordPress user id.
	 * @return int Unix time.
	 */
	public static function at( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		return (int) get_user_meta( $user_id, self::META, true );
	}

	/**
	 * Records that this person has looked, as of a moment.
	 *
	 * The moment is passed in rather than taken here, and it matters: it has to
	 * be the moment the list they read was worked out, not the moment they
	 * clicked. Anything that happened while they were reading is still new to
	 * them, and taking the clock here would quietly mark it seen.
	 *
	 * Never moves backwards. Two tabs open, the older one marked second, and a
	 * naive write would resurrect signals the person has already dealt with.
	 *
	 * @param int $user_id WordPress user id.
	 * @param int $at      Unix time the list was worked out.
	 * @return int Where they now are.
	 */
	public static function mark( int $user_id, int $at ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		$now = max( self::at( $user_id ), $at );

		update_user_meta( $user_id, self::META, $now );

		return $now;
	}
}
