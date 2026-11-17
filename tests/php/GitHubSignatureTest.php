<?php
/**
 * GitHub message signature tests.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\GitHub\Signature;
use PHPUnit\Framework\TestCase;

/**
 * #680. The webhook is public, so the signature is the only thing standing
 * between it and the world.
 */
final class GitHubSignatureTest extends TestCase {

	/**
	 * A message signed with the secret is accepted.
	 */
	public function test_a_correct_signature_is_valid(): void {
		$header = 'sha256=' . hash_hmac( 'sha256', '{"a":1}', 's' );

		$this->assertTrue( Signature::valid( '{"a":1}', $header, 's' ) );
	}

	/**
	 * Anything else is refused.
	 */
	public function test_a_wrong_secret_or_missing_prefix_is_not(): void {
		$hex    = hash_hmac( 'sha256', '{"a":1}', 's' );
		$header = 'sha256=' . $hex;

		$this->assertFalse( Signature::valid( '{"a":1}', $header, 't' ) );
		$this->assertFalse( Signature::valid( '{"a":1}', $hex, 's' ) );
		$this->assertFalse( Signature::valid( '{"a":1}', '', 's' ) );
		$this->assertFalse( Signature::valid( '{"a":2}', $header, 's' ) );
		$this->assertFalse( Signature::valid( '{"a":1}', 'sha256=' . hash_hmac( 'sha256', '{"a":1}', '' ), '' ) );
	}
}
