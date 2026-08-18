<?php
/**
 * Signing requests to the studio.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * The client half of ARCH-6's signed requests.
 *
 * **This duplicates the canonical form in the studio's
 * includes/Rest/Signature.php, on purpose.** The two artifacts ship separately
 * and a client site contains no studio code (ARCH-1), so there is no file both
 * can load. Sharing one would mean widening the closed list in
 * bin/check-artifacts.mjs, which is a decision about the boundary rather than a
 * convenience for ten lines.
 *
 * What stops the two drifting is not discipline but a test:
 * tests/php/SignerConformanceTest.php signs the same inputs with both classes
 * and fails if the results differ. Change the canonical form here and that test
 * goes red until the studio matches.
 */
final class Signer {

	/**
	 * Header carrying the site id.
	 */
	public const HEADER_SITE = 'X-BWX-Site';

	/**
	 * Header carrying the Unix timestamp the request was signed at.
	 */
	public const HEADER_TIMESTAMP = 'X-BWX-Timestamp';

	/**
	 * Header carrying the single-use nonce.
	 */
	public const HEADER_NONCE = 'X-BWX-Nonce';

	/**
	 * Header carrying the signature.
	 */
	public const HEADER_SIGNATURE = 'X-BWX-Signature';

	/**
	 * Signs a request.
	 *
	 * @param string $key       The site's signing key.
	 * @param string $site_id   Site id.
	 * @param string $method    HTTP method.
	 * @param string $path      REST route, as the studio resolves it.
	 * @param string $timestamp Unix timestamp.
	 * @param string $nonce     Single-use nonce.
	 * @param string $body      Raw request body.
	 * @return string Hex HMAC-SHA256.
	 */
	public static function sign(
		string $key,
		string $site_id,
		string $method,
		string $path,
		string $timestamp,
		string $nonce,
		string $body
	): string {
		$canonical = implode(
			"\n",
			array(
				$site_id,
				strtoupper( $method ),
				$path,
				$timestamp,
				$nonce,
				hash( 'sha256', $body ),
			)
		);

		return hash_hmac( 'sha256', $canonical, $key );
	}

	/**
	 * The headers a signed request carries.
	 *
	 * @param string $key     The site's signing key.
	 * @param string $site_id Site id.
	 * @param string $method  HTTP method.
	 * @param string $path    REST route.
	 * @param string $body    Raw request body.
	 * @return array<string, string>
	 */
	public static function headers( string $key, string $site_id, string $method, string $path, string $body = '' ): array {
		$timestamp = (string) time();
		// 128 bits from the CSPRNG. A nonce only has to be unique within the
		// signing window, but a predictable one would let a listener pre-burn the
		// value a legitimate request is about to use.
		$nonce = bin2hex( random_bytes( 16 ) );

		return array(
			self::HEADER_SITE      => $site_id,
			self::HEADER_TIMESTAMP => $timestamp,
			self::HEADER_NONCE     => $nonce,
			self::HEADER_SIGNATURE => self::sign( $key, $site_id, $method, $path, $timestamp, $nonce, $body ),
		);
	}
}
