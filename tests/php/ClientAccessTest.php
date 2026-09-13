<?php
/**
 * Who may use Forge on a client site.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

use Blueworx\Forge\Client\Access;
use PHPUnit\Framework\TestCase;

/**
 * Two capabilities and one role. Administrators get both capabilities
 * without anybody having to grant them; a Forge: Manager gets the one that
 * opens every screen but the connection.
 */
final class ClientAccessTest extends TestCase {

	public function test_an_administrator_can_use_forge_and_manage_the_connection(): void {
		$caps = Access::with_forge_caps( array( 'manage_options' => true ), array( 'administrator' ) );

		$this->assertTrue( $caps[ Access::USE ] );
		$this->assertTrue( $caps[ Access::CONNECT ] );
		$this->assertTrue( $caps['manage_options'], 'nothing they already had is taken away' );
	}

	public function test_anybody_else_is_left_with_what_their_role_gave_them(): void {
		$caps = Access::with_forge_caps( array( 'read' => true ), array( 'editor' ) );

		$this->assertSame( array( 'read' => true ), $caps );
	}

	public function test_the_manager_role_uses_forge_but_does_not_touch_the_connection(): void {
		$caps = Access::role_caps();

		$this->assertTrue( $caps['read'], 'a manager can reach wp-admin at all' );
		$this->assertTrue( $caps[ Access::USE ] );
		$this->assertTrue( $caps['upload_files'], 'a request can carry a screenshot' );
		$this->assertArrayNotHasKey( Access::CONNECT, $caps );
		$this->assertArrayNotHasKey( 'manage_options', $caps );
	}

	public function test_the_names_are_the_agreed_ones(): void {
		$this->assertSame( 'forge_manager', Access::ROLE );
		$this->assertSame( 'Forge: Manager', Access::ROLE_NAME );
		$this->assertSame( 'bwx_forge_client_use', Access::USE );
		$this->assertSame( 'bwx_forge_client_connect', Access::CONNECT );
	}
}
