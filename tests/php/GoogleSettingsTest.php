<?php
/**
 * The studio's Google OAuth client, entered once by an administrator.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Google\Settings;
use PHPUnit\Framework\TestCase;

/**
 * #606. The client id is shown back; the secret never is, beyond its last
 * four characters, and saving without one keeps the one already there.
 */
final class GoogleSettingsTest extends TestCase {

	protected function setUp(): void {
		unset( $GLOBALS['bwx_forge_test_options'][ Settings::OPTION ] );
	}

	public function test_nothing_saved_is_not_configured(): void {
		$this->assertFalse( Settings::configured() );
		$this->assertSame( '', Settings::client_id() );
		$this->assertNull( Settings::secret() );
		$this->assertSame( '', Settings::secret_hint() );
	}

	public function test_the_secret_is_sealed_and_only_its_last_four_are_shown(): void {
		Settings::save( 'client-123.apps.googleusercontent.com', 'GOCSPX-supersecretvalue' );

		$stored = (array) get_option( Settings::OPTION );
		$this->assertStringNotContainsString( 'supersecret', (string) wp_json_encode( $stored ) );

		$this->assertTrue( Settings::configured() );
		$this->assertSame( 'client-123.apps.googleusercontent.com', Settings::client_id() );
		$this->assertSame( 'GOCSPX-supersecretvalue', Settings::secret() );
		$this->assertSame( 'alue', substr( Settings::secret_hint(), -4 ) );
		$this->assertStringNotContainsString( 'secret', Settings::secret_hint() );
	}

	public function test_saving_without_a_secret_keeps_the_one_already_there(): void {
		Settings::save( 'first-id', 'the-first-secret' );
		Settings::save( 'second-id', '' );

		$this->assertSame( 'second-id', Settings::client_id() );
		$this->assertSame( 'the-first-secret', Settings::secret() );
	}

	public function test_the_consent_address_asks_for_read_only_calendar_access_offline(): void {
		Settings::save( 'client-123', 'secret' );

		$url = Settings::auth_url( 'state-abc' );
		$this->assertStringStartsWith( 'https://accounts.google.com/o/oauth2/v2/auth?', $url );

		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( 'client-123', $query['client_id'] );
		$this->assertSame( Settings::redirect_uri(), $query['redirect_uri'] );
		$this->assertSame( 'code', $query['response_type'] );
		$this->assertSame( 'https://www.googleapis.com/auth/calendar.readonly', $query['scope'] );
		$this->assertSame( 'offline', $query['access_type'] );
		$this->assertSame( 'consent', $query['prompt'] );
		$this->assertSame( 'state-abc', $query['state'] );
	}

	public function test_the_redirect_address_is_forges_admin_post_handler(): void {
		$this->assertStringEndsWith( 'wp-admin/admin-post.php?action=bwx_forge_google', Settings::redirect_uri() );
	}
}
