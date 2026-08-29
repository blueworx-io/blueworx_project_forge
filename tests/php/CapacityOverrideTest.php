<?php
/**
 * What over-booking somebody costs.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\CapacityOverride;
use Blueworx\Forge\Work\Events;
use Blueworx\Forge\Work\Override;
use PHPUnit\Framework\TestCase;

/**
 * CAP-E3 (#143). Over-allocation is permitted, with a reason, and recorded —
 * and it is deliberately not the WF-5 override, whose mark means something else
 * and whose report would be useless full of routine capacity calls.
 */
final class CapacityOverrideTest extends TestCase {

	public function test_the_reason_is_written_onto_the_item(): void {
		$mark = CapacityOverride::mark( 'Client has agreed the overtime.' );

		$this->assertSame( 1, $mark['capacity_override_used'] );
		$this->assertSame( 'Client has agreed the overtime.', $mark['capacity_override_reason'] );
	}

	public function test_a_long_reason_is_cut_to_the_column(): void {
		$mark = CapacityOverride::mark( str_repeat( 'a', 400 ) );

		$this->assertSame( CapacityOverride::MAX_REASON, strlen( $mark['capacity_override_reason'] ) );
	}

	public function test_surrounding_space_is_not_a_reason(): void {
		$this->assertSame( 'Agreed.', CapacityOverride::mark( '   Agreed.  ' )['capacity_override_reason'] );
	}

	public function test_it_does_not_touch_the_workflow_override(): void {
		$mark = CapacityOverride::mark( 'Client has agreed the overtime.' );

		$this->assertArrayNotHasKey( 'override_used', $mark );
		$this->assertArrayNotHasKey( 'override_reason', $mark );
	}

	public function test_the_two_overrides_are_separate_marks(): void {
		/*
		 * If these ever collide, the override report stops being able to tell a
		 * workflow correction from a busy week — which is the whole of CAP-E3.
		 */
		$this->assertNotSame(
			array_keys( CapacityOverride::mark( 'a' ) ),
			array_keys( Override::mark( 'a' ) )
		);
	}

	public function test_over_allocation_is_its_own_kind_of_history_entry(): void {
		// "How often are we over-committing people" has to be a query.
		$this->assertContains( Events::OVER_ALLOCATED, Events::ACTIONS );
		$this->assertNotSame( Events::OVERRIDDEN, Events::OVER_ALLOCATED );
	}
}
