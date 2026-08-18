<?php
/**
 * Signed request verification.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Rest\Signature;
use Blueworx\Forge\Sites\Registry;
use Blueworx\Forge\Sites\SecurityLog;
use PHPUnit\Framework\TestCase;

/**
 * ARCH-6: requests are signed and carry a timestamp and nonce, so a captured
 * request cannot be replayed.
 *
 * Each test here is one way a real attacker gets in — a replayed request, a
 * tampered body, a key from another site, a revoked key still in someone's
 * config — rather than one branch of the function.
 */
final class SignatureTest extends TestCase {

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
		$GLOBALS['bwx_forge_test_actions']    = array();
		$GLOBALS['bwx_forge_test_now']        = 1000000;

		$this->site = Registry::register( 'Acme', 'https://acme.example' );
	}

	/**
	 * Builds a valid set of signed headers.
	 *
	 * @param array<string, mixed> $overrides Anything to change.
	 * @return array<string, string>
	 */
	private function headers( array $overrides = array() ): array {
		$defaults = array(
			'site_id'   => $this->site['site_id'],
			'method'    => 'GET',
			'path'      => '/blueworx-forge/v1/client/handshake',
			'timestamp' => (string) $GLOBALS['bwx_forge_test_now'],
			'nonce'     => 'nonce-' . bin2hex( random_bytes( 8 ) ),
			'body'      => '',
			'key'       => $this->site['key'],
		);

		$parts = array_merge( $defaults, $overrides );

		return array(
			'site_id'   => $parts['site_id'],
			'timestamp' => $parts['timestamp'],
			'nonce'     => $parts['nonce'],
			'signature' => Signature::sign(
				$parts['key'],
				$parts['site_id'],
				$parts['method'],
				$parts['path'],
				$parts['timestamp'],
				$parts['nonce'],
				$parts['body']
			),
			'method'    => $parts['method'],
			'path'      => $parts['path'],
			'body'      => $parts['body'],
		);
	}

	/**
	 * Runs verification against a header set.
	 *
	 * @param array<string, string> $headers Headers.
	 * @return true|\WP_Error
	 */
	private function verify( array $headers ) {
		return Signature::verify(
			$headers['site_id'],
			$headers['signature'],
			$headers['timestamp'],
			$headers['nonce'],
			$headers['method'],
			$headers['path'],
			$headers['body']
		);
	}

	/**
	 * A registered site's signed request succeeds.
	 */
	public function test_a_correctly_signed_request_is_accepted(): void {
		$this->assertTrue( $this->verify( $this->headers() ) );
	}

	/**
	 * The same request sent twice is refused the second time. This is the whole
	 * point of the nonce: an attacker who captures a valid request cannot use it.
	 */
	public function test_a_replayed_request_is_refused(): void {
		$headers = $this->headers();

		$this->assertTrue( $this->verify( $headers ) );

		$replayed = $this->verify( $headers );

		$this->assertInstanceOf( WP_Error::class, $replayed );
		$this->assertSame( 'bwx_forge_unauthenticated', $replayed->get_error_code(), 'the response must not say which check failed' );
		$this->assertSame( 'replayed_request', SecurityLog::recent()[0]['reason'], 'the log must say exactly which check failed' );
	}

	/**
	 * A request older than the window is refused even with a fresh nonce, so a
	 * captured request cannot be held and used later.
	 */
	public function test_a_stale_timestamp_is_refused(): void {
		$old = (string) ( $GLOBALS['bwx_forge_test_now'] - Signature::WINDOW_SECONDS - 1 );

		$error = $this->verify( $this->headers( array( 'timestamp' => $old ) ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'bwx_forge_unauthenticated', $error->get_error_code(), 'the response must not say which check failed' );
		$this->assertSame( 'stale_request', SecurityLog::recent()[0]['reason'], 'the log must say exactly which check failed' );
	}

	/**
	 * A timestamp far in the future is refused too — otherwise a captured request
	 * dated next year would be valid all year.
	 */
	public function test_a_future_timestamp_is_refused(): void {
		$future = (string) ( $GLOBALS['bwx_forge_test_now'] + Signature::WINDOW_SECONDS + 1 );

		$error = $this->verify( $this->headers( array( 'timestamp' => $future ) ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'bwx_forge_unauthenticated', $error->get_error_code(), 'the response must not say which check failed' );
		$this->assertSame( 'stale_request', SecurityLog::recent()[0]['reason'], 'the log must say exactly which check failed' );
	}

	/**
	 * Changing the body after signing invalidates the signature, so a captured
	 * request cannot be edited in flight.
	 */
	public function test_a_tampered_body_is_refused(): void {
		$headers         = $this->headers( array( 'body' => '{"hours":1}' ) );
		$headers['body'] = '{"hours":1000}';

		$error = $this->verify( $headers );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'bwx_forge_unauthenticated', $error->get_error_code(), 'the response must not say which check failed' );
		$this->assertSame( 'bad_signature', SecurityLog::recent()[0]['reason'], 'the log must say exactly which check failed' );
	}

	/**
	 * Changing the method or the path invalidates the signature, so a signature
	 * for a harmless read cannot be replayed onto a write.
	 */
	public function test_a_signature_cannot_be_moved_to_another_route(): void {
		$headers           = $this->headers();
		$headers['method'] = 'POST';
		$headers['path']   = '/blueworx-forge/v1/client/write';

		$this->assertInstanceOf( WP_Error::class, $this->verify( $headers ) );
	}

	/**
	 * Another site's key does not work, even though that key is perfectly valid
	 * for its own site.
	 */
	public function test_another_sites_key_is_refused(): void {
		$other = Registry::register( 'Beta', 'https://beta.example' );

		$error = $this->verify( $this->headers( array( 'key' => $other['key'] ) ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'bwx_forge_unauthenticated', $error->get_error_code(), 'the response must not say which check failed' );
		$this->assertSame( 'bad_signature', SecurityLog::recent()[0]['reason'], 'the log must say exactly which check failed' );
	}

	/**
	 * A site nobody registered is refused. There is no enrolment by turning up.
	 */
	public function test_an_unknown_site_is_refused(): void {
		$error = $this->verify( $this->headers( array( 'site_id' => 'never-registered' ) ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'bwx_forge_unauthenticated', $error->get_error_code(), 'the response must not say which check failed' );
		$this->assertSame( 'unknown_site', SecurityLog::recent()[0]['reason'], 'the log must say exactly which check failed' );
	}

	/**
	 * A revoked key stops working, which is what "can be cut off" means.
	 */
	public function test_a_revoked_key_is_refused(): void {
		$headers = $this->headers();

		Registry::revoke( $this->site['site_id'] );

		$error = $this->verify( $headers );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'bwx_forge_unauthenticated', $error->get_error_code(), 'the response must not say which check failed' );
		$this->assertSame( 'revoked_site', SecurityLog::recent()[0]['reason'], 'the log must say exactly which check failed' );
	}

	/**
	 * A rotated key stops the old one working straight away.
	 */
	public function test_the_old_key_stops_working_after_rotation(): void {
		$headers = $this->headers();

		Registry::rotate( $this->site['site_id'] );

		$this->assertInstanceOf( WP_Error::class, $this->verify( $headers ) );
	}

	/**
	 * A missing signature is refused rather than treated as unsigned-and-fine.
	 */
	public function test_an_unsigned_request_is_refused(): void {
		$headers              = $this->headers();
		$headers['signature'] = '';

		$error = $this->verify( $headers );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'bwx_forge_unauthenticated', $error->get_error_code(), 'the response must not say which check failed' );
		$this->assertSame( 'bad_signature', SecurityLog::recent()[0]['reason'], 'the log must say exactly which check failed' );
	}

	/**
	 * Every refusal is logged as a security event, with the site it claimed to be
	 * and why it was refused. ARCH-6 requires this for a revoked key; it is
	 * cheaper and more useful to log all of them.
	 */
	public function test_every_refusal_is_logged(): void {
		Registry::revoke( $this->site['site_id'] );

		$this->verify( $this->headers( array( 'key' => 'wrong' ) ) );

		$logged = SecurityLog::recent();

		$this->assertNotEmpty( $logged, 'a refused request left no trace' );
		$this->assertSame( $this->site['site_id'], $logged[0]['site_id'] );
		$this->assertSame( 'revoked_site', $logged[0]['reason'] );
	}

	/**
	 * An accepted request is not logged as a security event. A log that records
	 * everything is a log nobody reads.
	 */
	public function test_an_accepted_request_is_not_logged_as_a_security_event(): void {
		$this->verify( $this->headers() );

		$this->assertSame( array(), SecurityLog::recent() );
	}

	/**
	 * Every refusal answers with the same code, whatever went wrong. Anything
	 * more specific lets an unauthenticated caller sort real site ids from
	 * invented ones, and a revoked key from one that never worked.
	 */
	public function test_every_refusal_looks_the_same_from_outside(): void {
		$codes = array();

		$codes[] = $this->verify( $this->headers( array( 'site_id' => 'never-registered' ) ) )->get_error_code();
		$codes[] = $this->verify( $this->headers( array( 'key' => 'wrong' ) ) )->get_error_code();
		$codes[] = $this->verify( $this->headers( array( 'timestamp' => '1' ) ) )->get_error_code();

		$repeated = $this->headers();
		$this->verify( $repeated );
		$codes[] = $this->verify( $repeated )->get_error_code();

		$this->assertSame( array( 'bwx_forge_unauthenticated' ), array_values( array_unique( $codes ) ) );
	}
}
