<?php
/**
 * The client site's read-through view of a studio record.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

use Blueworx\Forge\Client\Cache;
use Blueworx\Forge\Client\Connection;
use Blueworx\Forge\Client\Workspace;
use PHPUnit\Framework\TestCase;

/**
 * ARCH-2: the client site renders a studio record and holds no canonical copy.
 * ARCH-4: when the studio is unreachable it degrades to a cached read-only view
 * carrying a visible stale-data notice.
 *
 * Each test here is a state a real client site ends up in — connected and quick,
 * connected and slow, cut off, never connected — rather than a branch of the
 * function. The one that matters most is the difference between "we cannot see
 * your things right now" and "you have nothing", because only one of those is
 * ever true and showing the wrong one tells a client their work has vanished.
 */
final class WorkspaceTest extends TestCase {

	/**
	 * Clears every store and connects the site.
	 */
	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_options']       = array();
		$GLOBALS['bwx_forge_test_http']          = array();
		$GLOBALS['bwx_forge_test_http_requests'] = array();
		$GLOBALS['bwx_forge_client_test_now']    = 2000000;

		Connection::store( 'https://studio.example', 'site_abc', 'key-1' );
	}

	/**
	 * Queues an answer from the studio.
	 *
	 * @param string $name The site name the studio holds.
	 */
	private function studio_answers( string $name = 'Acme Ltd' ): void {
		$GLOBALS['bwx_forge_test_http'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode(
				array(
					'ok'        => true,
					'generated' => 5000,
					'record'    => array(
						'site_id'         => 'site_abc',
						'name'            => $name,
						'url'             => 'https://acme.example',
						'status'          => 'active',
						'connected_since' => 1000,
					),
				)
			),
		);
	}

	/**
	 * Queues the studio being unreachable.
	 */
	private function studio_is_down(): void {
		$GLOBALS['bwx_forge_test_http'][] = new WP_Error( 'http_request_failed', 'Connection refused.' );
	}

	/**
	 * How many times the studio has been called.
	 *
	 * @return int
	 */
	private function calls(): int {
		return count( $GLOBALS['bwx_forge_test_http_requests'] );
	}

	/**
	 * Moves the client site's clock on.
	 *
	 * @param int $seconds Seconds to advance.
	 */
	private function wait( int $seconds ): void {
		$GLOBALS['bwx_forge_client_test_now'] += $seconds;
	}

	/**
	 * The record comes from the studio, and the site shows it.
	 */
	public function test_a_studio_record_is_read_through_and_shown(): void {
		$this->studio_answers();

		$view = Workspace::view();

		$this->assertTrue( $view['ok'] );
		$this->assertSame( Workspace::STATE_LIVE, $view['sync']['state'] );
		$this->assertSame( 'Acme Ltd', $view['record']['name'] );
		$this->assertFalse( $view['sync']['stale'] );
	}

	/**
	 * A second read inside the staleness window is served locally. Without this
	 * every page view on the client site is a round trip to the studio, which is
	 * the cost ARCH-2 caches specifically to avoid.
	 */
	public function test_a_second_read_inside_the_window_does_not_call_the_studio(): void {
		$this->studio_answers();
		Workspace::view();

		$this->wait( Cache::MAX_AGE - 1 );
		$view = Workspace::view();

		$this->assertSame( 1, $this->calls(), 'the studio was called again inside the staleness window' );
		$this->assertSame( Workspace::STATE_CACHED, $view['sync']['state'] );
		$this->assertSame( 'Acme Ltd', $view['record']['name'] );
	}

	/**
	 * Past the window it asks again, and shows what changed. ARCH-5 sets
	 * acceptable staleness on a client site at 60 seconds.
	 */
	public function test_past_the_window_it_reads_again(): void {
		$this->studio_answers( 'Acme Ltd' );
		Workspace::view();

		$this->wait( Cache::MAX_AGE );
		$this->studio_answers( 'Acme Limited' );
		$view = Workspace::view();

		$this->assertSame( 2, $this->calls() );
		$this->assertSame( Workspace::STATE_LIVE, $view['sync']['state'] );
		$this->assertSame( 'Acme Limited', $view['record']['name'] );
	}

	/**
	 * Asking again on purpose skips the window, so someone who has just fixed a
	 * connection does not have to wait a minute to find out.
	 */
	public function test_a_forced_read_ignores_a_fresh_copy(): void {
		$this->studio_answers( 'Acme Ltd' );
		Workspace::view();

		$this->studio_answers( 'Acme Limited' );
		$view = Workspace::view( true );

		$this->assertSame( 2, $this->calls() );
		$this->assertSame( 'Acme Limited', $view['record']['name'] );
	}

	/**
	 * The studio going down does not empty the screen: the last copy is still
	 * shown, and the answer says plainly that it is old (ARCH-4).
	 */
	public function test_an_unreachable_studio_falls_back_to_the_last_copy(): void {
		$this->studio_answers();
		Workspace::view();

		$this->wait( Cache::MAX_AGE + 5 );
		$this->studio_is_down();
		$view = Workspace::view();

		$this->assertTrue( $view['ok'] );
		$this->assertSame( 'Acme Ltd', $view['record']['name'] );
		$this->assertSame( Workspace::STATE_STALE, $view['sync']['state'] );
		$this->assertTrue( $view['sync']['stale'] );
		$this->assertSame( Cache::MAX_AGE + 5, $view['sync']['age'], 'the age shown must be the age of what is on screen' );
	}

	/**
	 * With nothing cached and the studio down, the site says so rather than
	 * showing an empty workspace.
	 */
	public function test_an_unreachable_studio_with_nothing_cached_says_so(): void {
		$this->studio_is_down();

		$view = Workspace::view();

		$this->assertFalse( $view['ok'] );
		$this->assertNull( $view['record'] );
		$this->assertSame( Workspace::STATE_UNREACHABLE, $view['sync']['state'] );
		$this->assertTrue( $view['sync']['stale'] );
	}

	/**
	 * A refusal is not an outage, and the answer carries the status so the
	 * screen can tell a revoked site from a studio that is down.
	 */
	public function test_a_refused_read_reports_the_refusal(): void {
		$this->studio_answers();
		Workspace::view();

		$this->wait( Cache::MAX_AGE );
		$GLOBALS['bwx_forge_test_http'][] = array(
			'response' => array( 'code' => 401 ),
			'body'     => '{"code":"bwx_forge_unauthenticated"}',
		);

		$view = Workspace::view();

		$this->assertSame( Workspace::STATE_STALE, $view['sync']['state'] );
		$this->assertSame( 401, $view['sync']['status'] );
		$this->assertSame( 'bwx_forge_client_refused', $view['sync']['reason'] );
	}

	/**
	 * A failed read is not cached. Otherwise the failure itself becomes the copy
	 * the site falls back on, and the real record is lost.
	 */
	public function test_a_failed_read_does_not_replace_the_copy(): void {
		$this->studio_answers();
		Workspace::view();

		$this->wait( Cache::MAX_AGE );
		$this->studio_is_down();
		Workspace::view();

		$this->wait( 1 );
		$this->studio_is_down();
		$view = Workspace::view();

		$this->assertSame( 'Acme Ltd', $view['record']['name'] );
	}

	/**
	 * A failed refresh is remembered, so the next page view does not go back to
	 * calling itself up to date. It also does not go back to the network on
	 * every view: a studio that is down does not want a request from every page
	 * view on every client site.
	 */
	public function test_a_failed_refresh_is_still_reported_on_the_next_read(): void {
		$this->studio_answers();
		Workspace::view();

		$this->studio_is_down();
		Workspace::view( true );

		$this->wait( 1 );
		$view = Workspace::view();

		$this->assertSame( 2, $this->calls(), 'a site with a known-down studio must not retry on every view' );
		$this->assertSame( Workspace::STATE_STALE, $view['sync']['state'] );
		$this->assertSame( 'Acme Ltd', $view['record']['name'] );
	}

	/**
	 * And it stops being reported once it works again.
	 */
	public function test_a_recovered_read_stops_reporting_the_failure(): void {
		$this->studio_answers();
		Workspace::view();

		$this->studio_is_down();
		Workspace::view( true );

		$this->studio_answers();
		$view = Workspace::view( true );

		$this->assertSame( Workspace::STATE_LIVE, $view['sync']['state'] );
		$this->assertFalse( $view['sync']['stale'] );
		$this->assertSame( '', $view['sync']['reason'] );
	}

	/**
	 * A site nobody has connected says that, rather than reporting a network
	 * problem it never had.
	 */
	public function test_an_unconnected_site_says_it_is_not_connected(): void {
		Connection::forget();

		$view = Workspace::view();

		$this->assertFalse( $view['ok'] );
		$this->assertSame( Workspace::STATE_NOT_CONFIGURED, $view['sync']['state'] );
		$this->assertSame( 0, $this->calls(), 'an unconnected site must not call anything' );
	}

	/**
	 * Reconnecting throws the copy away. What was cached was read as whoever
	 * this site was before; after a re-connection it may be a different client,
	 * and serving the old copy would show one client another one's record.
	 */
	public function test_reconnecting_throws_away_what_was_cached(): void {
		$this->studio_answers( 'Acme Ltd' );
		Workspace::view();

		Connection::store( 'https://studio.example', 'site_xyz', 'key-2' );

		$this->assertNull( Cache::get( Workspace::ROUTE ) );

		$this->studio_answers( 'Beta Ltd' );
		$view = Workspace::view();

		$this->assertSame( 'Beta Ltd', $view['record']['name'] );
	}

	/**
	 * The read goes to the studio's own route, signed. The signature itself is
	 * proved in SignatureTest; what matters here is that this read goes through
	 * it rather than making a bare request.
	 */
	public function test_the_read_is_signed(): void {
		$this->studio_answers();
		Workspace::view();

		$request = $GLOBALS['bwx_forge_test_http_requests'][0];

		$this->assertSame( 'site_abc', $request['args']['headers']['X-BWX-Site'] );
		$this->assertNotEmpty( $request['args']['headers']['X-BWX-Signature'] );
		$this->assertStringEndsWith( '/wp-json/blueworx-forge/v1/client/workspace', $request['url'] );
	}
}
