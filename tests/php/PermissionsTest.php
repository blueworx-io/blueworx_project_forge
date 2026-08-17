<?php
/**
 * Permission callback tests.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Rest\Permissions;
use PHPUnit\Framework\TestCase;

/**
 * These are the functions standing between the API and the world, so they are
 * tested in isolation as well as through the live API in tests/e2e/rest.spec.js.
 */
final class PermissionsTest extends TestCase {

	/**
	 * Resets the fake capabilities before each test.
	 */
	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_can'] = array();
	}

	/**
	 * Reading is open: the app's read side serves logged-out visitors.
	 */
	public function test_read_is_public(): void {
		$this->assertTrue( Permissions::read() );
	}

	/**
	 * Managing requires the capability, and nothing else.
	 */
	public function test_manage_requires_the_capability(): void {
		$this->assertFalse( Permissions::manage() );

		$GLOBALS['bwx_forge_test_can'] = array( 'manage_options' );

		$this->assertTrue( Permissions::manage() );
	}

	/**
	 * A user with some other capability is still refused — the check is for one
	 * named capability, not for "is logged in and probably fine".
	 */
	public function test_manage_refuses_an_unrelated_capability(): void {
		$GLOBALS['bwx_forge_test_can'] = array( 'edit_posts' );

		$this->assertFalse( Permissions::manage() );
	}
}
