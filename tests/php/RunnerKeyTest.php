<?php
/**
 * The runner key: made, kept as a hash, and checked.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\RunnerKey;
use PHPUnit\Framework\TestCase;

/**
 * The Forge Runner on Luke's PC reads and moves the Agent's cards with this
 * key instead of a login. These are the rules the key itself keeps; what it
 * reaches is RunnerCardsTest's and RunnerRoutesTest's.
 */
final class RunnerKeyTest extends TestCase {

	/**
	 * A clean option and transient store, and a fixed clock.
	 */
	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_options']    = array();
		$GLOBALS['bwx_forge_test_transients'] = array();
		$GLOBALS['bwx_forge_test_now']        = 1000000;
	}

	/**
	 * The key is recognisable and long enough: the prefix, then at least 32
	 * random bytes as base64url.
	 */
	public function test_a_key_is_the_prefix_and_32_random_bytes(): void {
		$key = RunnerKey::make( 7 );

		$this->assertStringStartsWith( 'bwxr_', $key );
		$this->assertMatchesRegularExpression( '/^bwxr_[A-Za-z0-9_-]{43,}$/', $key );
		$this->assertNotSame( $key, RunnerKey::make( 7 ), 'two keys are never the same' );
	}

	/**
	 * Only a hash is kept. The key itself is nowhere in what is stored.
	 */
	public function test_only_the_hash_is_stored(): void {
		$key    = RunnerKey::make( 7 );
		$stored = $GLOBALS['bwx_forge_test_options'][ RunnerKey::OPTION ];

		$this->assertSame( hash( 'sha256', $key ), $stored['hash'] );
		$this->assertStringNotContainsString( $key, serialize( $stored ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$this->assertSame( 7, $stored['created_by'] );
		$this->assertSame( 1000000, $stored['created_at'] );
		$this->assertTrue( $stored['enabled'] );
		$this->assertSame( 0, $stored['last_used_at'] );
	}

	/**
	 * The status an administrator sees says whether there is a key and when it
	 * was used, and never the key or its hash.
	 */
	public function test_the_status_never_carries_the_key_or_its_hash(): void {
		$this->assertFalse( RunnerKey::status()['exists'] );

		$key    = RunnerKey::make( 7 );
		$status = RunnerKey::status();

		$this->assertTrue( $status['exists'] );
		$this->assertTrue( $status['enabled'] );
		$this->assertSame( 1000000, $status['created_at'] );
		$this->assertSame( 0, $status['last_used_at'] );
		$this->assertArrayNotHasKey( 'hash', $status );
		$this->assertStringNotContainsString( $key, (string) wp_json_encode( $status ) );
		$this->assertStringNotContainsString( hash( 'sha256', $key ), (string) wp_json_encode( $status ) );
	}

	/**
	 * The right key matches; anything else does not.
	 */
	public function test_only_the_right_key_matches(): void {
		$key = RunnerKey::make( 7 );

		$this->assertTrue( RunnerKey::matches( $key ) );
		$this->assertFalse( RunnerKey::matches( $key . 'x' ) );
		$this->assertFalse( RunnerKey::matches( 'bwxr_' . str_repeat( 'A', 43 ) ) );
		$this->assertFalse( RunnerKey::matches( '' ) );
		$this->assertFalse( RunnerKey::matches( strtoupper( $key ) ) );
	}

	/**
	 * Making a key again replaces the old one, which stops working.
	 */
	public function test_replacing_the_key_retires_the_old_one(): void {
		$old = RunnerKey::make( 7 );
		$new = RunnerKey::make( 7 );

		$this->assertFalse( RunnerKey::matches( $old ) );
		$this->assertTrue( RunnerKey::matches( $new ) );
	}

	/**
	 * A key turned off is refused, and works again once turned back on.
	 */
	public function test_a_turned_off_key_is_refused(): void {
		$key = RunnerKey::make( 7 );

		$this->assertTrue( RunnerKey::set_enabled( false ) );
		$this->assertFalse( RunnerKey::matches( $key ) );
		$this->assertFalse( RunnerKey::status()['enabled'] );

		$this->assertTrue( RunnerKey::set_enabled( true ) );
		$this->assertTrue( RunnerKey::matches( $key ) );
	}

	/**
	 * Turning on a key that does not exist does nothing, and says so.
	 */
	public function test_there_is_nothing_to_turn_on_without_a_key(): void {
		$this->assertFalse( RunnerKey::set_enabled( true ) );
		$this->assertFalse( RunnerKey::status()['exists'] );
	}

	/**
	 * A removed key is refused, and nothing of it is kept.
	 */
	public function test_a_removed_key_is_refused(): void {
		$key = RunnerKey::make( 7 );

		RunnerKey::forget();

		$this->assertFalse( RunnerKey::matches( $key ) );
		$this->assertArrayNotHasKey( RunnerKey::OPTION, $GLOBALS['bwx_forge_test_options'] );
	}

	/**
	 * With no key at all, nothing matches, including an empty string.
	 */
	public function test_with_no_key_nothing_matches(): void {
		$this->assertFalse( RunnerKey::matches( '' ) );
		$this->assertFalse( RunnerKey::matches( 'bwxr_anything' ) );
	}

	/**
	 * When the key was last used is noted, but not on every request.
	 */
	public function test_last_used_is_noted_at_most_once_a_minute(): void {
		RunnerKey::make( 7 );

		RunnerKey::used( 1000100 );
		$this->assertSame( 1000100, RunnerKey::status()['last_used_at'] );

		RunnerKey::used( 1000130 );
		$this->assertSame( 1000100, RunnerKey::status()['last_used_at'], 'thirty seconds later is not written' );

		RunnerKey::used( 1000200 );
		$this->assertSame( 1000200, RunnerKey::status()['last_used_at'] );
	}

	/**
	 * Over https the key is taken anywhere. Over plain http only on a site
	 * that says it is local or in development, or that lives at a loopback
	 * address: the local test WordPress has no certificate.
	 */
	public function test_the_key_is_only_taken_over_https_on_a_real_site(): void {
		$this->assertTrue( RunnerKey::transport_allowed( true, 'production', 'forge.example.test' ) );
		$this->assertFalse( RunnerKey::transport_allowed( false, 'production', 'forge.example.test' ) );
		$this->assertFalse( RunnerKey::transport_allowed( false, 'staging', 'forge.example.test' ) );
		$this->assertTrue( RunnerKey::transport_allowed( false, 'local', 'forge.example.test' ) );
		$this->assertTrue( RunnerKey::transport_allowed( false, 'development', 'forge.example.test' ) );
		$this->assertTrue( RunnerKey::transport_allowed( false, 'production', '127.0.0.1' ) );
		$this->assertTrue( RunnerKey::transport_allowed( false, 'production', 'localhost' ) );
		$this->assertFalse( RunnerKey::transport_allowed( false, 'production', '127.0.0.1.example.test' ) );
	}

	/**
	 * There is deliberately no lockout: the key is 256 random bits, so it is
	 * not guessed, and a lockout would let anybody stop the runner. So the key
	 * keeps nothing about wrong guesses.
	 */
	public function test_there_is_no_lockout(): void {
		$this->assertFalse( method_exists( RunnerKey::class, 'throttled' ) );
		$this->assertFalse( method_exists( RunnerKey::class, 'failed' ) );
		$this->assertFalse( defined( RunnerKey::class . '::LIMIT' ) );
	}
}
