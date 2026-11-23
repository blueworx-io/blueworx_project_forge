<?php
/**
 * The runner key: made, kept as a hash, checked, and throttled.
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
	 * Ten wrong keys from one address in ten minutes, and that address is
	 * refused until the ten minutes are up. Another address is not.
	 */
	public function test_ten_wrong_keys_in_ten_minutes_shut_the_address_out(): void {
		$now = 1000000;

		for ( $i = 0; $i < 9; $i++ ) {
			RunnerKey::failed( '203.0.113.5', $now + $i );
		}

		$this->assertFalse( RunnerKey::throttled( '203.0.113.5', $now + 10 ) );

		RunnerKey::failed( '203.0.113.5', $now + 20 );

		$this->assertTrue( RunnerKey::throttled( '203.0.113.5', $now + 21 ) );
		$this->assertTrue( RunnerKey::throttled( '203.0.113.5', $now + 599 ) );
		$this->assertFalse( RunnerKey::throttled( '198.51.100.9', $now + 21 ), 'another address is not shut out' );
		$this->assertFalse( RunnerKey::throttled( '203.0.113.5', $now + 600 ), 'the window ends' );
	}

	/**
	 * A failure after the window starts a new one rather than adding to the old.
	 */
	public function test_a_new_window_starts_after_the_old_one_ends(): void {
		for ( $i = 0; $i < 9; $i++ ) {
			RunnerKey::failed( '203.0.113.5', 1000000 );
		}

		RunnerKey::failed( '203.0.113.5', 1000700 );

		$this->assertFalse( RunnerKey::throttled( '203.0.113.5', 1000701 ) );
	}

	/**
	 * The count belongs to the key it was guessing at. Making a new key, which
	 * only an administrator can, starts every count again.
	 */
	public function test_a_new_key_starts_the_count_again(): void {
		RunnerKey::make( 7 );

		for ( $i = 0; $i < 10; $i++ ) {
			RunnerKey::failed( '203.0.113.5', 1000000 );
		}

		$this->assertTrue( RunnerKey::throttled( '203.0.113.5', 1000001 ) );

		$GLOBALS['bwx_forge_test_now'] = 1000001;
		RunnerKey::make( 7 );

		$this->assertFalse( RunnerKey::throttled( '203.0.113.5', 1000002 ) );
	}

	/**
	 * Turning the key off and on does not.
	 */
	public function test_turning_the_key_off_and_on_keeps_the_count(): void {
		RunnerKey::make( 7 );

		for ( $i = 0; $i < 10; $i++ ) {
			RunnerKey::failed( '203.0.113.5', 1000000 );
		}

		RunnerKey::set_enabled( false );
		RunnerKey::set_enabled( true );

		$this->assertTrue( RunnerKey::throttled( '203.0.113.5', 1000001 ) );
	}

	/**
	 * The address is never kept as it came: the transient is named by a hash.
	 */
	public function test_the_address_is_not_kept_in_the_clear(): void {
		RunnerKey::failed( '203.0.113.5', 1000000 );

		$names = implode( ' ', array_keys( $GLOBALS['bwx_forge_test_transients'] ) );

		$this->assertStringNotContainsString( '203.0.113.5', $names );
		$this->assertStringStartsWith( 'bwx_forge_runner_fail_', $names );
	}
}
