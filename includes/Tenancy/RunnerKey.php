<?php
/**
 * The Forge Runner's key.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

/**
 * The Forge Runner on Luke's PC builds the cards the Agent is doing, and has
 * to read and move them over REST. The Agent never gets a login (#689), and
 * an administrator's login would hand the runner the whole site. So the
 * runner sends this key instead, and the key opens the runner's own few
 * routes and nothing else (Rest\RunnerController).
 *
 * One key per site, made by an administrator and shown to them once. Only a
 * SHA-256 hash of it is kept, so nobody reading the database, a backup or a
 * log can use it; a lost key is replaced, never recovered.
 */
final class RunnerKey {

	/**
	 * Where the hash and the key's dates are kept. Not autoloaded: it is read
	 * on the runner's requests and the Settings screen, not on every page.
	 */
	public const OPTION = 'bwx_forge_runner_key';

	/**
	 * What every key starts with, so one pasted in the wrong place is
	 * recognisable for what it is.
	 */
	public const PREFIX = 'bwxr_';

	/**
	 * The request header the runner sends it in, as WordPress names headers.
	 * Never a query parameter: an address ends up in logs and histories.
	 */
	public const HEADER = 'x_forge_runner_key';

	/*
	 * There is deliberately no lockout after wrong keys. The key is 256
	 * random bits, so guessing it is not a real attack; a lockout would let
	 * anybody stop the runner by sending a few bad requests, and counting
	 * them would let anybody write to the database without a key.
	 */

	/**
	 * How stale the last-used time may be before it is written again, so a
	 * runner asking every few minutes does not write the option every time.
	 */
	private const USED_EVERY = 60;

	/**
	 * Makes a new key, replacing any there was. The key is returned once and
	 * kept nowhere.
	 *
	 * @param int $by The administrator making it.
	 * @return string
	 */
	public static function make( int $by ): string {
		$key = self::PREFIX . rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding random bytes as text, not hiding anything.

		update_option(
			self::OPTION,
			array(
				'hash'         => hash( 'sha256', $key ),
				'created_at'   => bwx_forge_now(),
				'created_by'   => $by,
				'enabled'      => true,
				'last_used_at' => 0,
			),
			false
		);

		return $key;
	}

	/**
	 * What an administrator is shown. Never the key, never its hash.
	 *
	 * @return array{exists: bool, enabled: bool, created_at: int, created_by: int, last_used_at: int}
	 */
	public static function status(): array {
		$stored = self::stored();

		return array(
			'exists'       => null !== $stored,
			'enabled'      => null !== $stored && ! empty( $stored['enabled'] ),
			'created_at'   => (int) ( $stored['created_at'] ?? 0 ),
			'created_by'   => (int) ( $stored['created_by'] ?? 0 ),
			'last_used_at' => (int) ( $stored['last_used_at'] ?? 0 ),
		);
	}

	/**
	 * Turns the key on or off, keeping it.
	 *
	 * @param bool $enabled On or off.
	 * @return bool False when there is no key to turn.
	 */
	public static function set_enabled( bool $enabled ): bool {
		$stored = self::stored();

		if ( null === $stored ) {
			return false;
		}

		$stored['enabled'] = $enabled;

		update_option( self::OPTION, $stored, false );

		return true;
	}

	/**
	 * Removes the key. The runner is refused from now on.
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Whether a key sent with a request is the site's key, and switched on.
	 *
	 * Compared as hashes with hash_equals(), so the time taken says nothing
	 * about how much of a guess was right; and a hash is still worked out and
	 * compared when there is no key, so "no key here" and "wrong key" take
	 * the same time too.
	 *
	 * @param string $presented The key the request carried.
	 * @return bool
	 */
	public static function matches( string $presented ): bool {
		$stored = self::stored();
		$known  = null === $stored ? str_repeat( '0', 64 ) : (string) $stored['hash'];
		$same   = hash_equals( $known, hash( 'sha256', $presented ) );

		return $same && '' !== $presented && null !== $stored && ! empty( $stored['enabled'] );
	}

	/**
	 * Notes that the key was used, at most once a minute.
	 *
	 * @param int $now Unix time.
	 */
	public static function used( int $now ): void {
		$stored = self::stored();

		if ( null === $stored || $now - (int) $stored['last_used_at'] < self::USED_EVERY ) {
			return;
		}

		$stored['last_used_at'] = $now;

		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Whether the key may be taken over this connection. Over https, always.
	 * Over plain http only on a site that is not a live one: it says it is
	 * local or in development, or it lives at a loopback address, as the
	 * local test WordPress does. Pure.
	 *
	 * @param bool   $ssl       Whether the request came over https.
	 * @param string $env       wp_get_environment_type().
	 * @param string $home_host The host of the site's own address.
	 * @return bool
	 */
	public static function transport_allowed( bool $ssl, string $env, string $home_host ): bool {
		return $ssl
			|| in_array( $env, array( 'local', 'development' ), true )
			|| in_array( strtolower( $home_host ), array( '127.0.0.1', 'localhost', '::1', '[::1]' ), true );
	}

	/**
	 * What is saved, or null when there is no key.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function stored(): ?array {
		$stored = get_option( self::OPTION, null );

		return is_array( $stored ) && '' !== (string) ( $stored['hash'] ?? '' ) ? $stored : null;
	}
}
