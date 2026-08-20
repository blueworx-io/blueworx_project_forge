<?php
/**
 * The WORK-1 hierarchy rules.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\Levels;
use Blueworx\Forge\Work\Types;
use PHPUnit\Framework\TestCase;

/**
 * WORK-1 puts four rungs in one entity, so that a level can be skipped and a
 * Bug can hang anywhere or nowhere. What holds it together is the one rule
 * below: a parent is always a higher rung than its child. Without it the
 * hierarchy admits a cycle, and every derived progress figure computed from it
 * (WORK-2) never terminates.
 */
final class WorkHierarchyTest extends TestCase {

	/**
	 * The four rungs, in order.
	 */
	public function test_the_levels_are_the_four_rungs_in_order(): void {
		$this->assertSame(
			array( 'project', 'milestone', 'feature', 'sub-feature' ),
			Levels::ALL
		);
	}

	/**
	 * A higher rung may parent a lower one, however far apart — WORK-1 allows a
	 * level to be skipped, so a Feature directly under a Project is ordinary.
	 */
	public function test_a_higher_level_may_parent_a_lower_one(): void {
		$this->assertTrue( Levels::may_parent( 'project', 'milestone' ) );
		$this->assertTrue( Levels::may_parent( 'project', 'sub-feature' ) );
		$this->assertTrue( Levels::may_parent( 'milestone', 'feature' ) );
	}

	/**
	 * Equal levels cannot parent each other. This is the case that makes a
	 * cycle: two items each naming the other.
	 */
	public function test_a_level_cannot_parent_its_own_level(): void {
		foreach ( Levels::ALL as $level ) {
			$this->assertFalse( Levels::may_parent( $level, $level ), "{$level} should not parent itself" );
		}
	}

	/**
	 * Nor can a lower level parent a higher one.
	 */
	public function test_a_lower_level_cannot_parent_a_higher_one(): void {
		$this->assertFalse( Levels::may_parent( 'sub-feature', 'project' ) );
		$this->assertFalse( Levels::may_parent( 'feature', 'milestone' ) );
	}

	/**
	 * A level nobody has defined is not a level, whichever side it appears on.
	 */
	public function test_an_invented_level_parents_nothing(): void {
		$this->assertFalse( Levels::may_parent( 'epic', 'feature' ) );
		$this->assertFalse( Levels::may_parent( 'project', 'epic' ) );
		$this->assertFalse( Levels::exists( 'epic' ) );
	}

	/**
	 * The four work types. Bug and Feedback are types, not levels, which is what
	 * lets them attach at any rung or stand alone.
	 */
	public function test_the_work_types_are_the_four_kinds(): void {
		$this->assertSame(
			array( 'feature', 'bug', 'feedback', 'task' ),
			Types::ALL
		);

		$this->assertTrue( Types::exists( 'bug' ) );
		$this->assertFalse( Types::exists( 'chore' ) );
	}

	/**
	 * The parent rule says nothing about work type. A Bug under a Project is
	 * allowed, and so is a Bug under nothing at all.
	 */
	public function test_work_type_does_not_constrain_where_something_hangs(): void {
		foreach ( Types::ALL as $type ) {
			$this->assertTrue( Levels::may_parent( 'project', 'feature' ), "{$type} should be able to hang under a project" );
		}
	}

	/**
	 * Every level has a sentence a human can read.
	 */
	public function test_every_level_and_type_has_a_label(): void {
		foreach ( Levels::ALL as $level ) {
			$this->assertNotSame( '', Levels::label( $level ) );
		}

		foreach ( Types::ALL as $type ) {
			$this->assertNotSame( '', Types::label( $type ) );
		}
	}
}
