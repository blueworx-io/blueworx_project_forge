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
 * The account is the token's own: its login is the owner whose repos are listed.
 */
final class GitHubSettingsTest extends TestCase {

	protected function setUp(): void {
		unset(
			$GLOBALS['bwx_forge_test_options'][ Settings::OPTION ],
			$GLOBALS['bwx_forge_test_options'][ Settings::STATUS ],
			$GLOBALS['bwx_forge_test_options'][ Settings::HEARD ]
		);
	}

	public function test_the_token_and_secret_are_sealed_and_hinted(): void {
		Settings::save( 'ghp_abcd1234', 's3cret', 'Blueworx-IO', 7 );

		$stored = (string) wp_json_encode( $GLOBALS['bwx_forge_test_options'][ Settings::OPTION ] );

		$this->assertStringNotContainsString( 'ghp_abcd1234', $stored );
		$this->assertStringNotContainsString( 's3cret', $stored );
		$this->assertSame( 'ghp_abcd1234', Settings::token() );
		$this->assertSame( 's3cret', Settings::secret() );
		$this->assertSame( '••••1234', Settings::token_hint() );
		$this->assertSame( 'blueworx-io', Settings::owner() );
		$this->assertSame( 'Blueworx-IO', Settings::login() );
		$this->assertSame( 7, Settings::actor() );
		$this->assertTrue( Settings::connected() );

		Settings::forget();

		$this->assertFalse( Settings::connected() );
		$this->assertSame( '', Settings::token_hint() );
	}

	public function test_a_connection_saved_before_the_rename_still_reads_its_owner(): void {
		Settings::save( 'ghp_abcd1234', 's3cret', 'blueworx-io', 7 );

		$old = $GLOBALS['bwx_forge_test_options'][ Settings::OPTION ];
		unset( $old['owner'] );
		$old['org'] = 'blueworx-io';
		$GLOBALS['bwx_forge_test_options'][ Settings::OPTION ] = $old;

		$this->assertSame( 'blueworx-io', Settings::owner() );
		$this->assertTrue( Settings::connected() );
	}

	public function test_the_last_message_heard_is_kept_apart_from_the_connection(): void {
		Settings::save( 'ghp_abcd1234', 's3cret', 'blueworx-io', 1 );
		$before = $GLOBALS['bwx_forge_test_options'][ Settings::OPTION ];

		Settings::heard( 1234 );

		$this->assertSame( 1234, Settings::last_heard() );
		// A delivery never rewrites the sealed connection.
		$this->assertSame( $before, $GLOBALS['bwx_forge_test_options'][ Settings::OPTION ] );

		Settings::forget();

		$this->assertSame( 0, Settings::last_heard() );
	}

	public function test_the_last_failure_is_kept_until_a_good_connect(): void {
		$this->assertSame( array( 'at' => 0, 'what' => '' ), Settings::problem() );

		Settings::failed( 'GitHub no longer accepts the token.' );

		$problem = Settings::problem();
		$this->assertSame( 'GitHub no longer accepts the token.', $problem['what'] );
		$this->assertGreaterThan( 0, $problem['at'] );

		Settings::save( 'ghp_abcd1234', 's3cret', 'blueworx-io', 1 );

		$this->assertSame( '', Settings::problem()['what'] );
	}
}
