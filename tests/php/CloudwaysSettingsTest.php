<?php
/**
 * The studio's Cloudways account, entered once by an administrator.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Cloudways\Settings;
use PHPUnit\Framework\TestCase;

/**
 * #621, #645. The access token is never shown back, beyond its last four
 * characters, and it is sealed at rest.
 */
final class CloudwaysSettingsTest extends TestCase {

	protected function setUp(): void {
		unset( $GLOBALS['bwx_forge_test_options'][ Settings::OPTION ] );
	}

	public function test_nothing_saved_is_not_connected(): void {
		$this->assertFalse( Settings::connected() );
		$this->assertNull( Settings::token() );
		$this->assertSame( '', Settings::token_hint() );
	}

	public function test_the_token_is_sealed_and_only_its_last_four_are_shown(): void {
		Settings::save( 'cwTokenAbCdEf123456wxyz' );

		$this->assertStringNotContainsString( 'cwTokenAbCdEf', (string) wp_json_encode( get_option( Settings::OPTION ) ) );
		$this->assertTrue( Settings::connected() );
		$this->assertSame( 'cwTokenAbCdEf123456wxyz', Settings::token() );
		$this->assertSame( '••••wxyz', Settings::token_hint() );
		$this->assertSame( bwx_forge_now(), Settings::connected_at() );
	}

	public function test_a_legacy_email_and_api_key_no_longer_count_as_connected(): void {
		// What #621 saved. Cloudways retired those keys on 15 October 2026.
		update_option(
			Settings::OPTION,
			array(
				'email' => 'ops@studio.test',
				'key'   => 'sealed-legacy-key',
				'hint'  => '1234',
			)
		);

		$this->assertFalse( Settings::connected() );
	}

	public function test_forgetting_clears_the_account_and_what_was_last_seen(): void {
		Settings::save( 'cwTokenAbCdEf123456wxyz' );
		update_option( Settings::STATUS, array( 'checked_at' => 5 ) );

		Settings::forget();

		$this->assertFalse( Settings::connected() );
		$this->assertSame( array(), get_option( Settings::STATUS, array() ) );
	}
}
