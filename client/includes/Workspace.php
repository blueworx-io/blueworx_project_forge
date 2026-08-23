<?php
/**
 * Reading the studio canonical workspace record.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * The workspace record (ARCH-2): the client site renders a studio record and
 * holds no canonical copy of it.
 *
 * The rule for how a record is read, cached and degraded lives in ReadThrough,
 * which the board reads through as well (#128). What is left here is the only
 * part that is this record's own: the studio answers with a `record`, and a
 * workspace with no record is not a workspace.
 *
 * The STATE_ constants are kept as names on this class because screens and
 * tests already speak of them that way. They are the Sync ones — the same five
 * strings, defined once — rather than a second set that could drift.
 */
final class Workspace {

	/**
	 * The studio route this reads.
	 */
	public const ROUTE = '/client/workspace';

	/**
	 * Never connected to the studio.
	 */
	public const STATE_NOT_CONFIGURED = Sync::STATE_NOT_CONFIGURED;

	/**
	 * Read from the studio just now.
	 */
	public const STATE_LIVE = Sync::STATE_LIVE;

	/**
	 * Served from a copy still within the acceptable staleness window.
	 */
	public const STATE_CACHED = Sync::STATE_CACHED;

	/**
	 * Served from a copy that is past the window because the studio could not be
	 * reached to refresh it.
	 */
	public const STATE_STALE = Sync::STATE_STALE;

	/**
	 * The studio could not be reached and there is nothing cached to fall back
	 * on.
	 */
	public const STATE_UNREACHABLE = Sync::STATE_UNREACHABLE;

	/**
	 * The workspace as this site can currently see it.
	 *
	 * @param bool $force True to ignore a still-fresh copy and ask the studio.
	 * @return array<string, mixed>
	 */
	public static function view( bool $force = false ): array {
		$read    = ReadThrough::view( self::ROUTE, $force );
		$payload = $read['payload'];
		$record  = is_array( $payload['record'] ?? null ) ? $payload['record'] : null;

		return array(
			'ok'     => null !== $record,
			'record' => $record,
			'sync'   => $read['sync'],
		);
	}

	/**
	 * Whether a state means what is on screen may be out of date.
	 *
	 * @param string $state One of the STATE_ constants.
	 * @return bool
	 */
	public static function is_stale( string $state ): bool {
		return Sync::is_stale( $state );
	}
}
