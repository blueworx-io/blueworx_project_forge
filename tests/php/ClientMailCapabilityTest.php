<?php
/**
 * Whether a client site can send mail.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

use Blueworx\Forge\Client\Mail;
use PHPUnit\Framework\TestCase;

/**
 * NOTIF-3 sends client email through `wp_mail` on the client's own site, so the
 * studio's question is only ever whether that path exists — and it has to be
 * answerable without sending anything. A probe would either mail a real person
 * who did not ask for it, or teach the site's mail provider that we are a
 * source of bounces.
 */
final class ClientMailCapabilityTest extends TestCase {

	/**
	 * Clears the recorded failure between tests, so one test's broken site does
	 * not become the next one's.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['bwx_forge_test_options'] = array();
		$GLOBALS['bwx_forge_test_now']     = 1000000;
	}

	/**
	 * The ordinary site: mail works as far as anything here can tell.
	 */
	public function test_a_site_with_a_working_mailer_says_yes(): void {
		$capability = Mail::capability();

		$this->assertSame( 'yes', $capability['capable'] );
	}

	/**
	 * A site whose last send failed says no, and says what went wrong — that
	 * sentence is the whole value of the check to whoever has to fix it.
	 */
	public function test_a_site_whose_last_send_failed_says_no(): void {
		Mail::remember_failure( 'SMTP connect() failed' );

		$capability = Mail::capability();

		$this->assertSame( 'no', $capability['capable'] );
		$this->assertStringContainsString( 'SMTP connect() failed', $capability['detail'] );
	}

	/**
	 * A failure the site recovered from is not held against it forever: a
	 * successful send clears it.
	 */
	public function test_a_successful_send_clears_an_earlier_failure(): void {
		Mail::remember_failure( 'SMTP connect() failed' );
		Mail::remember_success();

		$this->assertSame( 'yes', Mail::capability()['capable'] );
	}

	/**
	 * Whatever the answer, nothing was sent to find it out.
	 */
	public function test_the_check_sends_nothing(): void {
		Mail::capability();

		$sent = array_filter(
			$GLOBALS['bwx_forge_test_calls'],
			static function ( array $call ): bool {
				return 'wp_mail' === $call[0];
			}
		);

		$this->assertSame( array(), $sent );
	}

	/**
	 * The detail is bounded. It lands in a varchar(191), and a mailer that
	 * returns a page of SMTP transcript must not be able to fail the write.
	 */
	public function test_the_detail_is_short_enough_to_store(): void {
		Mail::remember_failure( str_repeat( 'x', 500 ) );

		$this->assertLessThanOrEqual( 191, strlen( Mail::capability()['detail'] ) );
	}
}
