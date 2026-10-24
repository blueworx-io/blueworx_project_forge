<?php
/**
 * The check every call from the studio to the network must pass.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Network;

use Blueworx\Forge\Client\Signer;

/**
 * #621. Until now only client sites signed calls *to* the studio; nothing on a
 * client site checked a signed call *from* it. The routes that create a site
 * and its users need that check, so this is it — new code, built to the same
 * canonical form as ARCH-6 (Signer, which the studio's Rest\Signature matches
 * under test), with the fixed site id `network` and the network's own key.
 *
 * Every part of the request is covered: the method and route (so a signature
 * for one route cannot be used on another), a hash of the body (so it cannot
 * be edited in flight), a timestamp within five minutes either side of now
 * (so a captured call goes stale), and a single-use nonce (so it cannot be
 * replayed inside those five minutes). The nonce is claimed last, only once
 * everything else has passed, so nobody can burn the studio's nonces by
 * sending junk.
 *
 * The answer is a yes or a no and nothing else. Which check failed is never
 * said, so a refusal teaches a caller nothing.
 */
final class Gate {

	/**
	 * The site id the studio signs network calls as.
	 */
	public const SITE = 'network';

	/**
	 * How far a call's timestamp may be from now, in seconds.
	 */
	public const WINDOW = 300;

	/**
	 * Whether a call is genuinely from the studio, and new.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $path    The REST route, as signed.
	 * @param string                $body    The raw body.
	 * @param array<string, string> $headers site, timestamp, nonce, signature.
	 * @return bool
	 */
	public static function verify( string $method, string $path, string $body, array $headers ): bool {
		$key       = Key::get();
		$site      = (string) ( $headers['site'] ?? '' );
		$timestamp = (string) ( $headers['timestamp'] ?? '' );
		$nonce     = (string) ( $headers['nonce'] ?? '' );
		$signature = (string) ( $headers['signature'] ?? '' );

		if ( '' === $key || self::SITE !== $site || '' === $nonce || '' === $signature || 1 !== preg_match( '/^\d+$/', $timestamp ) ) {
			return false;
		}

		if ( abs( bwx_forge_client_now() - (int) $timestamp ) > self::WINDOW ) {
			return false;
		}

		$expected = Signer::sign( $key, self::SITE, $method, $path, $timestamp, $nonce, $body );

		if ( ! hash_equals( $expected, $signature ) ) {
			return false;
		}

		return self::claim( $nonce );
	}

	/**
	 * Claims a nonce, once. Remembered for twice the window, which is longer
	 * than any timestamp that could still pass.
	 *
	 * @param string $nonce The nonce.
	 * @return bool False when it has been seen before.
	 */
	private static function claim( string $nonce ): bool {
		$name = 'bwx_forge_net_nonce_' . md5( $nonce );

		if ( false !== get_site_transient( $name ) ) {
			return false;
		}

		set_site_transient( $name, 1, self::WINDOW * 2 );

		return true;
	}
}
