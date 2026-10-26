<?php
/**
 * Whether a synced meeting is only studio staff.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Google\Internal;
use PHPUnit\Framework\TestCase;

/**
 * #657. A meeting where everyone invited is studio staff is studio time. One
 * guest from outside makes it a client's meeting.
 */
final class GoogleInternalTest extends TestCase {

	public function test_only_staff_is_internal(): void {
		$this->assertTrue( Internal::is_internal( array( array( 'email' => ' Jess@BlueWorx.io ' ) ), array( 'jess@blueworx.io' ), array() ) );
	}

	public function test_the_studio_domain_counts_as_staff(): void {
		$this->assertTrue( Internal::is_internal( array( array( 'email' => 'ross@blueworx.io' ) ), array(), array( 'blueworx.io' ) ) );
	}

	public function test_a_lookalike_domain_does_not(): void {
		$this->assertFalse( Internal::is_internal( array( array( 'email' => 'x@blueworx.io.evil.com' ) ), array(), array( 'blueworx.io' ) ) );
		$this->assertFalse( Internal::is_internal( array( array( 'email' => 'x@notblueworx.io' ) ), array(), array( 'blueworx.io' ) ) );
	}

	public function test_one_outside_guest_makes_it_a_client_meeting(): void {
		$this->assertFalse(
			Internal::is_internal(
				array(
					array( 'email' => 'jess@blueworx.io' ),
					array( 'email' => 'client@example.test' ),
				),
				array( 'jess@blueworx.io' ),
				array( 'blueworx.io' )
			)
		);
	}

	public function test_no_guests_is_internal(): void {
		$this->assertTrue( Internal::is_internal( array(), array(), array() ) );
	}
}
