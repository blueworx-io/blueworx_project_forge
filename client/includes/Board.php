<?php
/**
 * The work this client can see, as the studio last told us.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * The board behind the client three views (#128).
 *
 * A read, and only a read. There is no method here that changes anything,
 * because there is nothing on a client site a client may change about work:
 * every transition is refused server-side, the permission matrix leaves that
 * whole block "no" for every client role, and this artifact holds no route that
 * could ask for one.
 *
 * The stage list arrives with the items rather than being declared here. The
 * columns a board draws are the studio state machine, and a second copy of that
 * list on the client is a copy that is wrong the day a stage is added.
 *
 * An unreachable studio produces no items and says so. That distinction is why
 * this does not simply default to an empty list: an empty board tells a client
 * their work is gone, which is a worse lie than an error.
 */
final class Board {

	/**
	 * The studio route this reads.
	 */
	public const ROUTE = '/client/board';

	/**
	 * The board as this site can currently see it.
	 *
	 * @param bool $force True to ignore a still-fresh copy and ask the studio.
	 * @return array<string, mixed>
	 */
	public static function view( bool $force = false ): array {
		$read    = ReadThrough::view( self::ROUTE, $force );
		$payload = $read['payload'];

		return array(
			'ok'     => null !== $payload,
			'items'  => is_array( $payload['items'] ?? null ) ? array_values( $payload['items'] ) : array(),
			'stages' => is_array( $payload['stages'] ?? null ) ? array_values( $payload['stages'] ) : array(),
			'sync'   => $read['sync'],
		);
	}
}
