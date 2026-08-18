<?php
/**
 * Small helpers the client plugin needs before any class is loaded.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

if ( ! function_exists( 'bwx_forge_client_now' ) ) {
	/**
	 * The current Unix time, in UTC.
	 *
	 * Everything that ages — the cache, the last-sync indicator — reads the clock
	 * through here rather than calling time() directly, so a test can move time
	 * without waiting a minute for a cache to expire.
	 *
	 * Deliberately separate from the studio's bwx_forge_now(): a client site
	 * contains no studio code (ARCH-1), so it cannot call it.
	 *
	 * @return int
	 */
	function bwx_forge_client_now(): int {
		return time();
	}
}
