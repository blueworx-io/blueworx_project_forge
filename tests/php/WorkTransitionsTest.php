<?php
/**
 * What may move where.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\Transitions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The forward path from docs/architecture/workflow-state-machine.md, pinned.
 *
 * Single-step only, and that is the point rather than a limitation: a jump from
 * Triage to In Development skips the documentation, audit and design gates, and
 * a service that allows it quietly makes those gates optional.
 */
final class WorkTransitionsTest extends TestCase {

	/**
	 * Every forward move the state machine lists, and the work type each needs.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function forward_moves(): array {
		return array(
			'idea to triage'            => array( 'future-idea', 'triage', 'feature' ),
			'triage to bug tracking'    => array( 'triage', 'bug-tracking', 'bug' ),
			'triage to documentation'   => array( 'triage', 'documentation-period', 'feature' ),
			'bug tracking to docs'      => array( 'bug-tracking', 'documentation-period', 'bug' ),
			'docs to technical audit'   => array( 'documentation-period', 'technical-audit', 'feature' ),
			'audit to design'           => array( 'technical-audit', 'design-process', 'feature' ),
			'design to up next'         => array( 'design-process', 'up-next', 'feature' ),
			'up next to development'    => array( 'up-next', 'in-development', 'feature' ),
			'development to review'     => array( 'in-development', 'in-review', 'feature' ),
			'review to completed'       => array( 'in-review', 'completed', 'feature' ),
			'completed to released'     => array( 'completed', 'released', 'feature' ),
		);
	}

	/**
	 * Each one is allowed.
	 *
	 *
	 * @param string $from      Stage moving from.
	 * @param string $to        Stage moving to.
	 * @param string $work_type The item's work type.
	 */
	#[DataProvider( 'forward_moves' )]
	public function test_every_forward_move_in_the_state_machine_is_allowed( string $from, string $to, string $work_type ): void {
		$this->assertTrue( Transitions::allowed( $from, $to, $work_type ), "{$from} → {$to} should be allowed" );
	}

	/**
	 * A jump that skips a stage is refused. Three gates live between these two.
	 */
	public function test_a_multi_step_jump_is_refused(): void {
		$this->assertFalse( Transitions::allowed( 'triage', 'in-development', 'feature' ) );
		$this->assertFalse( Transitions::allowed( 'future-idea', 'completed', 'feature' ) );
	}

	/**
	 * Backwards is not a forward move. Returns are real (WF-3) but they are
	 * their own path with their own mandatory reason, and #108 owns them.
	 */
	public function test_moving_backwards_is_not_a_forward_move(): void {
		$this->assertFalse( Transitions::allowed( 'in-review', 'in-development', 'feature' ) );
		$this->assertFalse( Transitions::allowed( 'released', 'completed', 'feature' ) );
	}

	/**
	 * Bug Tracking is conditional on the work type (WF-1): a bug goes through it
	 * and nothing else may.
	 */
	public function test_only_a_bug_may_enter_bug_tracking(): void {
		$this->assertTrue( Transitions::allowed( 'triage', 'bug-tracking', 'bug' ) );

		foreach ( array( 'feature', 'feedback', 'task' ) as $type ) {
			$this->assertFalse( Transitions::allowed( 'triage', 'bug-tracking', $type ), "a {$type} should not enter bug tracking" );
		}
	}

	/**
	 * And a bug still goes to Documentation Period after it, like everything
	 * else — Bug Tracking is a stage on the way, not a separate pipeline.
	 */
	public function test_a_bug_rejoins_the_ordinary_path(): void {
		$this->assertTrue( Transitions::allowed( 'bug-tracking', 'documentation-period', 'bug' ) );
		$this->assertTrue( Transitions::allowed( 'documentation-period', 'technical-audit', 'bug' ) );
	}

	/**
	 * Released is the end of the forward path.
	 */
	public function test_nothing_moves_forward_out_of_released(): void {
		$this->assertSame( array(), Transitions::next_from( 'released', 'feature' ) );
	}

	/**
	 * Blocked is not reachable by a forward move. It is an exception state with
	 * its own entry and exit (#109), and a board that offered it as "the next
	 * stage" would have people blocking work by dragging it one column right.
	 */
	public function test_blocked_is_not_on_the_forward_path(): void {
		foreach ( array( 'triage', 'in-development', 'in-review' ) as $from ) {
			$this->assertNotContains( 'blocked', Transitions::next_from( $from, 'feature' ) );
		}
	}

	/**
	 * What a board offers as the next step, per work type. Triage is the only
	 * fork in the forward path, and which branch is offered is decided here
	 * rather than by the screen.
	 */
	public function test_triage_offers_one_next_stage_per_work_type(): void {
		$this->assertSame( array( 'bug-tracking' ), Transitions::next_from( 'triage', 'bug' ) );
		$this->assertSame( array( 'documentation-period' ), Transitions::next_from( 'triage', 'feature' ) );
	}

	/**
	 * Every allowed move names the gate it has to satisfy. The gates themselves
	 * arrive with #105; what matters now is that no move is left without one,
	 * because that is how a gate gets quietly skipped later.
	 */
	public function test_every_allowed_move_names_a_gate(): void {
		foreach ( self::forward_moves() as $move ) {
			list( $from, $to, $work_type ) = $move;

			$this->assertNotSame( '', Transitions::gate_for( $from, $to ), "{$from} → {$to} must name a gate" );
			$this->assertTrue( Transitions::allowed( $from, $to, $work_type ) );
		}
	}

	/**
	 * A new item enters at Future Idea and nowhere else.
	 */
	public function test_an_item_can_only_start_at_future_idea(): void {
		$this->assertTrue( Transitions::may_start( 'future-idea' ) );

		foreach ( array( 'triage', 'in-development', 'completed', 'blocked' ) as $stage ) {
			$this->assertFalse( Transitions::may_start( $stage ), "work must not start at {$stage}" );
		}
	}

	/**
	 * A stage nobody has defined is refused on both sides, rather than
	 * falling through to "not in the table, so no".
	 */
	public function test_an_invented_stage_is_refused(): void {
		$this->assertFalse( Transitions::allowed( 'triage', 'nearly-done', 'feature' ) );
		$this->assertFalse( Transitions::allowed( 'nearly-done', 'triage', 'feature' ) );
	}
}
