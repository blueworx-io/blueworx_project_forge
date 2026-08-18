<?php
/**
 * Reading the studio's canonical workspace record.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * The read-through layer (ARCH-2): the client site renders a studio record and
 * holds no canonical copy of it.
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
 *    an empty workspace, which reads as "you have nothing" rather than "we
 *    cannot see your things right now".
 *
 * The distinction in 4 and 5 is the whole point of the issue: an honest state,
 * not a blank screen and not a silently old one.
 */
final class Workspace {

	/**
	 * The studio route this reads.
	 */
	public const ROUTE = '/client/workspace';

	/**
	 * Never connected to the studio.
	 */
	public const STATE_NOT_CONFIGURED = 'not_configured';

	/**
	 * Read from the studio just now.
	 */
	public const STATE_LIVE = 'live';

	/**
	 * Served from a copy still within the acceptable staleness window.
	 */
	public const STATE_CACHED = 'cached';

	/**
	 * Served from a copy that is past the window because the studio could not be
	 * reached to refresh it.
	 */
	public const STATE_STALE = 'stale';

	/**
	 * The studio could not be reached and there is nothing cached to fall back on.
	 */
	public const STATE_UNREACHABLE = 'unreachable';

	/**
	 * The workspace as this site can currently see it.
	 *
	 * @param bool $force True to ignore a still-fresh copy and ask the studio.
	 * @return array<string, mixed>
	 */
	public static function view( bool $force = false ): array {
		if ( ! Connection::is_configured() ) {
			return self::result( null, self::STATE_NOT_CONFIGURED, 0 );
		}

		$cached = Cache::get( self::ROUTE );

		if ( ! $force && null !== $cached && ! Cache::is_expired( $cached ) ) {
			// A copy inside the window is served without touching the network —
			// unless the last attempt to refresh it failed, in which case it is
			// still served, but as what it is. Not retrying immediately is
			// deliberate: a studio that is down does not want a request from every
			// page view on every client site.
			return array() === $cached['failed']
				? self::result( $cached['payload'], self::STATE_CACHED, $cached['fetched_at'] )
				: self::result( $cached['payload'], self::STATE_STALE, $cached['fetched_at'], self::failure_of( $cached['failed'] ) );
		}

		$fresh = Connection::get( self::ROUTE );

		if ( ! is_wp_error( $fresh ) ) {
			Cache::put( self::ROUTE, $fresh );

			return self::result( $fresh, self::STATE_LIVE, bwx_forge_client_now() );
		}

		$data   = $fresh->get_error_data();
		$reason = array(
			'reason' => $fresh->get_error_code(),
			'status' => (int) ( $data['status'] ?? 0 ),
		);

		Cache::fail( self::ROUTE, $reason );

		if ( null !== $cached ) {
			return self::result( $cached['payload'], self::STATE_STALE, $cached['fetched_at'], $reason );
		}

		return self::result( null, self::STATE_UNREACHABLE, 0, $reason );
	}

	/**
	 * Whether a state means what is on screen may be out of date.
	 *
	 * @param string $state One of the STATE_ constants.
	 * @return bool
	 */
	public static function is_stale( string $state ): bool {
		return self::STATE_STALE === $state || self::STATE_UNREACHABLE === $state;
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
	 * @param array<string, mixed>|null $payload    The studio's answer, cached or fresh.
	 * @param string                    $state      One of the STATE_ constants.
	 * @param int                       $fetched_at When the payload was read from the studio.
	 * @param array<string, mixed>      $failure    Why the last attempt failed, if it did.
	 * @return array<string, mixed>
	 */
	private static function result( ?array $payload, string $state, int $fetched_at, array $failure = array() ): array {
		$record = is_array( $payload['record'] ?? null ) ? $payload['record'] : null;

		return array(
			'ok'     => null !== $record,
			'record' => $record,
			'sync'   => array_merge(
				array(
					'state'      => $state,
					'stale'      => self::is_stale( $state ),
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
