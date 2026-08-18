<?php
/**
 * The client site's local copy of what it last read from the studio.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * A stamped copy of the last answer the studio gave.
 *
 * ARCH-2 makes the studio canonical: nothing here is a record, it is a receipt
 * of one. It exists for two reasons, and neither is authority. Ordinary
 * browsing is served locally so every page view is not a network round trip,
 * and when the studio is unreachable the site degrades to a read-only view with
 * a visible stale-data notice rather than to an error page (ARCH-4).
 *
 * Stored as an option rather than a transient on purpose. A transient's whole
 * behaviour is to vanish at its expiry, which would take the stale copy with it
 * — and the stale copy is exactly what ARCH-4 needs to still be there when the
 * studio is down. Age is judged here instead, from the stamp.
 */
final class Cache {

	/**
	 * Option holding the cached payloads, keyed by route.
	 */
	public const OPTION = 'bwx_forge_client_cache';

	/**
	 * How old a copy may be before it is refreshed.
	 *
	 * ARCH-5 sets acceptable staleness on a client site at 60 seconds.
	 */
	public const MAX_AGE = 60;

	/**
	 * The stored entry for a route, or null.
	 *
	 * @param string $route Route within the studio namespace.
	 * @return array{payload: array<string, mixed>, fetched_at: int, failed: array<string, mixed>}|null
	 */
	public static function get( string $route ): ?array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) || ! isset( $stored[ $route ] ) ) {
			return null;
		}

		$entry = $stored[ $route ];

		if ( ! is_array( $entry ) || ! isset( $entry['payload'], $entry['fetched_at'] ) ) {
			return null;
		}

		return array(
			'payload'    => (array) $entry['payload'],
			'fetched_at' => (int) $entry['fetched_at'],
			'failed'     => is_array( $entry['failed'] ?? null ) ? $entry['failed'] : array(),
		);
	}

	/**
	 * Stores a fresh answer, stamped with the time it arrived.
	 *
	 * @param string               $route   Route within the studio namespace.
	 * @param array<string, mixed> $payload The studio's answer.
	 */
	public static function put( string $route, array $payload ): void {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$stored[ $route ] = array(
			'payload'    => $payload,
			'fetched_at' => bwx_forge_client_now(),
			// A success clears the last failure. What is recorded is always the
			// state of the most recent attempt, never a grievance the site keeps
			// showing after it has been fixed.
			'failed'     => array(),
		);

		update_option( self::OPTION, $stored );
	}

	/**
	 * Records that the last attempt to refresh this route failed.
	 *
	 * Kept next to the copy it failed to replace, because the two are only
	 * meaningful together: a copy from thirty seconds ago is fresh, and a copy
	 * from thirty seconds ago that could not be refreshed since is not. Without
	 * this the screen goes back to reporting itself up to date the moment the
	 * person reloads the page, which is the dishonest state ARCH-4 exists to
	 * avoid.
	 *
	 * @param string               $route   Route within the studio namespace.
	 * @param array<string, mixed> $failure Reason and HTTP status.
	 */
	public static function fail( string $route, array $failure ): void {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		if ( ! isset( $stored[ $route ] ) || ! is_array( $stored[ $route ] ) ) {
			return;
		}

		$stored[ $route ]['failed'] = array_merge( $failure, array( 'at' => bwx_forge_client_now() ) );

		update_option( self::OPTION, $stored );
	}

	/**
	 * How many seconds old an entry is.
	 *
	 * @param array{fetched_at: int} $entry Stored entry.
	 * @return int
	 */
	public static function age( array $entry ): int {
		return max( 0, bwx_forge_client_now() - (int) $entry['fetched_at'] );
	}

	/**
	 * Whether an entry is old enough to be worth refreshing.
	 *
	 * @param array{fetched_at: int} $entry Stored entry.
	 * @return bool
	 */
	public static function is_expired( array $entry ): bool {
		return self::age( $entry ) >= self::MAX_AGE;
	}

	/**
	 * Throws everything away.
	 *
	 * Called whenever the site's credentials change: what is cached was read as
	 * one site, and after a re-connection this may be a different one. Serving
	 * the old copy would show a client another client's record.
	 */
	public static function flush(): void {
		delete_option( self::OPTION );
	}
}
