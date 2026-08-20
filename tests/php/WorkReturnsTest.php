<?php
/**
 * Which way back is a way back.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\Returns;
use PHPUnit\Framework\TestCase;

/**
 * #108. The rule is one sentence and this file is the proof of it: backwards,
 * to a stage the item has actually occupied, with a reason.
 */
final class WorkReturnsTest extends TestCase {

	/**
	 * A changelog for an item that walked the ordinary path.
	 *
	 * @param array<int, array{string, string}> $moves from → to pairs.
	 * @param int                               $cycle Which cycle they are in.
	 * @return array<int, array<string, mixed>>
	 */
	private function history( array $moves, int $cycle = 1 ): array {
		$history = array();

		foreach ( $moves as $move ) {
			$history[] = array(
				'from_stage' => $move[0],
				'to_stage'   => $move[1],
				'cycle'      => $cycle,
			);
		}

		return $history;
	}

	/**
	 * An item.
	 *
	 * @param string $stage     Where it is.
	 * @param string $work_type What kind of work.
	 * @return array<string, mixed>
	 */
	private function item( string $stage, string $work_type = 'feature' ): array {
		return array(
			'id'        => 'wrk_1',
			'stage'     => $stage,
			'work_type' => $work_type,
			'cycle'     => 1,
		);
	}

	/**
	 * The stages read out of the changelog are the ones the item has been in.
	 */
	public function test_occupied_reads_the_changelog(): void {
		$history = $this->history(
			array(
				array( '', 'future-idea' ),
				array( 'future-idea', 'triage' ),
				array( 'triage', 'documentation-period' ),
			)
		);

		$this->assertSame(
			array( 'future-idea', 'triage', 'documentation-period' ),
			Returns::occupied( $history, 1 )
		);
	}

	/**
	 * Only earlier stages, and only occupied ones.
	 */
	public function test_targets_are_earlier_stages_the_item_has_been_in(): void {
		$history = $this->history(
			array(
				array( '', 'future-idea' ),
				array( 'future-idea', 'triage' ),
				array( 'triage', 'documentation-period' ),
				array( 'documentation-period', 'technical-audit' ),
			)
		);

		$targets = Returns::targets( $this->item( 'technical-audit' ), $history );

		$this->assertSame( array( 'future-idea', 'triage', 'documentation-period' ), $targets );
	}

	/**
	 * #108's first acceptance. A stage the item has never been in is not a
	 * return, whatever direction it is in.
	 */
	public function test_an_unoccupied_stage_is_refused(): void {
		$history = $this->history(
			array(
				array( '', 'future-idea' ),
				array( 'future-idea', 'triage' ),
			)
		);

		$item = $this->item( 'triage' );

		$this->assertTrue( Returns::allowed( $item, 'future-idea', $history ) );
		$this->assertFalse( Returns::allowed( $item, 'design-process', $history ) );
		$this->assertFalse( Returns::allowed( $item, 'bug-tracking', $history ) );
	}

	/**
	 * Forwards is not a return, even to somewhere the item has been. An item
	 * sent back and then moved on again goes forwards through its gate.
	 */
	public function test_forwards_is_never_a_return(): void {
		$history = $this->history(
			array(
				array( '', 'future-idea' ),
				array( 'future-idea', 'triage' ),
				array( 'triage', 'documentation-period' ),
				array( 'documentation-period', 'triage' ),
			)
		);

		$this->assertFalse( Returns::allowed( $this->item( 'triage' ), 'documentation-period', $history ) );
	}

	/**
	 * #110 again, from the other direction: a work type that may not hold a
	 * stage may not be sent back into it either.
	 */
	public function test_a_non_bug_cannot_be_returned_to_bug_tracking(): void {
		$history = $this->history(
			array(
				array( 'triage', 'bug-tracking' ),
				array( 'bug-tracking', 'documentation-period' ),
			)
		);

		$this->assertTrue( Returns::allowed( $this->item( 'documentation-period', 'bug' ), 'bug-tracking', $history ) );
		$this->assertFalse( Returns::allowed( $this->item( 'documentation-period', 'feature' ), 'bug-tracking', $history ) );
	}

	/**
	 * Blocked is never a return target. Leaving Blocked is its own move with its
	 * own gate, and it has exactly one destination.
	 */
	public function test_blocked_is_never_a_return_target(): void {
		$history = $this->history(
			array(
				array( 'future-idea', 'triage' ),
				array( 'triage', 'blocked' ),
				array( 'blocked', 'triage' ),
				array( 'triage', 'documentation-period' ),
			)
		);

		$this->assertNotContains( 'blocked', Returns::targets( $this->item( 'documentation-period' ), $history ) );
	}

	/**
	 * A blocked item is not sent back from where it is: it is unblocked.
	 */
	public function test_a_blocked_item_has_no_return_targets(): void {
		$history = $this->history(
			array(
				array( 'future-idea', 'triage' ),
				array( 'triage', 'blocked' ),
			)
		);

		$this->assertSame( array(), Returns::targets( $this->item( 'blocked' ), $history ) );
	}

	/**
	 * An earlier cycle's history is not this cycle's. A reopened item has been
	 * round once already, and that does not make every stage a return target.
	 */
	public function test_another_cycle_does_not_count(): void {
		$history = array_merge(
			$this->history( array( array( 'documentation-period', 'technical-audit' ) ), 1 ),
			$this->history( array( array( '', 'documentation-period' ) ), 2 )
		);

		$item = array(
			'id'        => 'wrk_1',
			'stage'     => 'technical-audit',
			'work_type' => 'feature',
			'cycle'     => 2,
		);

		$this->assertSame( array( 'documentation-period' ), Returns::targets( $item, $history ) );
	}

	/**
	 * The failed review is recognised as itself, because it asks for more than
	 * an ordinary return does.
	 */
	public function test_the_review_return_is_recognised(): void {
		$this->assertTrue( Returns::is_review_return( 'in-review', 'in-development' ) );
		$this->assertFalse( Returns::is_review_return( 'in-review', 'documentation-period' ) );
		$this->assertFalse( Returns::is_review_return( 'completed', 'in-development' ) );
	}
}
