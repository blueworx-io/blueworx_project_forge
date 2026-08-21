<?php
/**
 * The ways work ends without being released.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\Outcomes;
use Blueworx\Forge\Work\Stages;
use PHPUnit\Framework\TestCase;

/**
 * #111, one test per row of the terminal outcomes table.
 */
final class WorkOutcomesTest extends TestCase {

	/**
	 * An item.
	 *
	 * @param string $stage   Where it is.
	 * @param string $outcome Its outcome, if it has one.
	 * @return array<string, mixed>
	 */
	private function item( string $stage, string $outcome = '' ): array {
		return array(
			'id'               => 'wrk_1',
			'stage'            => $stage,
			'work_type'        => 'feature',
			'terminal_outcome' => $outcome,
			'archived'         => false,
		);
	}

	/**
	 * Rejected and Duplicate are Triage's alone. A rejection five stages in is
	 * a cancellation, and calling it a rejection would make the reports lie
	 * about where work dies.
	 */
	public function test_rejected_and_duplicate_are_reachable_from_triage_only(): void {
		foreach ( array( Outcomes::REJECTED, Outcomes::DUPLICATE ) as $outcome ) {
			$this->assertTrue( Outcomes::reachable_from( $outcome, 'triage' ) );

			foreach ( Stages::ALL as $stage ) {
				if ( 'triage' === $stage ) {
					continue;
				}

				$this->assertFalse( Outcomes::reachable_from( $outcome, $stage ), "{$outcome} should not be reachable from {$stage}" );
			}
		}
	}

	/**
	 * Cancelled is reachable from anywhere work is actually in progress, and
	 * from nowhere else.
	 */
	public function test_cancelled_is_reachable_from_any_active_stage(): void {
		$this->assertTrue( Outcomes::reachable_from( Outcomes::CANCELLED, 'in-development' ) );
		$this->assertTrue( Outcomes::reachable_from( Outcomes::CANCELLED, 'future-idea' ) );
		$this->assertFalse( Outcomes::reachable_from( Outcomes::CANCELLED, 'released' ) );
		$this->assertFalse( Outcomes::reachable_from( Outcomes::CANCELLED, 'blocked' ) );
	}

	/**
	 * Deferred is Triage's and Up Next's, and nowhere in between.
	 */
	public function test_deferred_is_reachable_from_triage_and_up_next(): void {
		$this->assertTrue( Outcomes::reachable_from( Outcomes::DEFERRED, 'triage' ) );
		$this->assertTrue( Outcomes::reachable_from( Outcomes::DEFERRED, 'up-next' ) );
		$this->assertFalse( Outcomes::reachable_from( Outcomes::DEFERRED, 'in-development' ) );
	}

	/**
	 * Deferred puts the item back to being an idea, and leaves it open. It is
	 * the one outcome that is a decision rather than an ending.
	 */
	public function test_deferred_returns_the_item_to_future_idea_and_leaves_it_open(): void {
		$definition = (array) Outcomes::definition( Outcomes::DEFERRED );

		$this->assertSame( Stages::FIRST, $definition['returns_to'] );
		$this->assertTrue( $definition['open'] );
		$this->assertFalse( Outcomes::is_closed( $this->item( 'future-idea', Outcomes::DEFERRED ) ) );
	}

	/**
	 * All four stay in reporting. That is WF-2, and the reason nothing here
	 * deletes anything.
	 */
	public function test_every_outcome_is_still_counted(): void {
		foreach ( Outcomes::ALL as $outcome ) {
			$definition = (array) Outcomes::definition( $outcome );

			$this->assertTrue( $definition['counted'], "{$outcome} should still be counted" );
		}
	}

	/**
	 * A duplicate is attributed to the item that survived, so counting it in
	 * throughput would count one piece of work twice.
	 */
	public function test_a_duplicate_needs_the_surviving_item(): void {
		$definition = (array) Outcomes::definition( Outcomes::DUPLICATE );

		$this->assertSame( 'duplicate_of', $definition['needs'] );
		$this->assertFalse( $definition['throughput'] );
	}

	/**
	 * Work that has ended is offered nothing further.
	 */
	public function test_ended_work_has_no_outcomes_left(): void {
		$this->assertNotSame( array(), Outcomes::available_for( $this->item( 'triage' ) ) );
		$this->assertSame( array(), Outcomes::available_for( $this->item( 'triage', Outcomes::CANCELLED ) ) );
	}

	/**
	 * Archiving is for work that has ended — at an outcome or at Released — and
	 * happens once.
	 */
	public function test_only_ended_work_can_be_archived(): void {
		$this->assertFalse( Outcomes::may_archive( $this->item( 'in-development' ) ) );
		$this->assertTrue( Outcomes::may_archive( $this->item( 'released' ) ) );
		$this->assertTrue( Outcomes::may_archive( $this->item( 'triage', Outcomes::REJECTED ) ) );

		$already             = $this->item( 'released' );
		$already['archived'] = true;

		$this->assertFalse( Outcomes::may_archive( $already ) );
	}
}
