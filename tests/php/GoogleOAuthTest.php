<?php
/**
 * Arriving back from Google's consent screen.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Google\OAuth;
use PHPUnit\Framework\TestCase;

/**
 * #606. The state is the check: an unknown or used one, or one started by a
 * different account, connects nobody — and Google is never asked.
 */
final class GoogleOAuthTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_http']          = array();
		$GLOBALS['bwx_forge_test_http_requests'] = array();
	}

	public function test_an_unknown_or_used_state_connects_nobody(): void {
		$this->assertSame( 'expired', OAuth::finish( null, 7, 'a-code', '' ) );
		$this->assertSame( array(), $GLOBALS['bwx_forge_test_http_requests'] );
	}

	public function test_a_state_started_by_somebody_else_connects_nobody(): void {
		$started = array(
			'user_id'    => 'usr_a',
			'wp_user_id' => 7,
		);

		$this->assertSame( 'expired', OAuth::finish( $started, 8, 'a-code', '' ) );
		$this->assertSame( 'expired', OAuth::finish( $started, 0, 'a-code', '' ) );
		$this->assertSame( array(), $GLOBALS['bwx_forge_test_http_requests'] );
	}

	public function test_saying_no_on_googles_screen_connects_nobody(): void {
		$started = array(
			'user_id'    => 'usr_a',
			'wp_user_id' => 7,
		);

		$this->assertSame( 'declined', OAuth::finish( $started, 7, '', 'access_denied' ) );
		$this->assertSame( array(), $GLOBALS['bwx_forge_test_http_requests'] );
	}
}
