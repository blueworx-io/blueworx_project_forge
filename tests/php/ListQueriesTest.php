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

		unset( $GLOBALS['bwx_forge_test_autoload'], $GLOBALS['bwx_forge_test_options'][ Cache::LEAN_FLAG ] );
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
		$GLOBALS['bwx_forge_test_options'][ Cache::OPTION ] = array( 'route' => array( 'payload' => array() ) );

		Cache::stop_autoloading();

		$this->assertFalse( $GLOBALS['bwx_forge_test_autoload'][ Cache::OPTION ] );
		$this->assertSame( array( 'route' => array( 'payload' => array() ) ), get_option( Cache::OPTION ), 'The copy itself is kept.' );

		unset( $GLOBALS['bwx_forge_test_autoload'] );
		Cache::stop_autoloading();

		$this->assertArrayNotHasKey( 'bwx_forge_test_autoload', $GLOBALS, 'It is done once, not on every page.' );
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
