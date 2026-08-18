<?php
/**
 * The two signing implementations must agree.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Client\Signer;
use Blueworx\Forge\Rest\Signature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The studio verifies signatures with Rest\Signature; the client produces them
 * with Client\Signer. They are separate files because a client site contains no
 * studio code (ARCH-1), so nothing at runtime can hold them together.
 *
 * This does. If the canonical form changes on one side and not the other, every
 * client site in the world starts being refused — and it would be refused with
 * "bad signature", which reads like a key problem and sends you looking in the
 * wrong place entirely. So the drift is caught here, at build time, instead.
 */
final class SignerConformanceTest extends TestCase {

	/**
	 * Inputs chosen to catch the ways two implementations usually differ:
	 * a body or not, case in the method, a path with segments, and characters
	 * that survive one encoding but not another.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string}>
	 */
	public static function requests(): array {
		return array(
			'empty GET'            => array( 'key-1', 'site_a', 'GET', '/blueworx-forge/v1/client/handshake', '1000000', 'nonce-1', '' ),
			'POST with a body'     => array( 'key-1', 'site_a', 'POST', '/blueworx-forge/v1/client/thing', '1000001', 'nonce-2', '{"hours":3}' ),
			'lowercase method'     => array( 'key-1', 'site_a', 'get', '/blueworx-forge/v1/client/handshake', '1000002', 'nonce-3', '' ),
			'unicode in the body'  => array( 'key-2', 'site_b', 'POST', '/blueworx-forge/v1/client/thing', '1000003', 'nonce-4', '{"name":"Ståle — ok"}' ),
			'newline in the body'  => array( 'key-2', 'site_b', 'POST', '/blueworx-forge/v1/client/thing', '1000004', 'nonce-5', "line one\nline two" ),
			'path with a trailing' => array( 'key-2', 'site_b', 'GET', '/blueworx-forge/v1/client/handshake/', '1000005', 'nonce-6', '' ),
		);
	}

	/**
	 * Both sides produce the same signature for the same request.
	 *
	 *
	 * @param string $key       Signing key.
	 * @param string $site_id   Site id.
	 * @param string $method    HTTP method.
	 * @param string $path      Route.
	 * @param string $timestamp Timestamp.
	 * @param string $nonce     Nonce.
	 * @param string $body      Body.
	 */
	#[DataProvider( 'requests' )]
	public function test_both_implementations_agree(
		string $key,
		string $site_id,
		string $method,
		string $path,
		string $timestamp,
		string $nonce,
		string $body
	): void {
		$studio = Signature::sign( $key, $site_id, $method, $path, $timestamp, $nonce, $body );
		$client = Signer::sign( $key, $site_id, $method, $path, $timestamp, $nonce, $body );

		$this->assertSame( $studio, $client );
	}

	/**
	 * The header names match too. Agreeing on the signature and disagreeing about
	 * which header carries it fails just as completely.
	 */
	public function test_the_header_names_match(): void {
		$this->assertSame( Signature::HEADER_SITE, Signer::HEADER_SITE );
		$this->assertSame( Signature::HEADER_TIMESTAMP, Signer::HEADER_TIMESTAMP );
		$this->assertSame( Signature::HEADER_NONCE, Signer::HEADER_NONCE );
		$this->assertSame( Signature::HEADER_SIGNATURE, Signer::HEADER_SIGNATURE );
	}

	/**
	 * A signature the client produces actually verifies at the studio. The tests
	 * above compare the two functions; this one proves the pair works as a whole,
	 * against the real verifier including its freshness and nonce checks.
	 */
	public function test_a_client_signature_verifies_at_the_studio(): void {
		$GLOBALS['bwx_forge_test_options']    = array();
		$GLOBALS['bwx_forge_test_transients'] = array();
		$GLOBALS['bwx_forge_test_now']        = 1000000;

		$site = \Blueworx\Forge\Sites\Registry::register( 'Acme', 'https://acme.example' );

		$path      = '/blueworx-forge/v1/client/handshake';
		$timestamp = (string) $GLOBALS['bwx_forge_test_now'];
		$nonce     = 'conformance-nonce';

		$signature = Signer::sign( $site['key'], $site['site_id'], 'GET', $path, $timestamp, $nonce, '' );

		$this->assertTrue(
			Signature::verify( $site['site_id'], $signature, $timestamp, $nonce, 'GET', $path, '' )
		);
	}
}
