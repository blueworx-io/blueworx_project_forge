<?php
/**
 * Which gate records count for an item right now.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\GateRecords;
use PHPUnit\Framework\TestCase;

/**
 * #388. The standup reads every item's gate records in one query and picks
 * each item's current ones out of that; the item screens read one item's.
 * Both go through the same pure filter, so the board and the transition route
 * cannot disagree about what counts.
 */
final class GateRecordsCurrentTest extends TestCase {

	/**
	 * One record.
	 *
	 * @param string $requirement Requirement id.
	 * @param int    $cycle       Cycle.
	 * @param string $gate        Gate.
	 * @param int    $attempt     Review attempt.
	 * @param string $value       Value.
	 * @return array<string, mixed>
	 */
	private function record( string $requirement, int $cycle = 1, string $gate = 'G-UP-NEXT', int $attempt = 1, string $value = 'yes' ): array {
		return array(
			'id'           => 'grc_' . $requirement . $cycle . $attempt . $value,
			'item_id'      => 'itm_1',
			'gate'         => $gate,
			'requirement'  => $requirement,
			'value'        => $value,
			'evidence'     => '',
			'cycle'        => $cycle,
			'attempt'      => $attempt,
			'actor'        => 1,
			'completed_at' => 1,
		);
	}

	public function test_only_this_cycle_counts(): void {
		$current = GateRecords::current_among(
			array(
				'cycle'          => 2,
				'review_attempt' => 1,
			),
			array( $this->record( 'A', 1 ), $this->record( 'B', 2 ) )
		);

		$this->assertSame( array( 'B' ), array_keys( $current ) );
	}

	public function test_the_review_gate_resets_per_attempt_and_nothing_else_does(): void {
		$current = GateRecords::current_among(
			array(
				'cycle'          => 1,
				'review_attempt' => 2,
			),
			array(
				$this->record( 'R1', 1, 'G-IN-REVIEW', 1 ),
				$this->record( 'R2', 1, 'G-IN-REVIEW', 2 ),
				$this->record( 'D1', 1, 'G-UP-NEXT', 1 ),
			)
		);

		$this->assertSame( array( 'R2', 'D1' ), array_keys( $current ) );
	}

	public function test_the_newest_record_for_a_requirement_answers_it(): void {
		$current = GateRecords::current_among(
			array(),
			array( $this->record( 'A', 1, 'G-UP-NEXT', 1, 'old' ), $this->record( 'A', 1, 'G-UP-NEXT', 1, 'new' ) )
		);

		$this->assertSame( 'new', $current['A']['value'] );
	}
}
