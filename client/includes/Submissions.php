<?php
/**
 * What this site has asked for, and what became of it.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * The read half of the client's intake record (#130).
 *
 * Its sibling, Submission, sends one and deliberately stands outside the
 * read-through rule: a request that will be sent later is a request the client
 * believes was sent now, so there is no honest way to queue one. This half is
 * the opposite case and takes the ordinary rule without an exception. A list of
 * things somebody asked for weeks ago is exactly the kind of record that is
 * better shown a minute old than not shown at all.
 *
 * The one distinction it must never lose is between "you have asked for
 * nothing" and "we cannot see what you asked for". A screen that answers both
 * with an empty list tells a client their request vanished, which is a worse
 * thing to say than that the connection is down. That is why `ok` here means
 * the studio answered, not that the list has anything in it.
 *
 * Nothing here changes anything. A submission's state, its response and the
 * work it became are all the studio's writes; this artifact holds no route that
 * could ask for one of them to change, and there is no state on this screen a
 * client can put a submission into.
 */
final class Submissions {

	/**
	 * The studio route this reads.
	 *
	 * The same route Submission posts to, because they are the same collection
	 * seen from two directions.
	 */
	public const ROUTE = '/client/submissions';

	/**
	 * Everything this site has asked for, as it can currently see it.
	 *
	 * @param bool $force True to ignore a still-fresh copy and ask the studio.
	 * @return array<string, mixed>
	 */
	public static function view( bool $force = false ): array {
		$read    = ReadThrough::view( self::ROUTE, $force );
		$payload = $read['payload'];

		return array(
			'ok'          => null !== $payload,
			'submissions' => is_array( $payload['submissions'] ?? null ) ? array_values( $payload['submissions'] ) : array(),
			'states'      => is_array( $payload['states'] ?? null ) ? array_values( $payload['states'] ) : array(),
			'contact'     => is_array( $payload['contact'] ?? null ) ? $payload['contact'] : array(),
			'sync'        => $read['sync'],
		);
	}
}
