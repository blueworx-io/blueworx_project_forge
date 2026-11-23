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
	 * A fresh key, over https.
	 */
	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_options']     = array();
		$GLOBALS['bwx_forge_test_transients']  = array();
		$GLOBALS['bwx_forge_test_now']         = 1000000;
		$GLOBALS['bwx_forge_test_ssl']         = true;
		$GLOBALS['bwx_forge_test_environment'] = 'production';
		$GLOBALS['bwx_forge_test_home']        = 'https://studio.example.test';

		$this->key = RunnerKey::make( 1 );
	}

	/**
	 * Clears what this test staged.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['bwx_forge_test_ssl'], $GLOBALS['bwx_forge_test_environment'], $GLOBALS['bwx_forge_test_home'] );
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
	 * Wrong keys write nothing, however many there are, and never shut the
	 * right key out: there is deliberately no lockout.
	 */
	public function test_wrong_keys_write_nothing_and_shut_nobody_out(): void {
		$options = $GLOBALS['bwx_forge_test_options'];

		for ( $i = 0; $i < 50; $i++ ) {
			Permissions::runner( $this->request( 'bwxr_guess' . $i ) );
		}

		$this->assertSame( $options, $GLOBALS['bwx_forge_test_options'] );
		$this->assertSame( array(), $GLOBALS['bwx_forge_test_transients'] );
		$this->assertTrue( Permissions::runner( $this->request( $this->key ) ) );
	}

	/**
	 * WordPress asks the same request twice, the second time for the Allow
	 * header. The first answer stands.
	 */
	public function test_one_request_is_answered_once(): void {
		$request = $this->request( $this->key );

		$this->assertTrue( Permissions::runner( $request ) );

		RunnerKey::forget();

		$this->assertTrue( Permissions::runner( $request ), 'the same request keeps its answer' );
		$this->assertInstanceOf( WP_Error::class, Permissions::runner( $this->request( $this->key ) ), 'a new request is asked afresh' );
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
