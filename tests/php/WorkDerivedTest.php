<?php
/**
 * Tests for what a parent reads as, given the work beneath it.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\Derived;
use PHPUnit\Framework\TestCase;

/**
 * WORK-2, and #101: a parent reflects the work beneath it and cannot be talked
 * up by hand.
 *
 * The whole point is that none of this is authored. Progress, state and dates
 * are computed from the children every time they are read, so there is no
 * stored value for anybody to write — which is a stronger guarantee than
 * refusing the write, because there is nothing to refuse.
 */
final class WorkDerivedTest extends TestCase {

	/**
	 * A child, in the shape Items::children() returns.
	 *
	 * @param string $stage   Which stage it sits at.
	 * @param array  $extra   Anything else the test cares about.
	 * @return array<string, mixed>
	 */
	private function child( string $stage, array $extra = array() ): array {
		return array_merge(
			array(
				'stage'            => $stage,
				'terminal_outcome' => '',
				'archived'         => 0,
				'planned_start'    => '',
				'planned_due'      => '',
			),
			$extra
		);
	}

	// -----------------------------------------------------------------------
	// State.
	// -----------------------------------------------------------------------

	/**
	 * The distinction #101 asks for by name. A parent with nothing beneath it is
	 * empty, which is a different thing from work that has not been started —
	 * one needs breaking down and the other needs doing.
	 */
	public function test_a_parent_with_no_children_is_empty(): void {
		$this->assertSame( Derived::EMPTY_PARENT, Derived::from( array() )['state'] );
	}

	/**
	 * Everything still at the beginning is not started.
	 */
	public function test_a_parent_whose_children_have_not_begun_has_not_started(): void {
		$state = Derived::from( array( $this->child( 'future-idea' ), $this->child( 'triage' ) ) );

		$this->assertSame( Derived::NOT_STARTED, $state['state'] );
	}

	/**
	 * One child moving is enough. A parent that still reads "not started" while
	 * somebody is working on it is the report nobody trusts.
	 */
	public function test_one_child_under_way_makes_the_parent_under_way(): void {
		$state = Derived::from( array( $this->child( 'future-idea' ), $this->child( 'in-development' ) ) );

		$this->assertSame( Derived::IN_PROGRESS, $state['state'] );
	}

	/**
	 * A parent reaches Completed only when everything beneath it is Completed.
	 */
	public function test_a_parent_is_complete_only_when_every_child_is(): void {
		$all  = Derived::from( array( $this->child( 'completed' ), $this->child( 'released' ) ) );
		$some = Derived::from( array( $this->child( 'completed' ), $this->child( 'in-review' ) ) );

		$this->assertSame( Derived::COMPLETED, $all['state'] );
		$this->assertSame( Derived::IN_PROGRESS, $some['state'] );
	}

	/**
	 * Work that ended without being done is not work anybody is still waiting
	 * for. A cancelled child holding its parent open forever is the bug this
	 * prevents.
	 */
	public function test_a_child_that_ended_without_being_done_does_not_hold_the_parent(): void {
		$state = Derived::from(
			array(
				$this->child( 'completed' ),
				$this->child( 'triage', array( 'terminal_outcome' => 'cancelled' ) ),
			)
		);

		$this->assertSame( Derived::COMPLETED, $state['state'] );
	}

	/**
	 * And nor does one that has been put out of the way (#111).
	 */
	public function test_an_archived_child_does_not_hold_the_parent(): void {
		$state = Derived::from(
			array(
				$this->child( 'released' ),
				$this->child( 'in-development', array( 'archived' => 1 ) ),
			)
		);

		$this->assertSame( Derived::COMPLETED, $state['state'] );
	}

	/**
	 * A parent whose every child ended without being done is empty rather than
	 * complete. Nothing was delivered, and saying "completed" would put it in a
	 * throughput report as work that shipped.
	 */
	public function test_a_parent_whose_children_all_ended_unfinished_is_empty(): void {
		$state = Derived::from(
			array(
				$this->child( 'triage', array( 'terminal_outcome' => 'cancelled' ) ),
				$this->child( 'triage', array( 'terminal_outcome' => 'rejected' ) ),
			)
		);

		$this->assertSame( Derived::EMPTY_PARENT, $state['state'] );
	}

	// -----------------------------------------------------------------------
	// Progress.
	// -----------------------------------------------------------------------

	/**
	 * Progress is the share of the children that are finished, which is the only
	 * measure that needs nothing anybody has to keep up to date by hand.
	 */
	public function test_progress_is_the_share_of_children_that_are_done(): void {
		$state = Derived::from(
			array(
				$this->child( 'completed' ),
				$this->child( 'completed' ),
				$this->child( 'in-development' ),
				$this->child( 'future-idea' ),
			)
		);

		$this->assertSame( 50, $state['progress'] );
	}

	/**
	 * An empty parent is not zero per cent through its work — it has no work.
	 * The two read the same on a bar and mean different things, so the state is
	 * what a screen should show.
	 */
	public function test_an_empty_parent_reads_zero_without_claiming_to_have_started(): void {
		$state = Derived::from( array() );

		$this->assertSame( 0, $state['progress'] );
		$this->assertSame( Derived::EMPTY_PARENT, $state['state'] );
	}

	/**
	 * Finished is finished.
	 */
	public function test_everything_done_is_a_hundred(): void {
		$this->assertSame( 100, Derived::from( array( $this->child( 'released' ) ) )['progress'] );
	}

	// -----------------------------------------------------------------------
	// Dates.
	// -----------------------------------------------------------------------

	/**
	 * A parent starts when its earliest child does and is due when its latest
	 * child is. Nobody types these, so they cannot disagree with the plan.
	 */
	public function test_the_dates_span_the_children(): void {
		$state = Derived::from(
			array(
				$this->child(
					'up-next',
					array(
						'planned_start' => '2026-09-10',
						'planned_due'   => '2026-09-20',
					)
				),
				$this->child(
					'up-next',
					array(
						'planned_start' => '2026-09-01',
						'planned_due'   => '2026-09-15',
					)
				),
			)
		);

		$this->assertSame( '2026-09-01', $state['start'] );
		$this->assertSame( '2026-09-20', $state['due'] );
	}

	/**
	 * A child with no dates yet contributes none, rather than contributing an
	 * empty string that sorts before every real date.
	 */
	public function test_a_child_with_no_dates_does_not_drag_the_span(): void {
		$state = Derived::from(
			array(
				$this->child( 'future-idea' ),
				$this->child(
					'up-next',
					array(
						'planned_start' => '2026-09-05',
						'planned_due'   => '2026-09-25',
					)
				),
			)
		);

		$this->assertSame( '2026-09-05', $state['start'] );
		$this->assertSame( '2026-09-25', $state['due'] );
	}

	/**
	 * And a parent whose children have no dates has none, rather than today's.
	 */
	public function test_a_parent_with_no_dated_children_has_no_dates(): void {
		$state = Derived::from( array( $this->child( 'future-idea' ) ) );

		$this->assertSame( '', $state['start'] );
		$this->assertSame( '', $state['due'] );
	}

	// -----------------------------------------------------------------------
	// The gate.
	// -----------------------------------------------------------------------

	/**
	 * The rule as the transition service asks it: may this parent be completed?
	 */
	public function test_a_parent_with_one_unfinished_child_may_not_complete(): void {
		$this->assertFalse(
			Derived::may_complete( array( $this->child( 'completed' ), $this->child( 'in-review' ) ) )
		);
	}

	/**
	 * A parent with nothing beneath it is not blocked by this rule. It is an
	 * item in its own right, and the rule is about children it does not have.
	 */
	public function test_a_parent_with_no_children_may_complete(): void {
		$this->assertTrue( Derived::may_complete( array() ) );
	}

	/**
	 * And with every child done, it may.
	 */
	public function test_a_parent_whose_children_are_all_done_may_complete(): void {
		$this->assertTrue(
			Derived::may_complete( array( $this->child( 'completed' ), $this->child( 'released' ) ) )
		);
	}

	// -----------------------------------------------------------------------
	// Nothing here is authored.
	// -----------------------------------------------------------------------

	/**
	 * The derived names are not writable fields. This is the "no direct edit
	 * path" half of #101, and it is checked here rather than trusted: a field
	 * added to the writable list later would otherwise let somebody set the
	 * progress of a parent by hand.
	 */
	public function test_no_derived_value_can_be_written(): void {
		$writable = \Blueworx\Forge\Work\Fields::writable();

		foreach ( Derived::NAMES as $name ) {
			$this->assertNotContains( $name, $writable, sprintf( '%s can be written by hand', $name ) );
		}
	}
}
