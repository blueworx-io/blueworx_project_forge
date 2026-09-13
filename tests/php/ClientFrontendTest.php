<?php
/**
 * The client workspace page keeps other plugins' page furniture off itself.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

use Blueworx\Forge\Client\Frontend;
use PHPUnit\Framework\TestCase;

/**
 * The workspace page renders a complete document with no theme (#193). A site
 * plugin that dresses "every page it does not draw itself" in the site's
 * header and footer — Clubhouse does — asks through a filter, and the answer
 * has to be no on this page and silence everywhere else.
 */
final class ClientFrontendTest extends TestCase {

	public function test_the_workspace_page_declines_the_site_chrome(): void {
		$this->assertFalse( Frontend::answer_for_site_chrome( null, true ) );
		$this->assertFalse( Frontend::answer_for_site_chrome( true, true ), 'even a plugin that would force it on' );
	}

	public function test_every_other_page_is_left_to_whoever_asked(): void {
		$this->assertNull( Frontend::answer_for_site_chrome( null, false ) );
		$this->assertTrue( Frontend::answer_for_site_chrome( true, false ) );
		$this->assertFalse( Frontend::answer_for_site_chrome( false, false ) );
	}

	/**
	 * The page asks strangers to sign in. Somebody already signed in who still
	 * cannot use Forge is told so — sending them to sign in again showed them
	 * a form they had already filled in, with nowhere else to go.
	 */
	public function test_who_may_open_the_workspace_page(): void {
		$this->assertSame( Frontend::GATE_OPEN, Frontend::gate( true, true ) );
		$this->assertSame( Frontend::GATE_SIGN_IN, Frontend::gate( false, false ) );
		$this->assertSame( Frontend::GATE_REFUSE, Frontend::gate( true, false ) );
	}
}
