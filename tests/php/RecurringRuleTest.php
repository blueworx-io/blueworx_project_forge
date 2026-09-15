<?php
/**
 * When a recurring task is next due.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Recurring\Rule;
use PHPUnit\Framework\TestCase;

/**
 * The date arithmetic behind recurring tasks, argued with here because a
 * schedule that is a day out is a task somebody never sees.
 */
final class RecurringRuleTest extends TestCase {

	public function test_daily_is_due_every_day(): void {
		$rule = Rule::normalise( array( 'every' => 'day' ) );

		self::assertSame( array( 'every' => 'day' ), $rule );
		self::assertSame( '2026-09-15', Rule::next_on_or_after( $rule, '2026-09-15' ) );
		self::assertSame( array( '2026-09-15', '2026-09-16', '2026-09-17' ), Rule::due_between( $rule, '2026-09-15', '2026-09-17' ) );
	}

	public function test_weekly_lands_on_the_chosen_weekdays(): void {
		// Monday and Thursday. 2026-09-16 is a Wednesday.
		$rule = Rule::normalise(
			array(
				'every' => 'week',
				'days'  => array( 4, 1, 4 ),
			)
		);

		self::assertSame(
			array(
				'every' => 'week',
				'days'  => array( 1, 4 ),
			),
			$rule
		);
		self::assertSame( '2026-09-17', Rule::next_on_or_after( $rule, '2026-09-16' ) );
		self::assertSame( '2026-09-21', Rule::next_on_or_after( $rule, '2026-09-18' ) );
		self::assertSame( array( '2026-09-17', '2026-09-21', '2026-09-24' ), Rule::due_between( $rule, '2026-09-16', '2026-09-25' ) );
	}

	public function test_monthly_clamps_to_the_last_day(): void {
		$rule = Rule::normalise(
			array(
				'every' => 'month',
				'day'   => 31,
			)
		);

		self::assertSame( '2026-02-28', Rule::next_on_or_after( $rule, '2026-02-01' ) );
		self::assertSame( '2028-02-29', Rule::next_on_or_after( $rule, '2028-02-01' ) );
		self::assertSame( '2026-03-31', Rule::next_on_or_after( $rule, '2026-03-01' ) );
		self::assertSame( array( '2026-01-31', '2026-02-28', '2026-03-31' ), Rule::due_between( $rule, '2026-01-31', '2026-04-01' ) );
	}

	public function test_monthly_on_the_same_day_is_due_today(): void {
		$rule = Rule::normalise(
			array(
				'every' => 'month',
				'day'   => 15,
			)
		);

		self::assertSame( '2026-09-15', Rule::next_on_or_after( $rule, '2026-09-15' ) );
		self::assertSame( '2026-10-15', Rule::next_on_or_after( $rule, '2026-09-16' ) );
	}

	public function test_an_end_date_stops_the_run(): void {
		$rule = Rule::normalise( array( 'every' => 'day' ) );

		self::assertSame( array( '2026-09-15', '2026-09-16' ), Rule::due_between( $rule, '2026-09-15', '2026-09-20', '2026-09-16' ) );
	}

	public function test_nonsense_is_refused(): void {
		self::assertNull( Rule::normalise( array( 'every' => 'year' ) ) );
		self::assertNull( Rule::normalise( array( 'every' => 'week' ) ) );
		self::assertNull(
			Rule::normalise(
				array(
					'every' => 'week',
					'days'  => array( 8 ),
				)
			)
		);
		self::assertNull(
			Rule::normalise(
				array(
					'every' => 'month',
					'day'   => 0,
				)
			)
		);
		self::assertNull(
			Rule::normalise(
				array(
					'every' => 'month',
					'day'   => 32,
				)
			)
		);
		self::assertNull( Rule::normalise( array() ) );
	}

	public function test_a_rule_reads_in_words(): void {
		self::assertSame( 'Every day', Rule::describe( array( 'every' => 'day' ) ) );
		self::assertSame(
			'Every Monday and Thursday',
			Rule::describe(
				array(
					'every' => 'week',
					'days'  => array( 1, 4 ),
				)
			)
		);
		self::assertSame(
			'Every Monday, Wednesday and Friday',
			Rule::describe(
				array(
					'every' => 'week',
					'days'  => array( 1, 3, 5 ),
				)
			)
		);
		self::assertSame(
			'Monthly on the 15th',
			Rule::describe(
				array(
					'every' => 'month',
					'day'   => 15,
				)
			)
		);
		self::assertSame(
			'Monthly on the last day',
			Rule::describe(
				array(
					'every' => 'month',
					'day'   => 31,
				)
			)
		);
	}
}
