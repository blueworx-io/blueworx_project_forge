<?php
/**
 * Tests for the order a person sets on their day (#620).
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\DayPlan;
use PHPUnit\Framework\TestCase;

/**
 * The day planner remembers the order a person sets for one day, on their
 * account. It is a view of the tasks and holds no task data: only ids.
 */
final class DayPlanTest extends TestCase {

	/**
	 * Clears the stored meta between tests.
	 */
	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_user_meta'] = array();
	}

	/**
	 * What was saved for a day comes back for that day.
	 */
	public function test_a_saved_order_comes_back(): void {
		DayPlan::save( 7, '2026-10-23', array( 'itm_a', 'itm_b' ), array( 'itm_c' ) );

		$this->assertSame(
			array(
				'order' => array( 'itm_a', 'itm_b' ),
				'down'  => array( 'itm_c' ),
			),
			DayPlan::for_day( 7, '2026-10-23' )
		);
	}

	/**
	 * Another day starts from scratch, and so does another person.
	 */
	public function test_another_day_or_person_starts_empty(): void {
		DayPlan::save( 7, '2026-10-23', array( 'itm_a' ), array() );

		$empty = array(
			'order' => array(),
			'down'  => array(),
		);

		$this->assertSame( $empty, DayPlan::for_day( 7, '2026-10-24' ) );
		$this->assertSame( $empty, DayPlan::for_day( 8, '2026-10-23' ) );
	}

	/**
	 * Only the last three days are kept; the row stays small.
	 */
	public function test_old_days_are_dropped(): void {
		foreach ( array( '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23' ) as $day ) {
			DayPlan::save( 7, $day, array( 'itm_a' ), array() );
		}

		$this->assertSame( array(), DayPlan::for_day( 7, '2026-10-20' )['order'] );
		$this->assertSame( array( 'itm_a' ), DayPlan::for_day( 7, '2026-10-23' )['order'] );
	}

	/**
	 * Anything that is not an id is dropped, repeats are folded, and the list is capped.
	 */
	public function test_ids_are_cleaned(): void {
		DayPlan::save( 7, '2026-10-23', array( 'itm_a', 'itm_a', '<script>', '', 5 ), array( 'x y' ) );

		$plan = DayPlan::for_day( 7, '2026-10-23' );

		$this->assertSame( array( 'itm_a' ), $plan['order'] );
		$this->assertSame( array(), $plan['down'] );

		DayPlan::save( 7, '2026-10-23', array_map( static fn( int $n ): string => 'itm_' . $n, range( 1, 900 ) ), array() );

		$this->assertCount( DayPlan::MOST, DayPlan::for_day( 7, '2026-10-23' )['order'] );
	}

	/**
	 * A day that is not a date, or nobody signed in, saves nothing.
	 */
	public function test_a_bad_day_or_nobody_is_refused(): void {
		$this->assertFalse( DayPlan::save( 7, 'tomorrow', array( 'itm_a' ), array() ) );
		$this->assertFalse( DayPlan::save( 0, '2026-10-23', array( 'itm_a' ), array() ) );
	}
}
