<?php
/**
 * Which clients need a commercial conversation.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Commerce;

/**
 * #157, COMM-1 and COMM-4. Pure, and the whole of the cross-client sales view's
 * judgement.
 *
 * **Nobody should have to remember to look.** A client whose package runs out
 * next month, or who has four hours left of forty, is a conversation that has
 * to happen *before* the next thing they ask for is refused. Every one of those
 * facts is already in the records; left to somebody checking sites one at a
 * time, it gets checked when they think of it, which is usually the day after
 * the client was told no.
 *
 * **Four conversations, not one flag.** A site with no package is a sale. A
 * lapsed one is a renewal, and its hours are frozen rather than gone (COMM-4) —
 * so it is a client waiting to use something they have already paid for, which
 * is a different phone call. One running low needs a top-up. One expiring soon
 * needs a note in the diary. Collapsing them into "needs attention" puts four
 * different jobs on one list with no way to tell them apart, and a list like
 * that gets ignored.
 */
final class Attention {

	/**
	 * Never bought anything. A sale.
	 */
	public const NO_PACKAGE = 'no-package';

	/**
	 * Bought one and let it run out. A renewal, with hours still frozen.
	 */
	public const LAPSED = 'lapsed';

	/**
	 * Stopped deliberately, and somebody should decide whether it stays stopped.
	 */
	public const SUSPENDED = 'suspended';

	/**
	 * Running out of hours.
	 */
	public const LOW_HOURS = 'low-hours';

	/**
	 * Running out of term.
	 */
	public const EXPIRING = 'expiring';

	/**
	 * Every reason a site can be on the list.
	 *
	 * @var array<int, string>
	 */
	public const REASONS = array(
		self::NO_PACKAGE,
		self::LAPSED,
		self::SUSPENDED,
		self::LOW_HOURS,
		self::EXPIRING,
	);

	/**
	 * What counts as running low: less than a fifth of what was bought.
	 *
	 * A share rather than a number, because four hours left of a ten-hour
	 * package is comfortable and four left of a hundred-hour one is not.
	 */
	public const LOW_SHARE = 0.2;

	/**
	 * How near the end of a term counts as expiring soon, in days.
	 */
	public const EXPIRING_DAYS = 30;

	/**
	 * Why a site is on the list, or empty when it is not.
	 *
	 * @param array<string, mixed> $position From {@see Support::entitlement_on()}.
	 * @param float                $balance  What the site has left.
	 * @param string               $today    YYYY-MM-DD.
	 * @return array<int, string>
	 */
	public static function of( array $position, float $balance, string $today ): array {
		$state = (string) ( $position['state'] ?? Support::NONE );

		/*
		 * A client with nothing is one conversation and only one. "Running low"
		 * and "expiring soon" are not facts about them — they are what you get
		 * from dividing by nought and reading an empty date, and a list saying a
		 * client with no package is also low on hours is a list nobody trusts.
		 */
		if ( Support::NONE === $state ) {
			return array( self::NO_PACKAGE );
		}

		$found = array();

		if ( Support::LAPSED === $state ) {
			$found[] = self::LAPSED;
		}

		if ( Support::SUSPENDED === $state ) {
			$found[] = self::SUSPENDED;
		}

		if ( self::low( $position, $balance ) ) {
			$found[] = self::LOW_HOURS;
		}

		if ( self::expiring( $position, $today ) ) {
			$found[] = self::EXPIRING;
		}

		return $found;
	}

	/**
	 * Whether a site wants anybody's attention at all.
	 *
	 * @param array<string, mixed> $position From {@see Support::entitlement_on()}.
	 * @param float                $balance  What the site has left.
	 * @param string               $today    YYYY-MM-DD.
	 * @return bool
	 */
	public static function wanted( array $position, float $balance, string $today ): bool {
		return array() !== self::of( $position, $balance, $today );
	}

	/**
	 * How a reason reads to a person.
	 *
	 * The list is a to-do list for a human being, so every row says what the
	 * job is rather than naming a constant.
	 *
	 * @param string $reason One of {@see self::REASONS}.
	 * @return string '' when it is not one.
	 */
	public static function label( string $reason ): string {
		switch ( $reason ) {
			case self::NO_PACKAGE:
				return __( 'Has never been on a package', 'blueworx-forge' );
			case self::LAPSED:
				return __( 'Package ran out — hours are frozen, waiting on a renewal', 'blueworx-forge' );
			case self::SUSPENDED:
				return __( 'Suspended — decide whether it stays that way', 'blueworx-forge' );
			case self::LOW_HOURS:
				return __( 'Running out of hours', 'blueworx-forge' );
			case self::EXPIRING:
				return __( 'Term ends within the month', 'blueworx-forge' );
			default:
				return '';
		}
	}

	/**
	 * Whether a site is running out of hours.
	 *
	 * @param array<string, mixed> $position The site's position.
	 * @param float                $balance  What it has left.
	 * @return bool
	 */
	private static function low( array $position, float $balance ): bool {
		$granted = round( (float) ( $position['hours_granted'] ?? 0 ), 2 );

		if ( $granted <= 0 ) {
			return false;
		}

		/*
		 * Below nought counts. COMM-3 lets a Primary administrator take a
		 * balance negative with a reason, and a comparison that only caught
		 * "less than a fifth" would drop exactly the client who most needs the
		 * conversation.
		 */
		return $balance < round( $granted * self::LOW_SHARE, 2 );
	}

	/**
	 * Whether a site's term ends soon.
	 *
	 * @param array<string, mixed> $position The site's position.
	 * @param string               $today    YYYY-MM-DD.
	 * @return bool
	 */
	private static function expiring( array $position, string $today ): bool {
		$ends = (string) ( $position['term_ends_on'] ?? '' );

		// An open period is running with no end set, which is not the same as
		// one ending today. Reading it as imminent would put every such client
		// on a renewal list for ever.
		if ( '' === $ends || '' === $today ) {
			return false;
		}

		$limit = gmdate( 'Y-m-d', (int) strtotime( $today . ' 00:00:00 UTC' ) + ( self::EXPIRING_DAYS * DAY_IN_SECONDS ) );

		return $ends <= $limit;
	}
}
