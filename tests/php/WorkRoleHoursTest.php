<?php
/**
 * Where the reviewer's and deliverer's hours come from.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\RoleHours;
use PHPUnit\Framework\TestCase;

/**
 * CAP-2's seeded defaults (#137).
 *
 * The rule is only useful if it is quiet in the common case and absent in the
 * unusual one, so the cases that matter are the ones where it should do
 * nothing: a figure somebody typed, and a figure somebody deliberately set to
 * zero.
 */
final class WorkRoleHoursTest extends TestCase {

	public function test_a_primary_estimate_seeds_the_other_two(): void {
		$seeded = RoleHours::seed( array( 'hours_primary' => 10.0 ) );

		$this->assertSame( 2.0, $seeded['hours_review'] );
		$this->assertSame( 1.0, $seeded['hours_delivery'] );
	}

	public function test_it_does_not_overwrite_what_somebody_typed(): void {
		$seeded = RoleHours::seed(
			array(
				'hours_primary' => 10.0,
				'hours_review'  => 4.0,
			)
		);

		$this->assertSame( 4.0, $seeded['hours_review'], 'a figure that was set stays set' );
		$this->assertSame( 1.0, $seeded['hours_delivery'] );
	}

	public function test_it_does_not_overwrite_a_deliberate_zero(): void {
		$seeded = RoleHours::seed(
			array(
				'hours_primary' => 10.0,
				'hours_review'  => 0.0,
			)
		);

		$this->assertSame( 0.0, $seeded['hours_review'], 'saying zero is saying something' );
	}

	public function test_it_leaves_an_item_that_already_has_hours_alone(): void {
		$seeded = RoleHours::seed(
			array( 'hours_primary' => 12.0 ),
			array(
				'hours_review'   => 3.0,
				'hours_delivery' => 1.5,
			)
		);

		$this->assertArrayNotHasKey( 'hours_review', $seeded, 're-estimating the work does not undo an edit' );
		$this->assertArrayNotHasKey( 'hours_delivery', $seeded );
	}

	public function test_it_does_nothing_without_a_primary_estimate(): void {
		$this->assertSame( array( 'title' => 'A thing' ), RoleHours::seed( array( 'title' => 'A thing' ) ) );
	}

	public function test_it_does_nothing_for_a_primary_estimate_of_zero(): void {
		$seeded = RoleHours::seed( array( 'hours_primary' => 0.0 ) );

		$this->assertArrayNotHasKey( 'hours_review', $seeded );
	}
}
