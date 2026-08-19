<?php
/**
 * Signed request verification for client sites.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Sites\Registry;
use Blueworx\Forge\Sites\SecurityLog;
use WP_Error;

/**
 * ARCH-6: requests are signed and carry a timestamp and nonce, so a captured
 * request cannot be replayed.
 *
 * The signature covers the site, the method, the path, the timestamp, the nonce
 * and a hash of the body. Every one of those is load-bearing:
 *
 * - **method and path**, or a signature captured from a harmless read could be
 *   replayed onto a write.
 * - **body hash**, or a captured request could be edited in flight.
 * - **timestamp**, or a captured request stays valid forever.
 * - **nonce**, or a captured request stays valid for the whole window — which is
 *   plenty of time to use it.
 * - **site**, or a signature from one client could be presented as another's.
 *
 * The timestamp alone is not enough and the nonce alone is not enough: the
 * timestamp bounds how long a nonce must be remembered, and the nonce closes the
 * window the timestamp leaves open.
 */
final class Signature {

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
	 * How far from now a request's timestamp may be, in seconds, in either
	 * direction. Wide enough for ordinary clock drift between two servers,
	 * narrow enough that a captured request is useless within minutes.
	 */
	public const WINDOW_SECONDS = 300;

	/**
	 * Builds the signature for a request. Used by the client artifact to sign,
	 * and here to check — one function, so the two cannot disagree about the
	 * canonical form.
	 *
	 * @param string $key       The site's signing key.
	 * @param string $site_id   Site id.
	 * @param string $method    HTTP method.
	 * @param string $path      Request path.
	 * @param string $timestamp Unix timestamp, as sent.
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
		return hash_hmac( 'sha256', self::canonical( $site_id, $method, $path, $timestamp, $nonce, $body ), $key );
	}

	/**
	 * Verifies a signed request.
	 *
	 * Order matters. The site is resolved first so a refusal can be logged
	 * against something, then the cheap checks, then the signature — but the
	 * refusal returned to the caller is deliberately vague about which stage
	 * failed, so the response cannot be used to enumerate registered sites.
	 *
	 * @param string $site_id   Site id claimed by the request.
	 * @param string $signature Signature sent.
	 * @param string $timestamp Timestamp sent.
	 * @param string $nonce     Nonce sent.
	 * @param string $method    HTTP method.
	 * @param string $path      Request path.
	 * @param string $body      Raw request body.
	 * @return true|WP_Error True when the request is genuine.
	 */
	public static function verify(
		string $site_id,
		string $signature,
		string $timestamp,
		string $nonce,
		string $method,
		string $path,
		string $body
	) {
		if ( '' === $site_id || ! Registry::exists( $site_id ) ) {
			return self::refuse( $site_id, 'unknown_site' );
		}

		if ( Registry::is_revoked( $site_id ) ) {
			return self::refuse( $site_id, 'revoked_site' );
		}

		$key = Registry::key_for( $site_id );

		if ( null === $key || '' === $key ) {
			return self::refuse( $site_id, 'revoked_site' );
		}

		if ( '' === $nonce || '' === $timestamp || ! self::is_fresh( $timestamp ) ) {
			return self::refuse( $site_id, 'stale_request' );
		}

		$expected = self::sign( $key, $site_id, $method, $path, $timestamp, $nonce, $body );

		// hash_equals, not ===: a byte-by-byte comparison that stops at the first
		// difference leaks how much of a guessed signature was right.
		if ( '' === $signature || ! hash_equals( $expected, $signature ) ) {
			return self::refuse( $site_id, 'bad_signature' );
		}

		// Claimed last, and only once everything else has passed. Claiming it
		// earlier would let an unauthenticated caller burn another site's nonces.
		if ( ! self::claim_nonce( $site_id, $nonce ) ) {
			return self::refuse( $site_id, 'replayed_request' );
		}

		/*
		 * Announced, not written. The Client Site Integration record (#89)
		 * stamps freshness from this, and it stamps it from every route a site
		 * calls rather than from one instrumented endpoint — which is the only
		 * way "last seen" means last seen. Doing the write here would put a
		 * database round trip inside the signature check and make this class
		 * untestable without a site around it.
		 *
		 * Spelled out rather than passed as a constant, matching SecurityLog: a
		 * hook name built from a constant is invisible to anybody grepping for
		 * who fires it.
		 */
		do_action( 'bwx_forge_site_verified', $site_id );

		return true;
	}

	/**
	 * Whether a timestamp is inside the window, in either direction.
	 *
	 * @param string $timestamp Timestamp as sent.
	 * @return bool
	 */
	private static function is_fresh( string $timestamp ): bool {
		if ( ! ctype_digit( ltrim( $timestamp, '-' ) ) ) {
			return false;
		}

		return abs( bwx_forge_now() - (int) $timestamp ) <= self::WINDOW_SECONDS;
	}

	/**
	 * Records a nonce as used, returning false if it already was.
	 *
	 * Held for twice the window: a nonce only has to outlive the period in which
	 * its timestamp would still be accepted, and the doubling covers clock drift
	 * at both ends of that.
	 *
	 * @param string $site_id Site id.
	 * @param string $nonce   Nonce.
	 * @return bool False when this nonce has been seen before.
	 */
	private static function claim_nonce( string $site_id, string $nonce ): bool {
		$name = 'bwx_forge_nonce_' . md5( $site_id . '|' . $nonce );

		if ( false !== get_transient( $name ) ) {
			return false;
		}

		set_transient( $name, 1, self::WINDOW_SECONDS * 2 );

		return true;
	}

	/**
	 * The canonical string both ends sign.
	 *
	 * Newline-separated with the body hashed rather than included: the parts are
	 * fixed-position so no value can be shifted into another's place, and the
	 * hash keeps the string small whatever the payload.
	 *
	 * @param string $site_id   Site id.
	 * @param string $method    HTTP method.
	 * @param string $path      Request path.
	 * @param string $timestamp Timestamp.
	 * @param string $nonce     Nonce.
	 * @param string $body      Raw body.
	 * @return string
	 */
	private static function canonical(
		string $site_id,
		string $method,
		string $path,
		string $timestamp,
		string $nonce,
		string $body
	): string {
		return implode(
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
	}

	/**
	 * Logs a refusal and returns the response the caller gets.
	 *
	 * One code, one message and one status for every reason. The log knows
	 * exactly what happened; the caller is told only that it was refused.
	 *
	 * The uniform code is the point. Returning bwx_forge_unknown_site for one
	 * failure and bwx_forge_revoked_site for another tells an unauthenticated
	 * caller which site ids are real — and tells somebody holding a stolen key
	 * that it was genuine and has since been revoked, rather than never having
	 * worked. Neither is much use on its own; neither is worth handing over.
	 *
	 * @param string $site_id Site id claimed.
	 * @param string $reason  Why it was refused.
	 * @return WP_Error
	 */
	private static function refuse( string $site_id, string $reason ): WP_Error {
		SecurityLog::refused( $site_id, $reason );

		return Errors::rest(
			'unauthenticated',
			__( 'This request could not be authenticated.', 'blueworx-forge' ),
			401
		);
	}
}
