<?php
/**
 * The pure parts of #712: how many open questions each item has when a whole
 * list is read at once, and when a list keeps what it has read.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\Comments;
use Blueworx\Forge\Work\ListMemo;
use PHPUnit\Framework\TestCase;

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
