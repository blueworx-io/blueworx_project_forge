<?php
/**
 * The days ahead of a schedule, as capacity counts them (2026-09-19).
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Recurring\Load;
use PHPUnit\Framework\TestCase;

final class RecurringLoadTest extends TestCase {

	/**
	 * A weekday schedule for two people, an hour each.
	 *
	 * @param array<string, mixed> $over Anything to change.
	 * @return array<string, mixed>
	 */
	private function source( array $over = array() ): array {
		return array_merge(
			array(
				'id'             => 'rcr_one',
				'title'          => 'Clear the inbox',
				'client_id'      => 'cli_one',
				'client_site_id' => 'cst_one',
				'assignees'      => array( 'usr_a', 'usr_b' ),
				'hours_each'     => 1.0,
				'rule'           => array(
					'every' => 'week',
					'days'  => array( 1, 2, 3, 4, 5 ),
				),
				'starts_on'      => '2026-09-01',
				'ends_on'        => '',
			),
			$over
		);
	}

	public function test_each_person_carries_the_hours_on_each_due_day(): void {
		// Monday 21 to Wednesday 23 September 2026: three weekdays, two people.
		$out = Load::allocations( $this->source(), '2026-09-21', '2026-09-23' );

		self::assertCount( 6, $out );
		self::assertSame( array( 'usr_a', 'usr_b' ), array_values( array_unique( array_column( $out, 'user_id' ) ) ) );
		self::assertSame( array( '2026-09-21', '2026-09-22', '2026-09-23' ), array_values( array_unique( array_column( $out, 'from' ) ) ) );
		self::assertSame( 1.0, $out[0]['hours'] );
		self::assertSame( 'assignee', $out[0]['role'] );
		self::assertSame( $out[0]['from'], $out[0]['to'], 'a chore is one day, not a span' );
	}

	public function test_a_weekend_costs_nothing_and_neither_does_an_ended_schedule(): void {
		self::assertSame( array(), Load::allocations( $this->source(), '2026-09-26', '2026-09-27' ) );
		self::assertSame( array(), Load::allocations( $this->source( array( 'ends_on' => '2026-09-18' ) ), '2026-09-21', '2026-09-25' ) );
		self::assertSame( array(), Load::allocations( $this->source( array( 'hours_each' => 0 ) ), '2026-09-21', '2026-09-25' ) );
	}

	public function test_nothing_before_it_starts(): void {
		$out = Load::allocations( $this->source( array( 'starts_on' => '2026-09-23' ) ), '2026-09-21', '2026-09-25' );

		self::assertSame( array( '2026-09-23', '2026-09-24', '2026-09-25' ), array_values( array_unique( array_column( $out, 'from' ) ) ) );
	}
}
