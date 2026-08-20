<?php
/**
 * Bug Tracking exists only for bugs.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\Stages;
use Blueworx\Forge\Work\Transitions;
use Blueworx\Forge\Work\Types;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #110's two halves: a non-bug cannot enter Bug Tracking by any route, and a
 * bug has no route round it.
 */
final class WorkConditionalStageTest extends TestCase {

	/**
	 * The three work types that are not bugs.
	 *
	 * @return array<string, array{string}>
	 */
	public static function non_bugs(): array {
		$cases = array();

		foreach ( Types::ALL as $type ) {
			if ( Types::BUG !== $type ) {
				$cases[ $type ] = array( $type );
			}
		}

		return $cases;
	}

	/**
	 * Nothing but a bug may hold the stage.
	 *
	 * @param string $type A work type that is not a bug.
	 */
	#[DataProvider( 'non_bugs' )]
	public function test_a_non_bug_may_not_hold_bug_tracking( string $type ): void {
		$this->assertFalse( Stages::may_hold( Stages::BUG_TRACKING, $type ) );
		$this->assertTrue( Stages::may_hold( Stages::BUG_TRACKING, Types::BUG ) );
	}

	/**
	 * And every other stage is open to every type — the conditional rule is one
	 * stage, not a general mechanism somebody can extend by accident.
	 *
	 * @param string $type A work type that is not a bug.
	 */
	#[DataProvider( 'non_bugs' )]
	public function test_every_other_stage_holds_every_type( string $type ): void {
		foreach ( Stages::ALL as $stage ) {
			if ( Stages::BUG_TRACKING === $stage ) {
				continue;
			}

			$this->assertTrue( Stages::may_hold( $stage, $type ), "{$stage} should hold {$type}" );
		}
	}

	/**
	 * A bug's path runs through Bug Tracking; nothing else's does.
	 *
	 * @param string $type A work type that is not a bug.
	 */
	#[DataProvider( 'non_bugs' )]
	public function test_the_stage_is_absent_from_a_non_bug_path( string $type ): void {
		$this->assertNotContains( Stages::BUG_TRACKING, Stages::path_for( $type ) );
		$this->assertContains( Stages::BUG_TRACKING, Stages::path_for( Types::BUG ) );
	}

	/**
	 * Blocked is in nobody's path. It is not a place on the way to anywhere.
	 */
	public function test_blocked_is_in_no_path(): void {
		foreach ( Types::ALL as $type ) {
			$this->assertNotContains( Stages::BLOCKED, Stages::path_for( $type ) );
		}
	}

	/**
	 * The other half: a bug leaves Triage through Bug Tracking, and has no
	 * other way out.
	 */
	public function test_a_bug_must_go_through_bug_tracking(): void {
		$this->assertSame( array( Stages::BUG_TRACKING ), Transitions::next_from( 'triage', Types::BUG ) );
		$this->assertSame( array( 'documentation-period' ), Transitions::next_from( 'triage', Types::FEATURE ) );
		$this->assertTrue( Stages::required_for( Stages::BUG_TRACKING, Types::BUG ) );
		$this->assertFalse( Stages::required_for( Stages::BUG_TRACKING, Types::FEATURE ) );
	}

	/**
	 * "Active" is what the specification means everywhere it says "any active
	 * stage": still being worked on. Released is finished, and Blocked is
	 * paused with its own way out.
	 */
	public function test_active_stages_are_the_ones_still_being_worked_on(): void {
		$this->assertTrue( Stages::is_active( 'in-development' ) );
		$this->assertTrue( Stages::is_active( 'completed' ) );
		$this->assertFalse( Stages::is_active( 'released' ) );
		$this->assertFalse( Stages::is_active( Stages::BLOCKED ) );
		$this->assertFalse( Stages::is_active( 'nonsense' ) );
	}
}
