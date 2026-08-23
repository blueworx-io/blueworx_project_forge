<?php
/**
 * How fresh what a client site is showing actually is.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * The five states any read-through record on a client site can be in.
 *
 * These live in one place because more than one screen now depends on them, and
 * two screens disagreeing about what "stale" means is exactly the failure
 * ARCH-4 is written to prevent: a person looking at two tabs, one honest about
 * its age and one not.
 */
final class Sync {

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
	 * The studio could not be reached and there is nothing cached to fall back
	 * on.
	 */
	public const STATE_UNREACHABLE = 'unreachable';

	/**
	 * Whether a state means what is on screen may be out of date.
	 *
	 * @param string $state One of the STATE_ constants.
	 * @return bool
	 */
	public static function is_stale( string $state ): bool {
		return self::STATE_STALE === $state || self::STATE_UNREACHABLE === $state;
	}
}
