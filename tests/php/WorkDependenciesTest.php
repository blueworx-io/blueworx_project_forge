<?php
/**
 * Tests for work that waits on other work.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\Dependencies;
use PHPUnit\Framework\TestCase;

/**
 * #103. Work that waits on other work says so, and a dependency that is
 * unscheduled or blocked is surfaced rather than hidden.
 *
 * That last part is the whole issue. A dependency list that only says "waiting
 * on three things" is the same as no list: the question somebody actually has
 * is "is any of this going to move", and the two answers that mean it will not
 * are the two this makes loud.
 */
final class WorkDependenciesTest extends TestCase {

	/**
	 * A dependency, in the shape the item it points at comes back in.
	 *
	 * @param string $id    Its id.
	 * @param string $stage Which stage it sits at.
	 * @param array  $extra Anything else the test cares about.
	 * @return array<string, mixed>
	 */
	private function upstream( string $id, string $stage, array $extra = array() ): array {
		return array_merge(
			array(
				'id'               => $id,
				'title'            => 'Upstream ' . $id,
				'stage'            => $stage,
				'terminal_outcome' => '',
				'planned_start'    => '2026-09-01',
				'planned_due'      => '2026-09-10',
			),
			$extra
		);
	}

	// -----------------------------------------------------------------------
	// Nothing to wait for.
	// -----------------------------------------------------------------------

	/**
	 * Work that waits on nothing is clear, and says so with a shape rather than
	 * an absence — a screen should not have to tell "no dependencies" from "the
	 * dependencies were not loaded".
	 */
	public function test_work_that_waits_on_nothing_is_clear(): void {
		$summary = Dependencies::summarise( array() );

		$this->assertTrue( $summary['clear'] );
		$this->assertSame( 0, $summary['waiting'] );
	}

	/**
	 * A dependency that is finished is not something anybody is waiting for.
	 */
	public function test_a_finished_dependency_is_not_waited_on(): void {
		$summary = Dependencies::summarise( array( $this->upstream( 'wit_a', 'released' ) ) );

		$this->assertTrue( $summary['clear'] );
		$this->assertSame( 1, $summary['satisfied'] );
	}

	/**
	 * Nor is one that was cancelled. Waiting forever for work somebody
	 * deliberately stopped is the failure this avoids.
	 */
	public function test_a_dependency_that_ended_unfinished_is_not_waited_on(): void {
		$summary = Dependencies::summarise(
			array( $this->upstream( 'wit_a', 'triage', array( 'terminal_outcome' => 'cancelled' ) ) )
		);

		$this->assertTrue( $summary['clear'] );
	}

	// -----------------------------------------------------------------------
	// The two states #103 asks to be surfaced.
	// -----------------------------------------------------------------------

	/**
	 * A dependency nobody has scheduled is named, because "waiting on something
	 * with no date" is the state that quietly eats a plan.
	 */
	public function test_an_unscheduled_dependency_is_named(): void {
		$summary = Dependencies::summarise(
			array(
				$this->upstream(
					'wit_a',
					'triage',
					array(
						'planned_start' => '',
						'planned_due'   => '',
					)
				),
			)
		);

		$this->assertFalse( $summary['clear'] );
		$this->assertSame( array( 'wit_a' ), $summary['unscheduled'] );
	}

	/**
	 * And so is one that is itself blocked, which is the same problem one step
	 * further away.
	 */
	public function test_a_blocked_dependency_is_named(): void {
		$summary = Dependencies::summarise( array( $this->upstream( 'wit_a', 'blocked' ) ) );

		$this->assertFalse( $summary['clear'] );
		$this->assertSame( array( 'wit_a' ), $summary['blocked'] );
	}

	/**
	 * A dependency that is merely not finished yet is waited on without being a
	 * problem, so the two are counted apart. Everything being flagged is the
	 * same as nothing being flagged.
	 */
	public function test_work_in_hand_is_waited_on_without_being_flagged(): void {
		$summary = Dependencies::summarise( array( $this->upstream( 'wit_a', 'in-development' ) ) );

		$this->assertSame( 1, $summary['waiting'] );
		$this->assertSame( array(), $summary['unscheduled'] );
		$this->assertSame( array(), $summary['blocked'] );
		$this->assertFalse( $summary['clear'] );
	}

	/**
	 * A blocked dependency with no dates is both, and is counted once as waited
	 * on — a total that double-counts is a total nobody can act on.
	 */
	public function test_a_dependency_is_waited_on_once_however_many_ways_it_is_stuck(): void {
		$summary = Dependencies::summarise(
			array(
				$this->upstream(
					'wit_a',
					'blocked',
					array(
						'planned_start' => '',
						'planned_due'   => '',
					)
				),
			)
		);

		$this->assertSame( 1, $summary['waiting'] );
		$this->assertSame( array( 'wit_a' ), $summary['blocked'] );
		$this->assertSame( array( 'wit_a' ), $summary['unscheduled'] );
	}

	// -----------------------------------------------------------------------
	// What cannot be a dependency.
	// -----------------------------------------------------------------------

	/**
	 * Work cannot wait on itself.
	 */
	public function test_work_cannot_depend_on_itself(): void {
		$this->assertNotNull( Dependencies::refuse( 'wit_a', 'wit_a', array() ) );
	}

	/**
	 * Nor can a pair wait on each other, directly or round a longer loop.
	 * Neither would ever start, and nothing downstream would ever be scheduled.
	 *
	 * The existing chain is given as upstream ids by item, which is how the
	 * caller has it.
	 */
	public function test_a_dependency_that_closes_a_loop_is_refused(): void {
		$existing = array(
			'wit_b' => array( 'wit_c' ),
			'wit_c' => array( 'wit_a' ),
		);

		// A already sits upstream of B through C, so B cannot also sit upstream
		// of A.
		$this->assertNotNull( Dependencies::refuse( 'wit_a', 'wit_b', $existing ) );
	}

	/**
	 * A chain that does not close is fine, however long it is.
	 */
	public function test_a_long_chain_that_does_not_close_is_allowed(): void {
		$existing = array(
			'wit_b' => array( 'wit_c' ),
			'wit_c' => array( 'wit_d' ),
		);

		$this->assertNull( Dependencies::refuse( 'wit_a', 'wit_b', $existing ) );
	}

	/**
	 * The refusal says which of the two problems it is, because "that cannot be
	 * a dependency" leaves somebody guessing between a typo and a loop.
	 */
	public function test_the_refusal_says_which_problem_it_is(): void {
		$this->assertSame( Dependencies::SELF, Dependencies::refuse( 'wit_a', 'wit_a', array() ) );
		$this->assertSame(
			Dependencies::LOOP,
			Dependencies::refuse( 'wit_a', 'wit_b', array( 'wit_b' => array( 'wit_a' ) ) )
		);
	}
}
