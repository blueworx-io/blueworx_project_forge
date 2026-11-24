<?php
/**
 * The pure parts of #702: what all work keeps out, how a kept answer is
 * filed, and what counts as a change.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Rest\Answers;
use Blueworx\Forge\Work\Items;
use Blueworx\Forge\Work\ListMemo;
use PHPUnit\Framework\TestCase;

/**
 * #702. All work used to read every site twice; it now reads them all once and
 * narrows in memory, so the narrowing has to say exactly what the query did.
 */
final class BoardSpeedTest extends TestCase {

	/**
	 * Four items on one site.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function family(): array {
		return array(
			array( 'id' => 'a', 'stage' => 'up-next', 'level' => 'feature', 'work_type' => 'bug', 'parent_id' => '', 'archived' => false ),
			array( 'id' => 'b', 'stage' => 'released', 'level' => 'milestone', 'work_type' => 'task', 'parent_id' => '', 'archived' => false ),
			array( 'id' => 'c', 'stage' => 'up-next', 'level' => 'sub-feature', 'work_type' => 'task', 'parent_id' => 'a', 'archived' => false ),
			array( 'id' => 'd', 'stage' => 'up-next', 'level' => 'feature', 'work_type' => 'bug', 'parent_id' => '', 'archived' => true ),
		);
	}

	/**
	 * The ids of a list.
	 *
	 * @param array<int, array<string, mixed>> $items Items.
	 * @return array<int, string>
	 */
	private function ids( array $items ): array {
		return array_column( $items, 'id' );
	}

	public function test_no_narrowing_keeps_everything_but_archived_work(): void {
		$this->assertSame( array( 'a', 'b', 'c' ), $this->ids( Items::narrow( $this->family(), array() ) ) );
	}

	public function test_archived_work_comes_back_only_when_asked_for(): void {
		$this->assertSame( array( 'a', 'b', 'c', 'd' ), $this->ids( Items::narrow( $this->family(), array( 'include_archived' => true ) ) ) );
		$this->assertSame( array( 'a', 'b', 'c' ), $this->ids( Items::narrow( $this->family(), array( 'include_archived' => false ) ) ) );
	}

	public function test_one_value_narrows_on_its_column(): void {
		$this->assertSame( array( 'a', 'c' ), $this->ids( Items::narrow( $this->family(), array( 'stage' => 'up-next' ) ) ) );
		$this->assertSame( array( 'c' ), $this->ids( Items::narrow( $this->family(), array( 'parent_id' => 'a' ) ) ) );
	}

	public function test_several_values_keep_any_of_them_and_an_empty_list_narrows_nothing(): void {
		$this->assertSame( array( 'a', 'b' ), $this->ids( Items::narrow( $this->family(), array( 'level' => array( 'feature', 'milestone' ) ) ) ) );
		$this->assertSame( array( 'a', 'b', 'c' ), $this->ids( Items::narrow( $this->family(), array( 'level' => array() ) ) ) );
	}

	public function test_narrowings_combine(): void {
		$this->assertSame( array( 'a' ), $this->ids( Items::narrow( $this->family(), array( 'stage' => 'up-next', 'work_type' => 'bug' ) ) ) );
	}

	public function test_a_kept_answer_is_filed_by_question_person_day_and_change(): void {
		$base = Answers::key( '/blueworx-forge/v1/standup', array( 'view' => 'week' ), 1, '2026-11-24', 7 );

		$this->assertSame( $base, Answers::key( '/blueworx-forge/v1/standup', array( 'view' => 'week' ), 1, '2026-11-24', 7 ) );
		$this->assertNotSame( $base, Answers::key( '/blueworx-forge/v1/standup', array( 'view' => 'today' ), 1, '2026-11-24', 7 ) );
		$this->assertNotSame( $base, Answers::key( '/blueworx-forge/v1/standup', array( 'view' => 'week' ), 2, '2026-11-24', 7 ) );
		$this->assertNotSame( $base, Answers::key( '/blueworx-forge/v1/standup', array( 'view' => 'week' ), 1, '2026-11-25', 7 ) );
		$this->assertNotSame( $base, Answers::key( '/blueworx-forge/v1/standup', array( 'view' => 'week' ), 1, '2026-11-24', 8 ) );
		$this->assertLessThanOrEqual( 172, strlen( $base ), 'A transient name WordPress can store.' );
	}

	public function test_the_order_of_a_query_does_not_change_its_key(): void {
		$this->assertSame(
			Answers::key( '/r', array( 'a' => '1', 'b' => '2' ), 1, '', 1 ),
			Answers::key( '/r', array( 'b' => '2', 'a' => '1' ), 1, '', 1 )
		);
	}

	public function test_any_write_counts_as_a_change_and_no_read_does(): void {
		$this->assertTrue( Answers::is_change( 'POST', '/blueworx-forge/v1/work-items/wi_1/move' ) );
		$this->assertTrue( Answers::is_change( 'delete', '/blueworx-forge/v1/reminders/r_1' ) );
		$this->assertTrue( Answers::is_change( 'POST', '/wp/v2/users/3' ) );
		$this->assertFalse( Answers::is_change( 'GET', '/blueworx-forge/v1/standup' ) );
		$this->assertFalse( Answers::is_change( 'HEAD', '/blueworx-forge/v1/standup' ) );
	}

	public function test_a_list_reads_once_and_anything_else_reads_every_time(): void {
		$reads = 0;
		$read  = static function () use ( &$reads ): int {
			return ++$reads;
		};

		ListMemo::remember( 'k', $read );
		ListMemo::remember( 'k', $read );
		$this->assertSame( 2, $reads, 'Off: every read happens.' );

		ListMemo::start();
		ListMemo::remember( 'k', $read );
		ListMemo::remember( 'k', $read );
		ListMemo::remember( 'other', $read );
		ListMemo::stop();
		$this->assertSame( 4, $reads, 'On: once per key.' );

		ListMemo::remember( 'k', $read );
		$this->assertSame( 5, $reads, 'Stopped: forgotten.' );
	}
}
