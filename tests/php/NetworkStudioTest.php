<?php
/**
 * The studio's side of multisite deploys.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Network\Client;
use Blueworx\Forge\Network\Deploys;
use Blueworx\Forge\Network\Settings;
use Blueworx\Forge\Rest\Signature;
use PHPUnit\Framework\TestCase;

/**
 * #621. The network's address and key, kept the way every other secret is;
 * calls to it signed with that key; and who gets a login on a new site.
 */
final class NetworkStudioTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_http']          = array();
		$GLOBALS['bwx_forge_test_http_requests'] = array();
		unset( $GLOBALS['bwx_forge_test_options'][ Settings::OPTION ] );
	}

	public function test_only_https_addresses_are_accepted_apart_from_this_machine(): void {
		$this->assertTrue( Settings::valid_url( 'https://network.example.com' ) );
		$this->assertTrue( Settings::valid_url( 'http://127.0.0.1:8893' ) );
		$this->assertTrue( Settings::valid_url( 'http://localhost' ) );
		$this->assertFalse( Settings::valid_url( 'http://network.example.com' ) );
		$this->assertFalse( Settings::valid_url( 'ftp://network.example.com' ) );
		$this->assertFalse( Settings::valid_url( 'network.example.com' ) );
		$this->assertFalse( Settings::valid_url( '' ) );
	}

	public function test_the_key_is_sealed_and_only_its_last_four_are_shown(): void {
		Settings::save( 'https://network.example.com/', 'abcdefghijklmnop1234', array( 'name' => 'Network' ) );

		$this->assertStringNotContainsString( 'abcdefghijkl', (string) wp_json_encode( get_option( Settings::OPTION ) ) );
		$this->assertSame( 'https://network.example.com', Settings::url() );
		$this->assertSame( 'abcdefghijklmnop1234', Settings::key() );
		$this->assertSame( '••••1234', Settings::key_hint() );
		$this->assertTrue( Settings::connected() );
	}

	public function test_a_call_is_signed_with_the_network_key_over_the_exact_path_and_body(): void {
		$GLOBALS['bwx_forge_test_http'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"ok":true}',
		);

		$answer = Client::send( 'https://network.example.com', 'the-key', 'POST', '/network/sites', array( 'slug' => 'acme' ) );

		$this->assertSame( array( 'ok' => true ), $answer );
		$sent = $GLOBALS['bwx_forge_test_http_requests'][0];
		$this->assertSame( 'https://network.example.com/wp-json/blueworx-forge-client/v1/network/sites', $sent['url'] );

		$headers = $sent['args']['headers'];
		$this->assertSame( 'network', $headers['X-BWX-Site'] );
		$expected = Signature::sign( 'the-key', 'network', 'POST', '/blueworx-forge-client/v1/network/sites', $headers['X-BWX-Timestamp'], $headers['X-BWX-Nonce'], $sent['args']['body'] );
		$this->assertSame( $expected, $headers['X-BWX-Signature'] );
		$this->assertStringNotContainsString( 'the-key', (string) wp_json_encode( $sent['args'] ) );
	}

	public function test_a_refused_call_and_an_unreachable_network_are_told_apart(): void {
		$GLOBALS['bwx_forge_test_http'][] = array(
			'response' => array( 'code' => 401 ),
			'body'     => '{"code":"bwx_forge_network_refused"}',
		);
		$GLOBALS['bwx_forge_test_http'][] = new WP_Error( 'http_request_failed', 'timeout' );

		$refused = Client::send( 'https://network.example.com', 'k', 'GET', '/network/hello' );
		$gone    = Client::send( 'https://network.example.com', 'k', 'GET', '/network/hello' );

		$this->assertSame( Client::DENIED, $refused->get_error_code() );
		$this->assertSame( Client::FAILED, $gone->get_error_code() );
	}

	public function test_the_networks_own_words_come_through_when_it_refuses_a_step(): void {
		$GLOBALS['bwx_forge_test_http'][] = array(
			'response' => array( 'code' => 409 ),
			'body'     => '{"code":"bwx_forge_network_address_taken","message":"That address is already taken on the network."}',
		);

		$answer = Client::send( 'https://network.example.com', 'k', 'POST', '/network/sites', array() );

		$this->assertSame( 'That address is already taken on the network.', $answer->get_error_message() );
	}

	public function test_client_admins_get_editor_viewers_manager_and_nobody_else_anything(): void {
		$memberships = array(
			array( 'user_id' => 'u1', 'role' => 'client_admin', 'client_site_id' => '' ),
			array( 'user_id' => 'u2', 'role' => 'client_viewer', 'client_site_id' => 'cst_1' ),
			array( 'user_id' => 'u3', 'role' => 'staff', 'client_site_id' => '' ),
			array( 'user_id' => 'u4', 'role' => 'client_admin', 'client_site_id' => 'cst_other' ),
			array( 'user_id' => 'u5', 'role' => 'primary_admin', 'client_site_id' => '' ),
			array( 'user_id' => 'u6', 'role' => 'internal_viewer', 'client_site_id' => '' ),
			array( 'user_id' => 'u7', 'role' => 'client_viewer', 'client_site_id' => '' ),
		);
		$users       = array(
			'u1' => array( 'email' => 'one@acme.test', 'display_name' => 'One' ),
			'u2' => array( 'email' => 'two@acme.test', 'display_name' => 'Two' ),
			'u3' => array( 'email' => 'staff@studio.test', 'display_name' => 'Staff' ),
			'u4' => array( 'email' => 'four@acme.test', 'display_name' => 'Four' ),
			'u5' => array( 'email' => 'boss@studio.test', 'display_name' => 'Boss' ),
			'u6' => array( 'email' => 'six@studio.test', 'display_name' => 'Six' ),
			'u7' => array( 'email' => '', 'display_name' => 'No email' ),
		);

		$people = Deploys::people_from( $memberships, $users, 'cst_1' );

		$this->assertSame(
			array(
				array( 'email' => 'one@acme.test', 'name' => 'One', 'access' => 'editor' ),
				array( 'email' => 'two@acme.test', 'name' => 'Two', 'access' => 'manager' ),
			),
			$people
		);
	}
}
