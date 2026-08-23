<?php
/**
 * Reading a studio record without holding a canonical copy of it.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * The read-through rule (ARCH-2), once, for every record a client site shows.
 *
 * The order is fixed and the reason for each step matters:
 *
 * 1. Not connected yet — say so, and do not pretend a network problem.
 * 2. A copy younger than the acceptable staleness — serve it without touching
 *    the network, which is what keeps ordinary browsing quick.
 * 3. Otherwise ask the studio, and store what comes back.
 * 4. If that fails and there is an older copy — serve it, and say plainly that
 *    it is old and how old (ARCH-4).
 * 5. If that fails and there is nothing — say the studio is unreachable. Never
 *    an empty record, which reads as "you have nothing" rather than "we cannot
 *    see your things right now".
 *
 * This is shared rather than copied because the distinction in 4 and 5 is the
 * whole point of it, and it is the kind of distinction that survives being
 * written once and quietly rots in the second copy. Each record keeps its own
 * cache entry, keyed by its own route, so sharing the rule does not mean
 * sharing a lifetime: a fresh workspace can sit beside a board nobody has
 * fetched for an hour.
 */
final class ReadThrough {

	/**
	 * One record, as this site can currently see it.
	 *
	 * @param string $route The studio route to read.
	 * @param bool   $force True to ignore a still-fresh copy and ask the studio.
	 * @return array<string, mixed>
	 */
	public static function view( string $route, bool $force = false ): array {
		if ( ! Connection::is_configured() ) {
			return self::result( null, Sync::STATE_NOT_CONFIGURED, 0 );
		}

		$cached = Cache::get( $route );

		if ( ! $force && null !== $cached && ! Cache::is_expired( $cached ) ) {
			// A copy inside the window is served without touching the network —
			// unless the last attempt to refresh it failed, in which case it is
			// still served, but as what it is. Not retrying immediately is
			// deliberate: a studio that is down does not want a request from
			// every page view on every client site.
			return array() === $cached['failed']
				? self::result( $cached['payload'], Sync::STATE_CACHED, $cached['fetched_at'] )
				: self::result( $cached['payload'], Sync::STATE_STALE, $cached['fetched_at'], self::failure_of( $cached['failed'] ) );
		}

		$fresh = Connection::get( $route );

		if ( ! is_wp_error( $fresh ) ) {
			Cache::put( $route, $fresh );

			return self::result( $fresh, Sync::STATE_LIVE, bwx_forge_client_now() );
		}

		$data   = $fresh->get_error_data();
		$reason = array(
			'reason' => $fresh->get_error_code(),
			'status' => (int) ( $data['status'] ?? 0 ),
		);

		Cache::fail( $route, $reason );

		if ( null !== $cached ) {
			return self::result( $cached['payload'], Sync::STATE_STALE, $cached['fetched_at'], $reason );
		}

		return self::result( null, Sync::STATE_UNREACHABLE, 0, $reason );
	}

	/**
	 * The reason and status out of a recorded failure.
	 *
	 * @param array<string, mixed> $failed Stored failure.
	 * @return array<string, mixed>
	 */
	private static function failure_of( array $failed ): array {
		return array(
			'reason' => (string) ( $failed['reason'] ?? '' ),
			'status' => (int) ( $failed['status'] ?? 0 ),
		);
	}

	/**
	 * Builds the answer.
	 *
	 * @param array<string, mixed>|null $payload    The studio answer, cached or fresh.
	 * @param string                    $state      One of the Sync STATE_ constants.
	 * @param int                       $fetched_at When the payload was read from the studio.
	 * @param array<string, mixed>      $failure    Why the last attempt failed, if it did.
	 * @return array<string, mixed>
	 */
	private static function result( ?array $payload, string $state, int $fetched_at, array $failure = array() ): array {
		return array(
			'payload' => $payload,
			'sync'    => array_merge(
				array(
					'state'      => $state,
					'stale'      => Sync::is_stale( $state ),
					'fetched_at' => $fetched_at,
					'age'        => $fetched_at > 0 ? max( 0, bwx_forge_client_now() - $fetched_at ) : 0,
					'max_age'    => Cache::MAX_AGE,
					'checked_at' => bwx_forge_client_now(),
					'reason'     => '',
					'status'     => 0,
				),
				$failure
			),
		);
	}
}
