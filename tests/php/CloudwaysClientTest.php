<?php
/**
 * The calls Forge makes to Cloudways.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Cloudways\Client;
use Blueworx\Forge\Cloudways\Settings;
use PHPUnit\Framework\TestCase;

/**
 * #621. Nothing here reaches Cloudways: the answers are queued in the
 * bootstrap's HTTP stub, in the order Forge makes the calls.
 */
final class CloudwaysClientTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_http']          = array();
		$GLOBALS['bwx_forge_test_http_requests'] = array();
		$GLOBALS['bwx_forge_test_transients']    = array();
		unset( $GLOBALS['bwx_forge_test_options'][ Settings::OPTION ] );
		Settings::save( 'ops@studio.test', 'the-api-key-1234' );
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

	/**
	 * A server list, as Cloudways sends it.
	 *
	 * @return array<string, mixed>
	 */
	private function servers(): array {
		return array(
			'status'  => true,
			'servers' => array(
				array(
					'id'     => '101',
					'label'  => 'London 1',
					'status' => 'running',
					'apps'   => array(
						array(
							'id'       => '9',
							'label'    => 'Acme',
							'app_fqdn' => 'wordpress-101-9.cloudwaysapps.com',
							'cname'    => 'www.acme.test',
							'aliases'  => array( 'acme.test' ),
						),
					),
				),
			),
		);
	}

	public function test_a_token_is_asked_for_with_the_email_and_key(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer(
			200,
			array(
				'access_token' => 'tok-1',
				'expires_in'   => 3600,
			)
		);

		$this->assertSame( 'tok-1', Client::token( 'ops@studio.test', 'the-api-key-1234' ) );

		$sent = $GLOBALS['bwx_forge_test_http_requests'][0];
		$this->assertSame( Client::API . '/oauth/access_token', $sent['url'] );
		$this->assertSame( 'ops@studio.test', $sent['args']['body']['email'] );
		$this->assertSame( 'the-api-key-1234', $sent['args']['body']['api_key'] );
	}

	public function test_a_refused_key_is_denied_without_repeating_the_key(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 401, array( 'error' => 'invalid_credentials' ) );

		$answer = Client::token( 'ops@studio.test', 'the-api-key-1234' );

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( Client::DENIED, $answer->get_error_code() );
		$this->assertStringNotContainsString( 'the-api-key', $answer->get_error_message() );
	}

	public function test_servers_are_read_with_the_token_and_their_apps_addresses_collected(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer(
			200,
			array(
				'access_token' => 'tok-1',
				'expires_in'   => 3600,
			)
		);
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 200, $this->servers() );

		$servers = Client::servers();

		$this->assertSame( 'Bearer tok-1', $GLOBALS['bwx_forge_test_http_requests'][1]['args']['headers']['Authorization'] );
		$this->assertSame( Client::API . '/server', $GLOBALS['bwx_forge_test_http_requests'][1]['url'] );
		$this->assertSame( '101', $servers[0]['id'] );
		$this->assertSame( 'London 1', $servers[0]['label'] );
		$this->assertSame( 'running', $servers[0]['status'] );
		$this->assertSame( 'Acme', $servers[0]['apps'][0]['label'] );
		$this->assertSame( array( 'www.acme.test', 'wordpress-101-9.cloudwaysapps.com', 'acme.test' ), $servers[0]['apps'][0]['domains'] );
	}

	public function test_the_token_is_kept_sealed_and_reused(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer(
			200,
			array(
				'access_token' => 'tok-1',
				'expires_in'   => 3600,
			)
		);
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 200, $this->servers() );
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 200, $this->servers() );

		Client::servers();
		Client::servers();

		$this->assertCount( 3, $GLOBALS['bwx_forge_test_http_requests'] );
		$this->assertStringNotContainsString( 'tok-1', (string) wp_json_encode( get_transient( Settings::TOKEN ) ) );
	}

	public function test_an_expired_token_is_asked_for_again_once(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer(
			200,
			array(
				'access_token' => 'tok-1',
				'expires_in'   => 3600,
			)
		);
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 401, array() );
		$GLOBALS['bwx_forge_test_http'][] = $this->answer(
			200,
			array(
				'access_token' => 'tok-2',
				'expires_in'   => 3600,
			)
		);
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 200, $this->servers() );

		$servers = Client::servers();

		$this->assertIsArray( $servers );
		$this->assertSame( 'Bearer tok-2', $GLOBALS['bwx_forge_test_http_requests'][3]['args']['headers']['Authorization'] );
	}

	public function test_cloudways_out_of_reach_is_a_failure(): void {
		$GLOBALS['bwx_forge_test_http'][] = new WP_Error( 'http_request_failed', 'cURL error 28' );

		$answer = Client::servers();

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( Client::FAILED, $answer->get_error_code() );
	}
}
