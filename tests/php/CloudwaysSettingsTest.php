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
 * #621. The email is shown back; the API key never is, beyond its last four
 * characters, and it is sealed at rest.
 */
final class CloudwaysSettingsTest extends TestCase {

	protected function setUp(): void {
		unset( $GLOBALS['bwx_forge_test_options'][ Settings::OPTION ] );
		$GLOBALS['bwx_forge_test_transients'] = array();
	}

	public function test_nothing_saved_is_not_connected(): void {
		$this->assertFalse( Settings::connected() );
		$this->assertSame( '', Settings::email() );
		$this->assertNull( Settings::key() );
		$this->assertSame( '', Settings::key_hint() );
	}

	public function test_the_key_is_sealed_and_only_its_last_four_are_shown(): void {
		Settings::save( 'ops@studio.test', 'cwKeyAbCdEf123456wxyz' );

		$this->assertStringNotContainsString( 'cwKeyAbCdEf', (string) wp_json_encode( get_option( Settings::OPTION ) ) );
		$this->assertTrue( Settings::connected() );
		$this->assertSame( 'ops@studio.test', Settings::email() );
		$this->assertSame( 'cwKeyAbCdEf123456wxyz', Settings::key() );
		$this->assertSame( '••••wxyz', Settings::key_hint() );
		$this->assertSame( bwx_forge_now(), Settings::connected_at() );
	}

	public function test_forgetting_clears_the_account_its_token_and_what_was_last_seen(): void {
		Settings::save( 'ops@studio.test', 'cwKeyAbCdEf123456wxyz' );
		set_transient( Settings::TOKEN, 'sealed-token' );
		update_option( Settings::STATUS, array( 'checked_at' => 5 ) );

		Settings::forget();

		$this->assertFalse( Settings::connected() );
		$this->assertFalse( get_transient( Settings::TOKEN ) );
		$this->assertSame( array(), get_option( Settings::STATUS, array() ) );
	}
}
