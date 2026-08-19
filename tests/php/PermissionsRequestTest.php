<?php
/**
 * One request, one verification.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Rest\Permissions;
use Blueworx\Forge\Rest\Signature;
use Blueworx\Forge\Sites\Registry;
use Blueworx\Forge\Sites\SecurityLog;
use PHPUnit\Framework\TestCase;

/**
 * WordPress calls a route's permission callback twice: once to decide whether
 * the request may proceed, and again afterwards, from rest_send_allow_header(),
 * purely to work out which methods to name in the Allow header.
 *
 * Our callback consumes a single-use nonce, so the second call was refused as a
 * replay and written to the security log. The request itself worked; the log
 * filled with refusals that never happened, which is worse than useless — a log
 * of false alarms is one nobody reads when a real one arrives.
 */
final class PermissionsRequestTest extends TestCase {

	/**
	 * The site under test.
	 *
	 * @var array{site_id: string, key: string}
	 */
	private array $site;

	/**
	 * Registers a fresh site and clears the stores.
	 */
	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_options']    = array();
		$GLOBALS['bwx_forge_test_transients'] = array();
		$GLOBALS['bwx_forge_test_now']        = 1000000;

		$this->site = Registry::register( 'Acme', 'https://acme.example' );
	}

	/**
	 * Builds a request signed as the registered site would sign it.
	 *
	 * @param string $nonce Nonce to sign with.
	 * @return WP_REST_Request
	 */
	private function signed_request( string $nonce = 'nonce-1' ): WP_REST_Request {
		$route     = '/blueworx-forge/v1/client/handshake';
		$timestamp = (string) $GLOBALS['bwx_forge_test_now'];

		return new WP_REST_Request(
			array(
				Signature::HEADER_SITE      => $this->site['site_id'],
				Signature::HEADER_TIMESTAMP => $timestamp,
				Signature::HEADER_NONCE     => $nonce,
				Signature::HEADER_SIGNATURE => Signature::sign(
					$this->site['key'],
					$this->site['site_id'],
					'GET',
					$route,
					$timestamp,
					$nonce,
					''
				),
			),
			'GET',
			$route,
			''
		);
	}

	/**
	 * Asked twice about the same request, the answer is the same both times.
	 */
	public function test_the_same_request_is_not_refused_the_second_time_it_is_asked_about(): void {
		$request = $this->signed_request();

		$this->assertTrue( Permissions::client_site( $request ) );
		$this->assertTrue(
			Permissions::client_site( $request ),
			'the second look at one request was refused — that is the Allow header check, not a replay'
		);
	}

	/**
	 * And it leaves nothing in the security log.
	 */
	public function test_asking_twice_logs_nothing(): void {
		$request = $this->signed_request();

		Permissions::client_site( $request );
		Permissions::client_site( $request );

		$this->assertSame( array(), SecurityLog::recent() );
	}

	/**
	 * A genuine replay — the same nonce on a *different* request — is still
	 * refused. This is the property the fix must not cost us.
	 */
	public function test_the_same_nonce_on_another_request_is_still_refused(): void {
		$this->assertTrue( Permissions::client_site( $this->signed_request( 'nonce-shared' ) ) );

		$replay = Permissions::client_site( $this->signed_request( 'nonce-shared' ) );

		$this->assertInstanceOf( WP_Error::class, $replay );
		$this->assertSame( 'replayed_request', SecurityLog::recent()[0]['reason'] );
	}

	/**
	 * A refusal is remembered as a refusal, not quietly upgraded.
	 */
	public function test_a_refused_request_stays_refused_when_asked_again(): void {
		$request = new WP_REST_Request( array(), 'GET', '/blueworx-forge/v1/client/handshake', '' );

		$this->assertInstanceOf( WP_Error::class, Permissions::client_site( $request ) );
		$this->assertInstanceOf( WP_Error::class, Permissions::client_site( $request ) );

		$this->assertCount( 1, SecurityLog::recent(), 'one refused request was logged twice' );
	}
}
