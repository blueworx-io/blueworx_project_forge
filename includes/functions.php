<?php
/**
 * Plain functions the plugin's classes rely on.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

if ( ! function_exists( 'bwx_forge_now' ) ) {
	/**
	 * The current Unix timestamp, in UTC.
	 *
	 * Deliberately not current_time( 'timestamp' ), which returns the site's
	 * local time. Signed requests compare a client site's clock with the
	 * studio's, and the two sites may be in different timezones — a local-time
	 * comparison would refuse every request from a client an hour away.
	 *
	 * It exists as a function so the unit tests can control the clock and prove
	 * the signature window without waiting five minutes.
	 *
	 * @return int
	 */
	function bwx_forge_now(): int {
		return time();
	}
}
