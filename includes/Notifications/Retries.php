<?php
/**
 * How long to wait before trying an email again.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Notifications;

/**
 * #174, NOTIF-3. Three retries at 5, 30 and 120 minutes, then a person.
 *
 * A file of its own, and pure, because the schedule is a decision rather than a
 * mechanism. The numbers were chosen and can be argued with; where they are
 * applied cannot be, and mixing the two would mean changing the ladder meant
 * editing the code that talks to the database.
 *
 * **The ladder ends.** That is the part worth defending. Retrying for ever is
 * the tempting option — nothing is ever lost, nothing ever needs a person — and
 * it is how a client goes a fortnight without hearing from us while a queue
 * quietly churns. After the third attempt the failure stops being the
 * product's problem and becomes somebody's, which is what "appears in Standup"
 * means and why the last rung is a person rather than a longer wait.
 *
 * The gaps widen because the two things that go wrong want different waits. A
 * mail server refusing a connection for a moment is fixed in five minutes; a
 * misconfigured site is not fixed in five minutes, and hammering it turns one
 * problem into a reputation problem with the client's own mail provider.
 */
final class Retries {

	/**
	 * How long after each failed attempt to try again, in seconds.
	 *
	 * Three entries, so a first failure waits five minutes, a second thirty,
	 * and a third two hours. A fourth failure has nowhere on this list to go,
	 * which is what ends the ladder.
	 *
	 * @var array<int, int>
	 */
	public const LADDER = array( 300, 1800, 7200 );

	/**
	 * How many attempts there are altogether: the first, and three retries.
	 */
	public const LIMIT = 4;

	/**
	 * Whether a failure at this attempt is worth trying again.
	 *
	 * @param int $attempts How many attempts have now been made.
	 * @return bool
	 */
	public static function again_after( int $attempts ): bool {
		return $attempts >= 1 && $attempts < self::LIMIT;
	}

	/**
	 * When the next attempt is due.
	 *
	 * @param int $attempts How many attempts have now been made.
	 * @param int $now      The current time.
	 * @return int Unix time, or 0 where there is no next attempt.
	 */
	public static function due_at( int $attempts, int $now ): int {
		if ( ! self::again_after( $attempts ) ) {
			return 0;
		}

		return $now + self::LADDER[ $attempts - 1 ];
	}

	/**
	 * What a failed attempt leaves the event in.
	 *
	 * Retrying and failed are deliberately different states rather than one
	 * with a counter. Standup reports the failures (#169), and an event that is
	 * going to be tried again in five minutes is not something anybody should
	 * be asked to look at — putting both under one name would mean the daily
	 * list filled up with problems that fix themselves.
	 *
	 * @param int $attempts How many attempts have now been made.
	 * @return string One of Register's outcomes.
	 */
	public static function outcome_after( int $attempts ): string {
		return self::again_after( $attempts ) ? Register::RETRYING : Register::FAILED;
	}
}
