<?php
/**
 * What a client site's connection is currently doing.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

/**
 * Health is derived, never stored.
 *
 * A stored status is only ever as current as the last thing that wrote it, and
 * the interesting transition here — a site going quiet — is precisely the case
 * where nothing writes. So the record carries facts with timestamps, and this
 * class turns them into a state at the moment somebody asks.
 *
 * It is a pure function of the record for the same reason: every screen, every
 * endpoint and every later report has to agree about what "broken" means, and
 * the only way to guarantee that is for there to be one answer.
 */
final class Health {

	/**
	 * No key has ever been issued: nobody has connected this site.
	 */
	public const UNCONFIGURED = 'unconfigured';

	/**
	 * Cut off deliberately. Not a fault.
	 */
	public const REVOKED = 'revoked';

	/**
	 * A key exists and has never been used.
	 */
	public const NEVER_CONNECTED = 'never_connected';

	/**
	 * Called recently, nothing failed since.
	 */
	public const CONNECTED = 'connected';

	/**
	 * Tried and failed, with no success since.
	 */
	public const BROKEN = 'broken';

	/**
	 * Has not called recently, and did not fail. Quiet, not broken.
	 */
	public const IDLE = 'idle';

	/**
	 * Every state, for anything that needs to enumerate them.
	 */
	public const STATES = array(
		self::UNCONFIGURED,
		self::REVOKED,
		self::NEVER_CONNECTED,
		self::CONNECTED,
		self::BROKEN,
		self::IDLE,
	);

	/**
	 * How long a site may go without calling before it reads as idle rather than
	 * connected, in seconds.
	 *
	 * A day, because the client plugin reports itself daily on cron: anything
	 * shorter would show every site as idle overnight, and anything much longer
	 * would take days to notice a site that had gone away.
	 */
	public const WINDOW_SECONDS = 86400;

	/**
	 * The state of one integration record.
	 *
	 * @param array<string, mixed> $record Integration record.
	 * @return string One of the constants above.
	 */
	public static function state( array $record ): string {
		$key_state = (string) ( $record['key_state'] ?? 'unissued' );

		if ( 'revoked' === $key_state ) {
			return self::REVOKED;
		}

		if ( 'active' !== $key_state ) {
			return self::UNCONFIGURED;
		}

		$seen  = (int) ( $record['last_seen_at'] ?? 0 );
		$error = (int) ( $record['last_error_at'] ?? 0 );

		/*
		 * Strictly greater than, so a failure recorded in the same second as a
		 * success does not count as having come after it. These are Unix
		 * seconds and a busy site produces ties routinely; resolving a tie the
		 * other way would flick a working site red for a request that also
		 * succeeded.
		 */
		if ( $error > $seen ) {
			return self::BROKEN;
		}

		if ( 0 === $seen ) {
			return self::NEVER_CONNECTED;
		}

		return bwx_forge_now() - $seen <= self::window() ? self::CONNECTED : self::IDLE;
	}

	/**
	 * How that state reads to a human.
	 *
	 * @param string $state One of the constants above.
	 * @return string
	 */
	public static function label( string $state ): string {
		switch ( $state ) {
			case self::UNCONFIGURED:
				return __( 'Not connected yet', 'blueworx-forge' );
			case self::REVOKED:
				return __( 'Cut off', 'blueworx-forge' );
			case self::NEVER_CONNECTED:
				return __( 'Key issued, never used', 'blueworx-forge' );
			case self::CONNECTED:
				return __( 'Connected', 'blueworx-forge' );
			case self::BROKEN:
				return __( 'Broken', 'blueworx-forge' );
			case self::IDLE:
				return __( 'Idle', 'blueworx-forge' );
			default:
				return __( 'Unknown', 'blueworx-forge' );
		}
	}

	/**
	 * Whether a state is one somebody has to do something about. `idle` is
	 * deliberately not: a site nobody is using has nothing wrong with it.
	 *
	 * @param string $state One of the constants above.
	 * @return bool
	 */
	public static function needs_attention( string $state ): bool {
		return self::BROKEN === $state;
	}

	/**
	 * The idle window, filterable so a site with a different reporting cadence
	 * can say so without a code change.
	 *
	 * @return int
	 */
	private static function window(): int {
		$window = (int) apply_filters( 'bwx_forge_connection_idle_window', self::WINDOW_SECONDS );

		// A window of zero or less would make every site idle the instant it
		// called, which is not a configuration anybody means.
		return $window > 0 ? $window : self::WINDOW_SECONDS;
	}
}
