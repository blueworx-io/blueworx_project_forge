<?php
/**
 * The calls Forge makes to Google, and how it reads the answers.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Google\Client;
use Blueworx\Forge\Google\Settings;
use PHPUnit\Framework\TestCase;

/**
 * #606. Nothing here reaches Google: the requests are the queued stubs in
 * the bootstrap, so a test says what Google answered and reads what Forge
 * sent.
 */
final class GoogleClientTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_http']          = array();
		$GLOBALS['bwx_forge_test_http_requests'] = array();
		Settings::save( 'client-123', 'the-secret' );
	}

	/**
	 * A response in WordPress's shape.
	 *
	 * @param int                  $code HTTP status.
	 * @param array<string, mixed> $body JSON body.
	 * @return array<string, mixed>
	 */
	private function answer( int $code, array $body ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => (string) wp_json_encode( $body ),
		);
	}

	public function test_a_code_is_exchanged_with_the_secret_and_the_exact_redirect(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer(
			200,
			array(
				'access_token'  => 'at-1',
				'refresh_token' => 'rt-1',
				'expires_in'    => 3600,
			)
		);

		$tokens = Client::exchange( 'the-code' );

		$this->assertSame( 'at-1', $tokens['access_token'] );
		$this->assertSame( 'rt-1', $tokens['refresh_token'] );
		$this->assertSame( bwx_forge_now() + 3600, $tokens['expires_at'] );

		$sent = $GLOBALS['bwx_forge_test_http_requests'][0];
		$this->assertSame( 'https://oauth2.googleapis.com/token', $sent['url'] );
		$this->assertSame( 'authorization_code', $sent['args']['body']['grant_type'] );
		$this->assertSame( 'the-code', $sent['args']['body']['code'] );
		$this->assertSame( 'the-secret', $sent['args']['body']['client_secret'] );
		$this->assertSame( Settings::redirect_uri(), $sent['args']['body']['redirect_uri'] );
	}

	public function test_an_exchange_without_a_refresh_token_is_refused(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer(
			200,
			array(
				'access_token' => 'at-1',
				'expires_in'   => 3600,
			)
		);

		$this->assertInstanceOf( WP_Error::class, Client::exchange( 'the-code' ) );
	}

	public function test_a_withdrawn_grant_is_reported_as_denied(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 400, array( 'error' => 'invalid_grant' ) );

		$refreshed = Client::refresh( 'rt-1' );

		$this->assertInstanceOf( WP_Error::class, $refreshed );
		$this->assertSame( Client::DENIED, $refreshed->get_error_code() );
	}

	public function test_a_refresh_keeps_the_refresh_token_google_did_not_resend(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer(
			200,
			array(
				'access_token' => 'at-2',
				'expires_in'   => 3600,
			)
		);

		$tokens = Client::refresh( 'rt-1' );

		$this->assertSame( 'at-2', $tokens['access_token'] );
		$this->assertSame( 'rt-1', $tokens['refresh_token'] );
		$this->assertSame( 'refresh_token', $GLOBALS['bwx_forge_test_http_requests'][0]['args']['body']['grant_type'] );
	}

	public function test_events_are_read_with_the_access_token(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer(
			200,
			array(
				'items'         => array( array( 'id' => 'e1' ) ),
				'nextSyncToken' => 'st-1',
			)
		);

		$page = Client::events( 'at-1', array( 'syncToken' => 'st-0' ) );

		$this->assertSame( 'e1', $page['items'][0]['id'] );
		$this->assertSame( 'st-1', $page['nextSyncToken'] );

		$sent = $GLOBALS['bwx_forge_test_http_requests'][0];
		$this->assertStringStartsWith( 'https://www.googleapis.com/calendar/v3/calendars/primary/events?', $sent['url'] );
		$this->assertStringContainsString( 'syncToken=st-0', $sent['url'] );
		$this->assertSame( 'Bearer at-1', $sent['args']['headers']['Authorization'] );
	}

	public function test_an_expired_sync_token_is_reported_as_gone(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 410, array( 'error' => array( 'code' => 410 ) ) );

		$this->assertSame( Client::GONE, Client::events( 'at-1', array( 'syncToken' => 'old' ) )->get_error_code() );
	}

	public function test_an_unauthorised_read_is_reported_as_denied(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 401, array( 'error' => array( 'code' => 401 ) ) );

		$this->assertSame( Client::DENIED, Client::events( 'at-1', array() )->get_error_code() );
	}

	public function test_the_primary_calendars_address_is_the_connected_email(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 200, array( 'id' => 'me@studio.test' ) );

		$this->assertSame( 'me@studio.test', Client::primary_email( 'at-1' ) );
		$this->assertSame( 'https://www.googleapis.com/calendar/v3/calendars/primary', $GLOBALS['bwx_forge_test_http_requests'][0]['url'] );
	}
}
