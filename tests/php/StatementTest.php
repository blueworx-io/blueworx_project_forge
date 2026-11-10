<?php
/**
 * The arithmetic behind the hours statement.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Commerce\Statement;
use PHPUnit\Framework\TestCase;

/**
 * #669. Which entries count on a statement, and what a date range means.
 */
final class StatementTest extends TestCase {

	/**
	 * One ledger entry, as the statement reads it.
	 *
	 * @param string $type  Event type.
	 * @param float  $hours Signed hours.
	 * @param int    $at    When it happened.
	 * @return array<string, mixed>
	 */
	private function entry( string $type, float $hours, int $at ): array {
		return array(
			'event_type'  => $type,
			'hours'       => $hours,
			'occurred_at' => $at,
		);
	}

	public function test_holds_do_not_count(): void {
		$this->assertFalse( Statement::counts( 'work-reservation' ) );
		$this->assertFalse( Statement::counts( 'meeting-release' ) );
		$this->assertTrue( Statement::counts( 'work-usage' ) );
		$this->assertTrue( Statement::counts( 'adjustment' ) );
	}

	public function test_sums_split_hours_from_holds(): void {
		$entries = array(
			$this->entry( 'allocation', 20, 100 ),
			$this->entry( 'work-reservation', -3, 200 ),
			$this->entry( 'work-release', 3, 300 ),
			$this->entry( 'work-usage', -3, 300 ),
			$this->entry( 'work-reservation', -2, 400 ),
		);
		$this->assertSame( 17.0, Statement::sum_through( $entries, 400 ) );
		$this->assertSame( -2.0, Statement::held_at( $entries, 400 ) );
		$this->assertSame( 20.0, Statement::sum_through( $entries, 250 ) );
		$this->assertSame( -3.0, Statement::held_at( $entries, 250 ) );
		$this->assertSame( array( 'work-usage' ), array_column( Statement::in_range( $entries, 300, 400 ), 'event_type' ) );
	}

	public function test_range_is_inclusive_utc_days(): void {
		$range = Statement::range( '2026-03-01', '2026-03-31' );
		$this->assertSame( strtotime( '2026-03-01 00:00:00 UTC' ), $range['from'] );
		$this->assertSame( strtotime( '2026-03-31 23:59:59 UTC' ), $range['to'] );
		$this->assertInstanceOf( WP_Error::class, Statement::range( '2026-03-31', '2026-03-01' ) );
		$this->assertInstanceOf( WP_Error::class, Statement::range( '2026-02-30', '2026-03-01' ) );
		$this->assertIsArray( Statement::range( '2015-01-01', '2026-01-03' ) );
	}
}
