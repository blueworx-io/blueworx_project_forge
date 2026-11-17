<?php
/**
 * The GitHub connection Forge keeps.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\GitHub\Settings;
use PHPUnit\Framework\TestCase;

/**
 * #680. The token and the hook secret are sealed, and shown back only by hint.
 */
final class GitHubSettingsTest extends TestCase {

	protected function setUp(): void {
		unset( $GLOBALS['bwx_forge_test_options'][ Settings::OPTION ] );
	}

	public function test_the_token_and_secret_are_sealed_and_hinted(): void {
		Settings::save( 'ghp_abcd1234', 'blueworx-io', 's3cret', 'blueworx-io', 7 );

		$stored = (string) wp_json_encode( $GLOBALS['bwx_forge_test_options'][ Settings::OPTION ] );

		$this->assertStringNotContainsString( 'ghp_abcd1234', $stored );
		$this->assertStringNotContainsString( 's3cret', $stored );
		$this->assertSame( 'ghp_abcd1234', Settings::token() );
		$this->assertSame( 's3cret', Settings::secret() );
		$this->assertSame( '••••1234', Settings::token_hint() );
		$this->assertSame( 'blueworx-io', Settings::org() );
		$this->assertSame( 'blueworx-io', Settings::login() );
		$this->assertSame( 7, Settings::actor() );
		$this->assertTrue( Settings::connected() );

		Settings::forget();

		$this->assertFalse( Settings::connected() );
		$this->assertSame( '', Settings::token_hint() );
	}

	public function test_the_last_message_heard_is_remembered(): void {
		Settings::save( 'ghp_abcd1234', 'blueworx-io', 's3cret', 'blueworx-io', 1 );
		Settings::heard( 1234 );

		$this->assertSame( 1234, Settings::last_heard() );

		Settings::forget();

		$this->assertSame( 0, Settings::last_heard() );
	}
}
