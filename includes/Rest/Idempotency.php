<?php
/**
 * Idempotency keys for writes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

/**
 * A write that is retried — a flaky connection, an impatient second click, a
 * client that resends on timeout — must produce one record, not two. The client
 * sends a key with the write; the first attempt under that key does the work and
 * its answer is remembered, and every later attempt gets that same answer back
 * without the work running again.
 *
 * Answers are held in transients rather than a table: they are a short-lived
 * safety net for a retry seconds or minutes later, not an audit trail.
 */
final class Idempotency {

	/**
	 * The header a client sends its key in.
	 */
	public const HEADER = 'Idempotency-Key';

	/**
	 * How long a remembered answer stays available, in seconds.
	 *
	 * Long enough to cover a client retrying after a timeout, short enough that
	 * the same key reused next week is treated as a new write rather than
	 * silently answered from a stale record.
	 */
	public const TTL = 86400;

	/**
	 * Longest key accepted. A key is an identifier, not a payload.
	 */
	public const MAX_KEY_LENGTH = 255;

	/**
	 * Whether a key is usable.
	 *
	 * Keys land in a storage name, so anything outside a conservative character
	 * set is refused rather than escaped — there is no reason for a client to
	 * send a key that needs escaping.
	 *
	 * @param string $key The client's key.
	 * @return bool
	 */
	public static function is_valid_key( string $key ): bool {
		if ( '' === $key || strlen( $key ) > self::MAX_KEY_LENGTH ) {
			return false;
		}

		return 1 === preg_match( '/^[A-Za-z0-9_.:-]+$/', $key );
	}

	/**
	 * Returns the answer already given under this key, if there is one.
	 *
	 * @param string $operation Name of the write, so two different writes reusing
	 *                          a key cannot answer each other.
	 * @param string $key       The client's key.
	 * @return array<string, mixed>|null Null when this key has not been used.
	 */
	public static function replay( string $operation, string $key ): ?array {
		if ( ! self::is_valid_key( $key ) ) {
			return null;
		}

		$stored = get_transient( self::storage_name( $operation, $key ) );

		return is_array( $stored ) ? $stored : null;
	}

	/**
	 * Remembers the answer given under this key.
	 *
	 * @param string               $operation Name of the write.
	 * @param string               $key       The client's key.
	 * @param array<string, mixed> $response  What the first attempt answered.
	 */
	public static function remember( string $operation, string $key, array $response ): void {
		if ( ! self::is_valid_key( $key ) ) {
			return;
		}

		set_transient( self::storage_name( $operation, $key ), $response, self::TTL );
	}

	/**
	 * The storage name for one operation's key.
	 *
	 * Hashed because a transient name is capped at 172 characters and a key may
	 * be 255; hashing also means the stored name cannot be shaped by the client.
	 *
	 * @param string $operation Name of the write.
	 * @param string $key       The client's key.
	 * @return string
	 */
	private static function storage_name( string $operation, string $key ): string {
		return 'bwx_forge_idem_' . md5( $operation . '|' . $key );
	}
}
