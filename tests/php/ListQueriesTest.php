<?php
/**
 * The pure parts of #712: how many open questions each item has when a whole
 * list is read at once, and when a list keeps what it has read.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Client\Cache;
use Blueworx\Forge\Work\Comments;
use Blueworx\Forge\Work\ListMemo;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/multisite-stubs.php';

if ( ! function_exists( 'get_sites' ) ) {
	/**
	 * Stub. The ids of the sites on the stub network.
	 *
	 * @param array<string, mixed> $args Ignored but for the shape.
	 * @return array<int, int>
	 */
	function get_sites( array $args = array() ): array {
		return array_keys( $GLOBALS['bwx_forge_test_net']['sites'] );
	}
}

if ( ! function_exists( 'wp_set_option_autoload' ) ) {
	/**
	 * Stub. Records which options were taken out of the load on every page.
	 *
	 * @param string $option   Option name.
	 * @param bool   $autoload Whether it loads on every page.
	 * @return bool
	 */
	function wp_set_option_autoload( string $option, bool $autoload ): bool {
		$GLOBALS['bwx_forge_test_autoload'][ $option ] = $autoload;

		return true;
	}
}

if ( ! function_exists( 'wp_load_alloptions' ) ) {
	/**
	 * Stub. The options WordPress loads on every page; a test fills it in.
	 *
	 * @param bool $force_cache Ignored.
	 * @return array<string, mixed>
	 */
	function wp_load_alloptions( bool $force_cache = false ): array {
		return $GLOBALS['bwx_forge_test_alloptions'] ?? array();
	}
}

/**
 * #712. Every card on a list asked the database, on its own, whether it had
 * open questions and whether it had evidence — about 1,700 queries for 4,000
 * cards. A list now reads both once, so what it says has to be exactly what
 * the one-card reading says.
 */
final class ListQueriesTest extends TestCase {

	/**
	 * Stops keeping reads, whatever a test left on.
	 */
	protected function tearDown(): void {
		ListMemo::stop();

		unset( $GLOBALS['bwx_forge_test_autoload'], $GLOBALS['bwx_forge_test_alloptions'], $GLOBALS['bwx_forge_test_autoload_row'], $GLOBALS['bwx_forge_test_options'][ Cache::LEAN_FLAG ] );

		if ( isset( $this->database ) ) {
			$GLOBALS['wpdb'] = $this->database;
		}

		bwx_forge_test_network();
	}

	/**
	 * The database the other tests use, put back after each test here.
	 *
	 * @var object|null
	 */
	private $database = null;

	/**
	 * A database whose options table answers what the cache's own row says:
	 * the value a test set, else off once WordPress was told to stop loading
	 * it, else on while the copy exists.
	 */
	private function options_database(): void {
		$this->database  = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			/**
			 * Options table.
			 *
			 * @var string
			 */
			public string $options = 'wp_options';

			/**
			 * The last error, as $wpdb keeps it.
			 *
			 * @var string
			 */
			public string $last_error = '';

			/**
			 * Stub.
			 *
			 * @param string $query Query.
			 * @param mixed  ...$args Values.
			 * @return string
			 */
			public function prepare( string $query, ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
			}

			/**
			 * Stub.
			 *
			 * @param string $query Query.
			 * @return string|null
			 */
			public function get_var( string $query ) {
				if ( ! str_contains( $query, Cache::OPTION ) ) {
					return null;
				}

				if ( array_key_exists( 'bwx_forge_test_autoload_row', $GLOBALS ) ) {
					return $GLOBALS['bwx_forge_test_autoload_row'];
				}

				if ( ! array_key_exists( Cache::OPTION, $GLOBALS['bwx_forge_test_options'] ?? array() ) ) {
					return null;
				}

				return false === ( $GLOBALS['bwx_forge_test_autoload'][ Cache::OPTION ] ?? true ) ? 'off' : 'on';
			}
		};
	}

	/**
	 * A row as the one read returns it.
	 *
	 * @param string $id      Comment id.
	 * @param string $item_id Item id.
	 * @param string $kind    comment or question.
	 * @param string $answers The question it answers, or ''.
	 * @return array<string, string>
	 */
	private function row( string $id, string $item_id, string $kind, string $answers = '' ): array {
		return array(
			'id'      => $id,
			'item_id' => $item_id,
			'kind'    => $kind,
			'answers' => $answers,
		);
	}

	public function test_a_question_nobody_has_answered_is_open(): void {
		$this->assertSame(
			array( 'a' => 2 ),
			Comments::open_questions_by_item( array( $this->row( 'q1', 'a', 'question' ), $this->row( 'q2', 'a', 'question' ) ) )
		);
	}

	public function test_an_answered_question_drops_out(): void {
		$this->assertSame(
			array( 'a' => 1 ),
			Comments::open_questions_by_item(
				array(
					$this->row( 'q1', 'a', 'question' ),
					$this->row( 'q2', 'a', 'question' ),
					$this->row( 'c1', 'a', 'comment', 'q1' ),
				)
			)
		);
	}

	public function test_an_item_with_nothing_open_is_absent(): void {
		$this->assertSame(
			array(),
			Comments::open_questions_by_item( array( $this->row( 'q1', 'a', 'question' ), $this->row( 'c1', 'a', 'comment', 'q1' ) ) )
		);
	}

	public function test_an_answer_only_closes_a_question_on_its_own_item(): void {
		// The one-card reading looks at one item's comments, so an answer on
		// item b never closes a question on item a.
		$this->assertSame(
			array( 'a' => 1 ),
			Comments::open_questions_by_item( array( $this->row( 'q1', 'a', 'question' ), $this->row( 'c1', 'b', 'comment', 'q1' ) ) )
		);
	}

	public function test_items_are_counted_separately(): void {
		$this->assertSame(
			array(
				'a' => 1,
				'b' => 2,
			),
			Comments::open_questions_by_item(
				array(
					$this->row( 'q1', 'a', 'question' ),
					$this->row( 'q2', 'b', 'question' ),
					$this->row( 'q3', 'b', 'question' ),
				)
			)
		);
	}

	public function test_a_list_knows_it_is_drawing_one(): void {
		$this->assertFalse( ListMemo::is_on() );

		ListMemo::start();
		$this->assertTrue( ListMemo::is_on() );

		ListMemo::stop();
		$this->assertFalse( ListMemo::is_on() );
	}

	public function test_a_list_only_covers_the_cards_it_names(): void {
		ListMemo::start();
		ListMemo::cover( array( 'a', 'b' ) );

		$this->assertTrue( ListMemo::covers( 'a' ) );
		$this->assertFalse( ListMemo::covers( 'c' ) );
		$this->assertSame( array( 'a', 'b' ), ListMemo::covered() );
	}

	public function test_nothing_is_covered_when_no_list_is_being_drawn(): void {
		ListMemo::cover( array( 'a' ) );

		$this->assertFalse( ListMemo::covers( 'a' ) );
		$this->assertSame( array(), ListMemo::covered() );
	}

	public function test_a_read_made_for_fewer_cards_is_not_reused_for_more(): void {
		ListMemo::start();
		ListMemo::cover( array( 'a' ) );
		$first = ListMemo::key_for_covered( 'evidence' );

		ListMemo::cover( array( 'a' ) );
		$this->assertSame( $first, ListMemo::key_for_covered( 'evidence' ), 'Naming a card again changes nothing.' );

		ListMemo::cover( array( 'b' ) );
		$this->assertNotSame( $first, ListMemo::key_for_covered( 'evidence' ) );
	}

	public function test_a_stored_copy_is_taken_out_of_the_load_once(): void {
		$this->options_database();
		$GLOBALS['bwx_forge_test_options'][ Cache::OPTION ] = array( 'route' => array( 'payload' => array() ) );

		Cache::stop_autoloading();

		$this->assertFalse( $GLOBALS['bwx_forge_test_autoload'][ Cache::OPTION ] );
		$this->assertSame( array( 'route' => array( 'payload' => array() ) ), get_option( Cache::OPTION ), 'The copy itself is kept.' );

		unset( $GLOBALS['bwx_forge_test_autoload'] );
		Cache::stop_autoloading();

		$this->assertArrayNotHasKey( 'bwx_forge_test_autoload', $GLOBALS, 'It is done once, not on every page.' );
	}

	public function test_a_failed_change_is_tried_again_on_the_next_page(): void {
		$this->options_database();

		// WordPress could not change it: the copy's row still says it loads.
		$GLOBALS['bwx_forge_test_autoload_row'] = 'on';

		Cache::stop_autoloading();

		$this->assertArrayNotHasKey( Cache::LEAN_FLAG, $GLOBALS['bwx_forge_test_options'] );

		$GLOBALS['bwx_forge_test_autoload_row'] = 'off';

		Cache::stop_autoloading();

		$this->assertSame( '1', get_option( Cache::LEAN_FLAG ) );
	}

	public function test_a_database_check_that_fails_is_tried_again(): void {
		$this->options_database();

		$GLOBALS['bwx_forge_test_autoload_row'] = null;
		$GLOBALS['wpdb']->last_error          = 'MySQL server has gone away';

		Cache::stop_autoloading();

		$this->assertArrayNotHasKey( Cache::LEAN_FLAG, $GLOBALS['bwx_forge_test_options'], 'Not marked done on a failed check.' );

		$GLOBALS['wpdb']->last_error          = '';
		$GLOBALS['bwx_forge_test_autoload_row'] = 'off';

		Cache::stop_autoloading();

		$this->assertSame( '1', get_option( Cache::LEAN_FLAG ) );
	}

	public function test_a_database_check_that_is_cancelled_is_tried_again(): void {
		$this->options_database();

		// Another plugin stopped the check: no answer and no error, though the copy exists.
		$GLOBALS['bwx_forge_test_options'][ Cache::OPTION ] = array( 'route' => array( 'payload' => array() ) );
		$GLOBALS['bwx_forge_test_autoload_row']             = null;

		Cache::stop_autoloading();

		$this->assertArrayNotHasKey( Cache::LEAN_FLAG, $GLOBALS['bwx_forge_test_options'], 'Not marked done when it cannot tell.' );

		unset( $GLOBALS['bwx_forge_test_options'][ Cache::OPTION ] );

		Cache::stop_autoloading();

		$this->assertSame( '1', get_option( Cache::LEAN_FLAG ), 'With no copy at all, there is nothing left to do.' );
	}

	public function test_a_copy_that_was_the_last_to_load_is_still_marked_done(): void {
		$this->options_database();

		// Nothing is left to load, so WordPress's list hands back every option,
		// the copy among them. Its own row says it no longer loads.
		$GLOBALS['bwx_forge_test_alloptions']   = array( Cache::OPTION => 'x', 'siteurl' => 'x' );
		$GLOBALS['bwx_forge_test_autoload_row'] = 'off';

		Cache::stop_autoloading();

		$this->assertSame( '1', get_option( Cache::LEAN_FLAG ), 'Done once, not tried on every page.' );
	}

	public function test_uninstalling_the_client_plugin_removes_the_upgrade_flag(): void {
		bwx_forge_test_network( false );
		$GLOBALS['bwx_forge_test_calls'] = array();

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'blueworx-forge-client/blueworx-forge-client.php' );
		}

		require dirname( __DIR__, 2 ) . '/client/uninstall.php';

		$deleted = array_map(
			static fn( array $call ): string => (string) $call[1],
			array_filter( $GLOBALS['bwx_forge_test_calls'], static fn( array $call ): bool => 'delete_option' === $call[0] )
		);

		$this->assertContains( Cache::OPTION, $deleted );
		$this->assertContains( Cache::LEAN_FLAG, $deleted );
	}

	public function test_uninstalling_from_a_network_removes_the_flag_from_every_site(): void {
		bwx_forge_test_network();
		$GLOBALS['bwx_forge_test_net']['sites'][2] = array( 'domain' => 'two.network.test', 'path' => '/', 'title' => 'Two', 'options' => array() );
		$GLOBALS['bwx_forge_test_net']['sites'][3] = array( 'domain' => 'three.network.test', 'path' => '/', 'title' => 'Three', 'options' => array() );
		$GLOBALS['bwx_forge_test_calls']           = array();
		$seen                                      = array();

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'blueworx-forge-client/blueworx-forge-client.php' );
		}

		// Which site each deletion happened on.
		$GLOBALS['bwx_forge_test_on_delete'] = static function ( string $option ) use ( &$seen ): void {
			if ( Cache::LEAN_FLAG === $option ) {
				$seen[] = $GLOBALS['bwx_forge_test_net']['blog'];
			}
		};

		require dirname( __DIR__, 2 ) . '/client/uninstall.php';

		unset( $GLOBALS['bwx_forge_test_on_delete'] );

		$flags = array_filter( $GLOBALS['bwx_forge_test_calls'], static fn( array $call ): bool => 'delete_option' === $call[0] && Cache::LEAN_FLAG === $call[1] );

		$this->assertCount( 3, $flags, 'Once on each of the three sites.' );
		$this->assertSame( array( 1, 2, 3 ), $seen );
		$this->assertSame( 1, $GLOBALS['bwx_forge_test_net']['blog'], 'Back on the site it started from.' );
	}

	public function test_a_list_reads_a_thing_once(): void {
		$reads = 0;
		$read  = static function () use ( &$reads ): array {
			++$reads;

			return array( 'a' => 1 );
		};

		ListMemo::start();
		ListMemo::remember( 'open-questions', $read );
		ListMemo::remember( 'open-questions', $read );

		$this->assertSame( 1, $reads );
	}
}
