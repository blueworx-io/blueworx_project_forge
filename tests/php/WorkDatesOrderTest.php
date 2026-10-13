<?php
/**
 * A task's dates, one per person, kept in order.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\Validate;
use PHPUnit\Framework\TestCase;

/**
 * #513. Design by, then deliver by, then review by, then release by. The start
 * is Forge's own, set from the earliest of them, so it is not held to an order.
 */
final class WorkDatesOrderTest extends TestCase {

	public function test_dates_in_order_are_accepted(): void {
		$this->assertSame(
			array(),
			Validate::dates_in_order(
				array(
					'design_target'  => '2026-10-14',
					'planned_due'    => '2026-10-15',
					'review_target'  => '2026-10-16',
					'release_target' => '2026-10-17',
				)
			)
		);
	}

	public function test_deliver_by_cannot_be_before_design_by(): void {
		$errors = Validate::dates_in_order(
			array(
				'design_target' => '2026-10-15',
				'planned_due'   => '2026-10-14',
			)
		);

		$this->assertSame( array( 'planned_due' => 'Deliver by cannot be before design by.' ), $errors );
	}

	public function test_the_start_is_not_held_to_the_order(): void {
		$this->assertSame(
			array(),
			Validate::dates_in_order(
				array(
					'planned_start' => '2026-10-20',
					'planned_due'   => '2026-10-14',
				)
			)
		);
	}
}
