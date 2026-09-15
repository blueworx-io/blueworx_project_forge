<?php
/**
 * Secrets kept at rest.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

/**
 * An API token for somebody else's service has to be kept, because Forge
 * has to use it again; and it must not be readable by anyone who can read
 * the database, because that is most of what a database backup is.
 *
 * So it is sealed with libsodium under a key derived from this site's
 * AUTH_KEY — which lives in wp-config.php, not the database — and opened
 * only in PHP, at the moment of use. Nothing here ever hands a plaintext
 * back to a browser. A site whose AUTH_KEY changes loses the ability to
 * open what was sealed under the old one, which is the right failure: the
 * token is re-entered rather than recovered.
 */
final class Secrets {

	/**
	 * Seals a secret for storage.
	 *
	 * @param string $plain The secret.
	 * @return string Base64 of the nonce and the ciphertext.
	 */
	public static function seal( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Ciphertext is binary; this is how it fits a text column.
		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) );
	}

	/**
	 * Opens a sealed secret.
	 *
	 * @param string $sealed What seal() returned.
	 * @return string|null Null when it was not sealed here, or was changed.
	 */
	public static function open( string $sealed ): ?string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- The reverse of seal(); the bytes are ciphertext, not code.
		$bytes = base64_decode( $sealed, true );

		if ( false === $bytes || strlen( $bytes ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}

		$nonce  = substr( $bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$sealed = substr( $bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $sealed, $nonce, self::key() );

		return false === $plain ? null : $plain;
	}

	/**
	 * The key, derived rather than stored.
	 *
	 * @return string
	 */
	private static function key(): string {
		$auth = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '';

		return sodium_crypto_generichash( $auth . '|bwx-forge-secrets', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
