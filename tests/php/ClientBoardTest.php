<?php
/**
 * The client site's read-through view of its board.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

use Blueworx\Forge\Client\Board;
use Blueworx\Forge\Client\Cache;
use Blueworx\Forge\Client\Connection;
use Blueworx\Forge\Client\Sync;
use PHPUnit\Framework\TestCase;

/**
 * #128. The board follows the same read-through rules as the workspace, and
 * for the same reason (ARCH-2, ARCH-4): the studio is canonical, the client
 * site keeps what it last saw, and it never dresses one up as the other.
 *
 * The state that matters most here is the unreachable one. A board that draws
 * empty columns when the studio cannot be reached tells a client their work has
 * been deleted. It has to say it cannot see, not that there is nothing.
 */
final class ClientBoardTest extends TestCase {

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
	 * Queues a board from the studio.
	 *
	 * @param string $title The one item's title.
	 */
	private function studio_answers( string $title = 'Rebuild the booking form' ): void {
		$GLOBALS['bwx_forge_test_http'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode(
				array(
					'ok'        => true,
					'generated' => 5000,
					'stages'    => array(
						array( 'slug' => 'future-idea', 'label' => 'Future idea' ),
						array( 'slug' => 'triage', 'label' => 'Triage' ),
						array( 'slug' => 'in-development', 'label' => 'In development' ),
					),
					'items'     => array(
						array(
							'id'            => 'wrk_1',
							'title'         => $title,
							'stage'         => 'in-development',
							'planned_start' => '2026-09-01',
							'planned_due'   => '2026-09-14',
							'people'        => array( 'primary' => array( 'display_name' => 'Ana Fielding' ) ),
						),
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

	// -----------------------------------------------------------------------
	// The ordinary cases.
	// -----------------------------------------------------------------------

	/**
	 * The items come from the studio, and the site shows them.
	 */
	public function test_a_board_is_read_through_and_shown(): void {
		$this->studio_answers();

		$view = Board::view();

		$this->assertTrue( $view['ok'] );
		$this->assertSame( Sync::STATE_LIVE, $view['sync']['state'] );
		$this->assertSame( 'Rebuild the booking form', $view['items'][0]['title'] );
	}

	/**
	 * The columns are the studio's stage list, not a copy this artifact keeps.
	 * A board holding its own copy of the state machine goes wrong the day a
	 * stage is added, and goes wrong quietly.
	 */
	public function test_the_columns_come_from_the_studio(): void {
		$this->studio_answers();

		$view = Board::view();

		$this->assertSame( array( 'future-idea', 'triage', 'in-development' ), array_column( $view['stages'], 'slug' ) );
		$this->assertSame( array( 'Future idea', 'Triage', 'In development' ), array_column( $view['stages'], 'label' ) );
	}

	/**
	 * A second read inside the window is served locally, as the workspace is.
	 */
	public function test_a_second_read_inside_the_window_does_not_call_the_studio(): void {
		$this->studio_answers();
		Board::view();

		$this->wait( Cache::MAX_AGE - 1 );
		$view = Board::view();

		$this->assertSame( 1, $this->calls(), 'the studio was called again inside the staleness window' );
		$this->assertSame( Sync::STATE_CACHED, $view['sync']['state'] );
	}

	// -----------------------------------------------------------------------
	// When the studio cannot be reached.
	// -----------------------------------------------------------------------

	/**
	 * An unreachable studio falls back to the last board this site saw, and
	 * says that is what it is doing.
	 */
	public function test_an_unreachable_studio_falls_back_to_the_last_board(): void {
		$this->studio_answers();
		Board::view();

		$this->wait( Cache::MAX_AGE + 1 );
		$this->studio_is_down();
		$view = Board::view();

		$this->assertSame( Sync::STATE_STALE, $view['sync']['state'] );
		$this->assertTrue( $view['sync']['stale'] );
		$this->assertSame( 'Rebuild the booking form', $view['items'][0]['title'] );
	}

	/**
	 * The one that matters. With nothing cached, an unreachable studio is not
	 * an empty board — an empty board says the work is gone.
	 */
	public function test_an_unreachable_studio_with_nothing_cached_is_not_an_empty_board(): void {
		$this->studio_is_down();

		$view = Board::view();

		$this->assertFalse( $view['ok'] );
		$this->assertSame( Sync::STATE_UNREACHABLE, $view['sync']['state'] );
		$this->assertSame( array(), $view['items'] );
	}

	/**
	 * A site nobody has connected says so, rather than blaming the network.
	 */
	public function test_an_unconnected_site_says_it_is_not_connected(): void {
		$GLOBALS['bwx_forge_test_options'] = array();

		$view = Board::view();

		$this->assertFalse( $view['ok'] );
		$this->assertSame( Sync::STATE_NOT_CONFIGURED, $view['sync']['state'] );
		$this->assertSame( 0, $this->calls() );
	}

	// -----------------------------------------------------------------------
	// The two records are separate.
	// -----------------------------------------------------------------------

	/**
	 * The board and the workspace are different reads with different lifetimes.
	 * Reading one must not satisfy the other, or a fresh workspace would serve
	 * a board that was never fetched.
	 */
	public function test_reading_the_workspace_does_not_satisfy_a_board_read(): void {
		$GLOBALS['bwx_forge_test_http'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode(
				array(
					'ok'     => true,
					'record' => array( 'name' => 'Acme Ltd' ),
				)
			),
		);
		\Blueworx\Forge\Client\Workspace::view();

		$this->studio_answers();
		$view = Board::view();

		$this->assertSame( 2, $this->calls(), 'the board was served from the workspace cache' );
		$this->assertSame( Sync::STATE_LIVE, $view['sync']['state'] );
	}
}
