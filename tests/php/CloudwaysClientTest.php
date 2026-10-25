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
 * #621, #645. Nothing here reaches Cloudways: the answers are queued in the
 * bootstrap's HTTP stub, in the order Forge makes the calls.
 */
final class CloudwaysClientTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_http']          = array();
		$GLOBALS['bwx_forge_test_http_requests'] = array();
		unset( $GLOBALS['bwx_forge_test_options'][ Settings::OPTION ] );
		Settings::save( 'the-access-token-1234' );
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

	public function test_servers_are_read_with_the_saved_token_and_their_apps_addresses_collected(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 200, $this->servers() );

		$servers = Client::servers();

		// #645. One call, the access token sent as it is: no exchange first.
		$this->assertCount( 1, $GLOBALS['bwx_forge_test_http_requests'] );
		$this->assertSame( 'https://api.cloudways.com/api/v2/server', $GLOBALS['bwx_forge_test_http_requests'][0]['url'] );
		$this->assertSame( 'Bearer the-access-token-1234', $GLOBALS['bwx_forge_test_http_requests'][0]['args']['headers']['Authorization'] );
		$this->assertSame( '101', $servers[0]['id'] );
		$this->assertSame( 'London 1', $servers[0]['label'] );
		$this->assertSame( 'running', $servers[0]['status'] );
		$this->assertSame( 'Acme', $servers[0]['apps'][0]['label'] );
		$this->assertSame( array( 'www.acme.test', 'wordpress-101-9.cloudwaysapps.com', 'acme.test' ), $servers[0]['apps'][0]['domains'] );
	}

	public function test_a_token_being_connected_is_checked_with_cloudways_before_it_is_saved(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 200, $this->servers() );

		$this->assertIsArray( Client::servers( 'a-new-token-5678' ) );
		$this->assertSame( 'Bearer a-new-token-5678', $GLOBALS['bwx_forge_test_http_requests'][0]['args']['headers']['Authorization'] );
	}

	public function test_a_refused_token_is_denied_without_repeating_it(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 401, array( 'error' => 'unauthorised' ) );

		$answer = Client::servers();

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( Client::DENIED, $answer->get_error_code() );
		$this->assertStringContainsString( 'access token', $answer->get_error_message() );
		$this->assertStringContainsString( 'expired', $answer->get_error_message() );
		$this->assertStringNotContainsString( 'the-access-token', $answer->get_error_message() );
		$this->assertCount( 1, $GLOBALS['bwx_forge_test_http_requests'] );
	}

	public function test_nothing_is_asked_when_cloudways_is_not_connected(): void {
		Settings::forget();

		$answer = Client::servers();

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( array(), $GLOBALS['bwx_forge_test_http_requests'] );
	}

	public function test_cloudways_out_of_reach_is_a_failure(): void {
		$GLOBALS['bwx_forge_test_http'][] = new WP_Error( 'http_request_failed', 'cURL error 28' );

		$answer = Client::servers();

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( Client::FAILED, $answer->get_error_code() );
	}
}
