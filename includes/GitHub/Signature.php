<?php
/**
 * The signature on a message from GitHub.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\GitHub;

/**
 * #680. GitHub signs every message with the shared secret and sends the result
 * in X-Hub-Signature-256. The webhook is public, so this is the whole of its
 * door: no valid signature, no message.
 */
final class Signature {

	/**
	 * Whether a message was signed with the secret.
	 *
	 * @param string $raw    The body exactly as it arrived.
	 * @param string $header X-Hub-Signature-256: sha256=<hex>.
	 * @param string $secret The shared secret.
	 * @return bool
	 */
	public static function valid( string $raw, string $header, string $secret ): bool {
		if ( '' === $secret || 0 !== strpos( $header, 'sha256=' ) ) {
			return false;
		}

		return hash_equals( hash_hmac( 'sha256', $raw, $secret ), substr( $header, 7 ) );
	}
}
