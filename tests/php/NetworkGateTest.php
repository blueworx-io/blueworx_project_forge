<?php
/**
 * The check every call from the studio to the network must pass.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

require_once __DIR__ . '/support/multisite-stubs.php';

use Blueworx\Forge\Client\Network\Gate;
use Blueworx\Forge\Client\Network\Key;
use Blueworx\Forge\Client\Signer;
use PHPUnit\Framework\TestCase;

/**
 * #621. The create-site routes refuse anything the studio did not sign with
 * the network's key, anything signed more than five minutes either side of
 * now, and anything already seen once.
 */
final class NetworkGateTest extends TestCase {

	private const KEY  = 'network-key-0123456789abcdef0123456789abcdef';
	private const PATH = '/blueworx-forge-client/v1/network/sites';

	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_site_options']    = array();
		$GLOBALS['bwx_forge_test_site_transients'] = array();
		$GLOBALS['bwx_forge_client_test_now']      = 2000000;
		update_site_option( Key::OPTION, self::KEY );
	}

	/**
	 * Headers the studio would send.
	 *
	 * @param string $body      The body.
	 * @param string $key       The key it signs with.
	 * @param int    $timestamp When it was signed.
	 * @param string $nonce     The nonce.
	 * @return array<string, string>
	 */
	private function signed( string $body, string $key = self::KEY, int $timestamp = 2000000, string $nonce = 'nonce-1' ): array {
		return array(
			'site'      => Gate::SITE,
			'timestamp' => (string) $timestamp,
			'nonce'     => $nonce,
			'signature' => Signer::sign( $key, Gate::SITE, 'POST', self::PATH, (string) $timestamp, $nonce, $body ),
		);
	}

	public function test_a_call_signed_with_the_network_key_passes(): void {
		$this->assertTrue( Gate::verify( 'POST', self::PATH, '{"a":1}', $this->signed( '{"a":1}' ) ) );
	}

	public function test_a_call_signed_with_another_key_is_refused(): void {
		$this->assertFalse( Gate::verify( 'POST', self::PATH, '{"a":1}', $this->signed( '{"a":1}', 'some-other-key' ) ) );
	}

	public function test_a_changed_body_is_refused(): void {
		$this->assertFalse( Gate::verify( 'POST', self::PATH, '{"a":2}', $this->signed( '{"a":1}' ) ) );
	}

	public function test_a_signature_for_another_route_is_refused(): void {
		$this->assertFalse( Gate::verify( 'POST', '/blueworx-forge-client/v1/network/sites/4/people', '{"a":1}', $this->signed( '{"a":1}' ) ) );
	}

	public function test_a_call_signed_as_a_client_site_is_refused(): void {
		$headers         = $this->signed( '{}' );
		$headers['site'] = 'site_abc';
		$this->assertFalse( Gate::verify( 'POST', self::PATH, '{}', $headers ) );
	}

	public function test_a_call_outside_five_minutes_is_refused_either_way(): void {
		$this->assertFalse( Gate::verify( 'POST', self::PATH, '{}', $this->signed( '{}', self::KEY, 2000000 - 301 ) ) );
		$this->assertFalse( Gate::verify( 'POST', self::PATH, '{}', $this->signed( '{}', self::KEY, 2000000 + 301, 'nonce-2' ) ) );
	}

	public function test_a_replayed_call_is_refused(): void {
		$headers = $this->signed( '{}' );

		$this->assertTrue( Gate::verify( 'POST', self::PATH, '{}', $headers ) );
		$this->assertFalse( Gate::verify( 'POST', self::PATH, '{}', $headers ) );
	}

	public function test_a_refused_call_does_not_use_up_its_nonce(): void {
		$bad = $this->signed( '{}', 'wrong-key' );
		$this->assertFalse( Gate::verify( 'POST', self::PATH, '{}', $bad ) );

		$this->assertTrue( Gate::verify( 'POST', self::PATH, '{}', $this->signed( '{}' ) ) );
	}

	public function test_with_no_key_set_everything_is_refused(): void {
		$GLOBALS['bwx_forge_test_site_options'] = array();

		$this->assertFalse( Gate::verify( 'POST', self::PATH, '{}', $this->signed( '{}', '' ) ) );
	}

	public function test_missing_headers_are_refused(): void {
		$this->assertFalse( Gate::verify( 'POST', self::PATH, '{}', array() ) );
	}

	public function test_a_generated_key_is_long_random_and_kept(): void {
		$key = Key::generate();

		$this->assertSame( 64, strlen( $key ) );
		$this->assertSame( $key, Key::get() );
		$this->assertNotSame( $key, Key::generate() );
	}
}
