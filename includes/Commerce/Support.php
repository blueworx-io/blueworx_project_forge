<?php
/**
 * What a site is entitled to, on any given day.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Commerce;

/**
 * #146, COMM-1. A site's commercial position over time.
 *
 * Pure. It is handed the dated periods a site has had and answers what was true
 * on a date — which is the acceptance criterion word for word: *a client's
 * entitlement on any past date can be reconstructed from the record.*
 *
 * **Four states, and the difference between three of them matters commercially.**
 * A site with no package has never bought one and is a sales conversation. A
 * lapsed site bought one and let it run out, and COMM-4 says its remaining
 * balance is frozen rather than voided — it is a renewal conversation, and
 * treating it as a new sale loses hours the client paid for. A suspended site
 * is one we stopped deliberately. Only an active site may draw down hours.
 *
 * **Reconstruction is a lookup, not a calculation.** Every change of position
 * closes one period and opens another, so "what was true in March" is whichever
 * period covers March. Nothing here replays a history or infers what must have
 * happened; it reads what did.
 */
final class Support {

	/**
	 * Never had a package.
	 */
	public const NONE = 'none';

	/**
	 * Has one, running now.
	 */
	public const ACTIVE = 'active';

	/**
	 * Stopped deliberately, and can be resumed.
	 */
	public const SUSPENDED = 'suspended';

	/**
	 * Had one and it ran out. The balance is frozen, not voided (COMM-4).
	 */
	public const LAPSED = 'lapsed';

	/**
	 * Agreed, and starting on a date that has not arrived.
	 */
	public const SCHEDULED = 'scheduled';

	/**
	 * Every state a site can be in.
	 *
	 * @var array<int, string>
	 */
	public const STATES = array(
		self::NONE,
		self::SCHEDULED,
		self::ACTIVE,
		self::SUSPENDED,
		self::LAPSED,
	);

	/**
	 * The states a period row can itself be stored in.
	 *
	 * `none` and `lapsed` are never stored: they are what the absence of a
	 * covering period means, and a row saying "this client has nothing" would
	 * be a row somebody has to remember to write.
	 *
	 * @var array<int, string>
	 */
	public const PERIOD_STATES = array( self::SCHEDULED, self::ACTIVE, self::SUSPENDED );

	/* -------------------------------------------------- why a period began */

	public const ASSIGNED = 'assigned';
	public const RENEWED  = 'renewed';
	public const REPLACED = 'replaced';
	public const RESUMED  = 'resumed';
	public const STARTED  = 'started';

	/* ---------------------------------------------------- and why it ended */

	public const CANCELLED  = 'cancelled';
	public const LAPSE      = 'lapsed';
	public const SUSPENDING = 'suspended';

	/**
	 * Every reason a period can begin.
	 *
	 * @var array<int, string>
	 */
	public const BEGINNINGS = array(
		self::ASSIGNED,
		self::RENEWED,
		self::REPLACED,
		self::RESUMED,
		self::STARTED,
	);

	/**
	 * Every reason a period can end.
	 *
	 * @var array<int, string>
	 */
	public const ENDINGS = array(
		self::RENEWED,
		self::REPLACED,
		self::CANCELLED,
		self::LAPSE,
		self::SUSPENDING,
	);

	/**
	 * Whether a period covers a date.
	 *
	 * An empty end date means open: running, with no end set. Both ends are
	 * inclusive, because a period is the days a client has cover on and the
	 * last day is one of them.
	 *
	 * @param array<string, mixed> $period The period.
	 * @param string               $date   YYYY-MM-DD.
	 * @return bool
	 */
	public static function covers( array $period, string $date ): bool {
		$from = (string) ( $period['starts_on'] ?? '' );
		$to   = (string) ( $period['ends_on'] ?? '' );

		if ( '' === $from || '' === $date || $date < $from ) {
			return false;
		}

		return '' === $to || $date <= $to;
	}

	/**
	 * The period covering a date, if there is one.
	 *
	 * The latest of them where two somehow overlap. Overlapping periods are a
	 * bug rather than a state anybody means, and answering with the newer is
	 * the reading that matches what somebody was trying to do.
	 *
	 * @param array<int, array<string, mixed>> $periods The site's periods.
	 * @param string                           $date    YYYY-MM-DD.
	 * @return array<string, mixed> Empty when nothing covers it.
	 */
	public static function period_on( array $periods, string $date ): array {
		$found = array();

		foreach ( $periods as $period ) {
			if ( ! self::covers( $period, $date ) ) {
				continue;
			}

			if ( array() === $found || (string) $period['starts_on'] >= (string) $found['starts_on'] ) {
				$found = $period;
			}
		}

		return $found;
	}

	/**
	 * What a site's position was on a date.
	 *
	 * @param array<int, array<string, mixed>> $periods The site's periods.
	 * @param string                           $date    YYYY-MM-DD.
	 * @return string One of {@see self::STATES}.
	 */
	public static function state_on( array $periods, string $date ): string {
		$period = self::period_on( $periods, $date );

		if ( array() !== $period ) {
			$state = (string) $period['state'];

			/*
			 * A scheduled period that the date has reached is active. The row is
			 * not rewritten when its start date arrives — nothing runs at
			 * midnight to do it, and a state that depends on a cron job having
			 * fired is a state that is wrong whenever the cron job did not.
			 */
			return self::SCHEDULED === $state && $date >= (string) $period['starts_on'] ? self::ACTIVE : $state;
		}

		/*
		 * Nothing covers the date, so the question is whether anything ever
		 * did. A site whose package ran out is lapsed and its balance is frozen
		 * pending renewal (COMM-4); a site that never had one is a sales
		 * conversation. Treating the first as the second loses hours somebody
		 * paid for.
		 */
		foreach ( $periods as $period ) {
			if ( '' !== (string) ( $period['starts_on'] ?? '' ) && (string) $period['starts_on'] <= $date ) {
				return self::LAPSED;
			}
		}

		// Everything this site has is in the future, or it has nothing at all.
		return self::has_future( $periods, $date ) ? self::SCHEDULED : self::NONE;
	}

	/**
	 * Everything about a site's position on a date, in one answer.
	 *
	 * @param array<int, array<string, mixed>> $periods The site's periods.
	 * @param string                           $date    YYYY-MM-DD.
	 * @return array<string, mixed>
	 */
	public static function entitlement_on( array $periods, string $date ): array {
		$period = self::period_on( $periods, $date );
		$state  = self::state_on( $periods, $date );

		return array(
			'on'                 => $date,
			'state'              => $state,

			// Only an active site may draw hours down. Said here rather than
			// left for each caller to work out from the state, because there
			// are four states and every caller getting it right is four
			// chances to get it wrong.
			'may_use_hours'      => self::ACTIVE === $state,
			'period_id'          => (string) ( $period['id'] ?? '' ),
			'package_version_id' => (string) ( $period['package_version_id'] ?? '' ),
			'hours_granted'      => (float) ( $period['hours_granted'] ?? 0 ),
			'starts_on'          => (string) ( $period['starts_on'] ?? '' ),
			'ends_on'            => (string) ( $period['ends_on'] ?? '' ),
			'term_ends_on'       => (string) ( $period['term_ends_on'] ?? '' ),
		);
	}

	/**
	 * How a state reads to a person.
	 *
	 * @param string $state One of {@see self::STATES}.
	 * @return string
	 */
	public static function label( string $state ): string {
		switch ( $state ) {
			case self::NONE:
				return __( 'No support package', 'blueworx-forge' );
			case self::SCHEDULED:
				return __( 'Starts later', 'blueworx-forge' );
			case self::ACTIVE:
				return __( 'On support', 'blueworx-forge' );
			case self::SUSPENDED:
				return __( 'Suspended', 'blueworx-forge' );
			case self::LAPSED:
				return __( 'Lapsed — hours frozen, pending renewal', 'blueworx-forge' );
			default:
				return __( 'Unknown', 'blueworx-forge' );
		}
	}

	/**
	 * Whether any period starts after a date.
	 *
	 * @param array<int, array<string, mixed>> $periods The site's periods.
	 * @param string                           $date    YYYY-MM-DD.
	 * @return bool
	 */
	private static function has_future( array $periods, string $date ): bool {
		foreach ( $periods as $period ) {
			if ( (string) ( $period['starts_on'] ?? '' ) > $date ) {
				return true;
			}
		}

		return false;
	}
}
