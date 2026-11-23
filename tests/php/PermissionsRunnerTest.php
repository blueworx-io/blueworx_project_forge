<?php
/**
 * The runner key, asked at the door of the runner's own routes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Rest\Permissions;
use Blueworx\Forge\Tenancy\RunnerKey;
use PHPUnit\Framework\TestCase;

/**
 * Permissions::runner() is the only thing that accepts the runner key, and it
 * sits on the runner's routes alone. A wrong, missing or turned-off key all
 * get the same refusal, so a caller learns nothing about which it was.
 */
final class PermissionsRunnerTest extends TestCase {

	/**
	 * The key made for each test.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * A fresh key, over https, from one address.
	 */
	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_options']     = array();
		$GLOBALS['bwx_forge_test_transients']  = array();
		$GLOBALS['bwx_forge_test_now']         = 1000000;
		$GLOBALS['bwx_forge_test_ssl']         = true;
		$GLOBALS['bwx_forge_test_environment'] = 'production';
		$GLOBALS['bwx_forge_test_home']        = 'https://studio.example.test';
		$_SERVER['REMOTE_ADDR']                = '203.0.113.5';

		$this->key = RunnerKey::make( 1 );
	}

	/**
	 * Clears what this test staged.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['bwx_forge_test_ssl'], $GLOBALS['bwx_forge_test_environment'], $GLOBALS['bwx_forge_test_home'], $_SERVER['REMOTE_ADDR'] );
	}

	/**
	 * A request carrying a key in the runner's header.
	 *
	 * @param string $key The key sent; empty sends no header.
	 * @return WP_REST_Request
	 */
	private function request( string $key ): WP_REST_Request {
		return new WP_REST_Request( '' === $key ? array() : array( RunnerKey::HEADER => $key ), 'GET', '/blueworx-forge/v1/runner/cards' );
	}

	/**
	 * The right key is let in, and noted as used.
	 */
	public function test_the_right_key_is_let_in(): void {
		$this->assertTrue( Permissions::runner( $this->request( $this->key ) ) );
		$this->assertSame( 1000000, RunnerKey::status()['last_used_at'] );
	}

	/**
	 * Wrong, missing and turned off are one answer, word for word.
	 */
	public function test_every_refusal_is_the_same_refusal(): void {
		$wrong   = Permissions::runner( $this->request( $this->key . 'x' ) );
		$missing = Permissions::runner( $this->request( '' ) );

		RunnerKey::set_enabled( false );
		$off = Permissions::runner( $this->request( $this->key ) );

		RunnerKey::forget();
		$gone = Permissions::runner( $this->request( $this->key ) );

		foreach ( array( $wrong, $missing, $off, $gone ) as $refusal ) {
			$this->assertInstanceOf( WP_Error::class, $refusal );
			$this->assertSame( $wrong->get_error_code(), $refusal->get_error_code() );
			$this->assertSame( $wrong->get_error_message(), $refusal->get_error_message() );
			$this->assertSame( $wrong->get_error_data(), $refusal->get_error_data() );
		}

		$this->assertSame( 401, $wrong->get_error_data()['status'] );
	}

	/**
	 * The refusal says nothing about why.
	 */
	public function test_the_refusal_gives_no_hint(): void {
		$message = strtolower( Permissions::runner( $this->request( 'bwxr_nope' ) )->get_error_message() );

		foreach ( array( 'wrong', 'missing', 'off', 'disabled', 'expired', 'exist' ) as $hint ) {
			$this->assertStringNotContainsString( $hint, $message );
		}
	}

	/**
	 * Over plain http on a real site the key is refused before it is even
	 * looked at, so a runner set up wrongly fails loudly.
	 */
	public function test_plain_http_is_refused_on_a_real_site(): void {
		$GLOBALS['bwx_forge_test_ssl'] = false;

		$refused = Permissions::runner( $this->request( $this->key ) );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 0, RunnerKey::status()['last_used_at'] );
		$this->assertSame( array(), $GLOBALS['bwx_forge_test_transients'], 'not counted as a wrong key' );
	}

	/**
	 * A local site takes the key over plain http.
	 */
	public function test_a_local_site_takes_plain_http(): void {
		$GLOBALS['bwx_forge_test_ssl']  = false;
		$GLOBALS['bwx_forge_test_home'] = 'http://127.0.0.1:8892';

		$this->assertTrue( Permissions::runner( $this->request( $this->key ) ) );
	}

	/**
	 * Ten wrong keys, and the right one is refused too until the window ends.
	 */
	public function test_ten_wrong_keys_shut_the_address_out(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			Permissions::runner( $this->request( 'bwxr_guess' . $i ) );
		}

		$refused = Permissions::runner( $this->request( $this->key ) );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( Permissions::runner( $this->request( 'bwxr_other' ) )->get_error_message(), $refused->get_error_message() );

		$GLOBALS['bwx_forge_test_now'] = 1000000 + 601;
		$this->assertTrue( Permissions::runner( $this->request( $this->key ) ) );
	}

	/**
	 * WordPress asks the same request twice, the second time for the Allow
	 * header. One wrong key is one failure, not two.
	 */
	public function test_one_request_asked_twice_is_one_failure(): void {
		$request = $this->request( 'bwxr_guess' );

		for ( $i = 0; $i < 9; $i++ ) {
			Permissions::runner( $this->request( 'bwxr_guess' . $i ) );
		}

		Permissions::runner( $request );
		Permissions::runner( $request );

		$this->assertTrue( RunnerKey::throttled( '203.0.113.5', 1000001 ) );

		$GLOBALS['bwx_forge_test_transients'] = array();

		for ( $i = 0; $i < 8; $i++ ) {
			Permissions::runner( $this->request( 'bwxr_again' . $i ) );
		}

		$twice = $this->request( 'bwxr_twice' );
		Permissions::runner( $twice );
		Permissions::runner( $twice );

		$this->assertFalse( RunnerKey::throttled( '203.0.113.5', 1000001 ), 'nine failures, though one was asked twice' );
	}

	/**
	 * The runner's routes are outside the Forge door: the key is not a login,
	 * and the door asks for one.
	 */
	public function test_the_runner_is_outside_the_door(): void {
		$this->assertFalse( Permissions::behind_the_door( array( Permissions::class, 'runner' ) ) );
	}

	/**
	 * The key opens no door that asks for a person. With the right key and no
	 * login, the door every other route sits behind stays shut.
	 */
	public function test_the_key_does_not_open_the_forge_door(): void {
		$GLOBALS['bwx_forge_test_can'] = array();

		$door = Permissions::behind_door( '__return_true' );

		$this->assertFalse( $door( $this->request( $this->key ) ) );
		$this->assertFalse( Permissions::manage() );
	}
}
