<?php
/**
 * Showing a newly issued key once.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Admin\IssuedKey;
use PHPUnit\Framework\TestCase;

/**
 * A key is readable exactly once, by the administrator who issued it.
 *
 * This is the whole of the "shown once" promise on the sites screen, and the
 * part that would fail quietly: a key that survives a second look is a key
 * sitting in a database that anyone with a database export can read, which is
 * the state the promise exists to avoid.
 */
final class IssuedKeyTest extends TestCase {

	/**
	 * Clears the store.
	 */
	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_transients'] = array();
	}

	/**
	 * The administrator who issued it sees it.
	 */
	public function test_the_issuing_administrator_can_take_the_key(): void {
		IssuedKey::remember( 7, 'site_abc', 'the-key' );

		$this->assertSame(
			array(
				'site_id' => 'site_abc',
				'key'     => 'the-key',
			),
			IssuedKey::take( 7 )
		);
	}

	/**
	 * And only once.
	 */
	public function test_the_key_cannot_be_taken_twice(): void {
		IssuedKey::remember( 7, 'site_abc', 'the-key' );

		IssuedKey::take( 7 );

		$this->assertNull( IssuedKey::take( 7 ), 'the key was still readable on a second look' );
	}

	/**
	 * A key waiting for one administrator is not handed to another. Two people
	 * registering sites at once is ordinary, and getting this wrong hands one
	 * client's key to whoever looks next.
	 */
	public function test_another_administrator_is_not_shown_the_key(): void {
		IssuedKey::remember( 7, 'site_abc', 'the-key' );

		$this->assertNull( IssuedKey::take( 8 ) );
		$this->assertNotNull( IssuedKey::take( 7 ), 'the key meant for its issuer went missing' );
	}

	/**
	 * Asking when nothing was issued is not an error.
	 */
	public function test_taking_nothing_returns_nothing(): void {
		$this->assertNull( IssuedKey::take( 7 ) );
	}
}
