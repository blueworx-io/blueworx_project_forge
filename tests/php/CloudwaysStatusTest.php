<?php
/**
 * What Forge last knew about the studio's servers.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Cloudways\Address;
use Blueworx\Forge\Cloudways\Settings;
use Blueworx\Forge\Cloudways\Status;
use PHPUnit\Framework\TestCase;

/**
 * #621. Asked only when somebody looks, never twice in a few seconds, and
 * each application matched to its client by address.
 */
final class CloudwaysStatusTest extends TestCase {

	/**
	 * The client sites the matching is done against.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $sites = array(
		array(
			'id'   => 'cst_acme',
			'name' => 'Acme',
			'url'  => 'https://acme.test/',
		),
	);

	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_http']          = array();
		$GLOBALS['bwx_forge_test_http_requests'] = array();
		$GLOBALS['bwx_forge_test_transients']    = array();
		$GLOBALS['bwx_forge_test_now']           = 1000000;
		unset( $GLOBALS['bwx_forge_test_options'][ Settings::OPTION ], $GLOBALS['bwx_forge_test_options'][ Settings::STATUS ] );
		Settings::save( 'ops@studio.test', 'the-api-key-1234' );
	}

	/**
	 * Queues a token and a server list.
	 *
	 * @param string $state What Cloudways says the server is doing.
	 */
	private function cloudways_says( string $state ): void {
		$GLOBALS['bwx_forge_test_http'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode(
				array(
					'access_token' => 'tok',
					'expires_in'   => 3600,
				)
			),
		);
		$this->servers_say( $state );
	}

	/**
	 * Queues a server list alone, for when the token is already held.
	 *
	 * @param string $state What Cloudways says the server is doing.
	 */
	private function servers_say( string $state ): void {
		$GLOBALS['bwx_forge_test_http'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode(
				array(
					'servers' => array(
						array(
							'id'     => '101',
							'label'  => 'London 1',
							'status' => $state,
							'apps'   => array(
								array(
									'label' => 'Acme',
									'cname' => 'WWW.Acme.test',
								),
								array(
									'label'    => 'Staging',
									'app_fqdn' => 'wordpress-101-2.cloudwaysapps.com',
								),
							),
						),
					),
				)
			),
		);
	}

	public function test_an_address_is_its_host_without_scheme_www_port_or_path(): void {
		$this->assertSame( 'acme.test', Address::host( 'https://www.Acme.test:8443/shop?x=1' ) );
		$this->assertSame( 'acme.test', Address::host( 'acme.test' ) );
		$this->assertSame( '', Address::host( '' ) );
	}

	public function test_a_check_lists_servers_and_matches_applications_to_client_sites(): void {
		$this->cloudways_says( 'running' );

		$status = Status::read( false, $this->sites );

		$this->assertSame( 1000000, $status['checked_at'] );
		$this->assertSame( '', $status['error'] );
		$this->assertSame( 'running', $status['servers'][0]['state'] );
		$this->assertSame( 'cst_acme', $status['servers'][0]['apps'][0]['client_site_id'] );
		$this->assertSame( 'Acme', $status['servers'][0]['apps'][0]['site_name'] );
		$this->assertSame( '', $status['servers'][0]['apps'][1]['client_site_id'] );
	}

	public function test_opening_the_page_again_within_five_minutes_does_not_ask_cloudways(): void {
		$this->cloudways_says( 'running' );
		Status::read( false, $this->sites );

		$GLOBALS['bwx_forge_test_now'] += 299;
		Status::read( false, $this->sites );

		$this->assertCount( 2, $GLOBALS['bwx_forge_test_http_requests'] );
	}

	public function test_check_now_asks_again_but_not_within_fifteen_seconds(): void {
		$this->cloudways_says( 'running' );
		Status::read( false, $this->sites );

		$GLOBALS['bwx_forge_test_now'] += 10;
		Status::read( true, $this->sites );
		$this->assertCount( 2, $GLOBALS['bwx_forge_test_http_requests'] );

		$GLOBALS['bwx_forge_test_now'] += 10;
		$this->servers_say( 'running' );
		Status::read( true, $this->sites );
		$this->assertCount( 3, $GLOBALS['bwx_forge_test_http_requests'] );
	}

	public function test_cloudways_out_of_reach_shows_servers_unreachable_and_keeps_what_was_known(): void {
		$this->cloudways_says( 'stopped' );
		Status::read( false, $this->sites );

		$GLOBALS['bwx_forge_test_now'] += 600;
		$GLOBALS['bwx_forge_test_http'][] = new WP_Error( 'http_request_failed', 'timeout' );
		$status                           = Status::read( false, $this->sites );

		$this->assertNotSame( '', $status['error'] );
		$this->assertSame( 'unreachable', $status['servers'][0]['state'] );
		$this->assertSame( 'stopped', $status['servers'][0]['last_state'] );
		$this->assertSame( 1000600, $status['checked_at'] );
	}

	public function test_a_stopped_server_needs_attention_and_a_running_one_does_not(): void {
		$this->cloudways_says( 'stopped' );
		Status::read( false, $this->sites );

		$problems = Status::problems();

		$this->assertCount( 1, $problems );
		$this->assertSame( 'server', $problems[0]['subject_type'] );
		$this->assertSame( 'server-101', $problems[0]['id'] );
		$this->assertSame( 'London 1', $problems[0]['title'] );
		$this->assertSame( '', $problems[0]['client_site_id'] );
		$this->assertStringContainsString( 'Acme', $problems[0]['detail'] );

		$GLOBALS['bwx_forge_test_now'] += 600;
		$this->servers_say( 'running' );
		Status::read( false, $this->sites );

		$this->assertSame( array(), Status::problems() );
	}

	public function test_while_cloudways_is_out_of_reach_the_last_known_state_still_flags(): void {
		$this->cloudways_says( 'stopped' );
		Status::read( false, $this->sites );
		$GLOBALS['bwx_forge_test_now'] += 600;
		$GLOBALS['bwx_forge_test_http'][] = new WP_Error( 'http_request_failed', 'timeout' );
		Status::read( false, $this->sites );

		$this->assertCount( 1, Status::problems() );
	}

	public function test_nothing_is_flagged_when_cloudways_is_not_connected(): void {
		$this->cloudways_says( 'stopped' );
		Status::read( false, $this->sites );
		Settings::forget();

		$this->assertSame( array(), Status::problems() );
	}
}
