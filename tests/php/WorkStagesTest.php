<?php
/**
 * The twelve stages, and that they cannot be changed.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\Stages;
use PHPUnit\Framework\TestCase;

/**
 * #104 asks for stages that cannot be created, renamed, reordered or deleted
 * **by design rather than by permission**, proved by test rather than by policy.
 *
 * The reason is that everything else is written against these twelve. The gates
 * name them, the transition table maps them, reports group by them, and the
 * board draws a column per stage. A renameable stage set turns all of that into
 * data that can disagree with the code reading it.
 */
final class WorkStagesTest extends TestCase {

	/**
	 * The twelve, in the order the state machine gives them.
	 */
	public function test_there_are_twelve_stages_in_order(): void {
		$this->assertSame(
			array(
				'future-idea',
				'triage',
				'bug-tracking',
				'documentation-period',
				'technical-audit',
				'design-process',
				'blocked',
				'up-next',
				'in-development',
				'in-review',
				'completed',
				'released',
			),
			Stages::ALL
		);
	}

	/**
	 * Nothing can change the set. Asserted by absence rather than by trusting a
	 * comment: there is no method here that could.
	 */
	public function test_no_method_can_change_the_stage_set(): void {
		$methods = get_class_methods( Stages::class );

		foreach ( array( 'add', 'create', 'rename', 'reorder', 'remove', 'delete', 'set', 'register' ) as $forbidden ) {
			$this->assertNotContains( $forbidden, $methods, 'Stages must not be editable at all.' );
		}
	}

	/**
	 * And no filter dressed up as a read. A stage list that could be filtered is
	 * a stage list a plugin can rewrite.
	 */
	public function test_reading_the_stages_fires_no_filter(): void {
		$GLOBALS['bwx_forge_test_actions'] = array();

		Stages::ALL;
		Stages::kind( 'triage' );
		Stages::position( 'triage' );

		$this->assertSame( array(), $GLOBALS['bwx_forge_test_actions'] );
	}

	/**
	 * Each stage knows what kind it is. Bug Tracking is entered only from Triage
	 * and only for a bug, and Blocked is an exception state that stores where it
	 * came from — neither is an ordinary linear stage (WF-1).
	 */
	public function test_the_conditional_and_exception_stages_are_marked_as_such(): void {
		$this->assertSame( Stages::CONDITIONAL, Stages::kind( 'bug-tracking' ) );
		$this->assertSame( Stages::EXCEPTION, Stages::kind( 'blocked' ) );
		$this->assertSame( Stages::LINEAR, Stages::kind( 'triage' ) );
		$this->assertSame( Stages::LINEAR, Stages::kind( 'released' ) );
	}

	/**
	 * Every stage has a kind and a label; none is left to a default.
	 */
	public function test_every_stage_is_fully_described(): void {
		foreach ( Stages::ALL as $stage ) {
			$this->assertContains( Stages::kind( $stage ), array( Stages::LINEAR, Stages::CONDITIONAL, Stages::EXCEPTION ) );
			$this->assertNotSame( '', Stages::label( $stage ) );
		}
	}

	/**
	 * A stage nobody has defined is not a stage.
	 */
	public function test_an_invented_stage_does_not_exist(): void {
		$this->assertFalse( Stages::exists( 'nearly-done' ) );
		$this->assertSame( -1, Stages::position( 'nearly-done' ) );
	}

	/**
	 * Work starts at Future Idea and nowhere else. The board's first column and
	 * the create route both read this rather than each naming a stage.
	 */
	public function test_work_starts_at_future_idea(): void {
		$this->assertSame( 'future-idea', Stages::FIRST );
		$this->assertSame( 0, Stages::position( Stages::FIRST ) );
	}

	/**
	 * The board draws a column per stage, but not for the two that are not
	 * places work queues up — an item is blocked *from* somewhere, and Bug
	 * Tracking only exists for bugs.
	 */
	public function test_the_linear_stages_are_the_ones_a_board_shows(): void {
		$this->assertNotContains( 'blocked', Stages::linear() );
		$this->assertNotContains( 'bug-tracking', Stages::linear() );
		$this->assertContains( 'triage', Stages::linear() );
		$this->assertCount( 10, Stages::linear() );
	}
}
