<?php
/**
 * Where a schedule's next day lands after its repeat is changed.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Recurring\Rule;
use Blueworx\Forge\Recurring\Sources;
use PHPUnit\Framework\TestCase;

final class RecurringEditTest extends TestCase {

	private function fridays(): array {
		return Rule::normalise(
			array(
				'every' => 'week',
				'days'  => array( 5 ),
			)
		);
	}

	public function test_a_new_repeat_is_due_on_its_own_next_day_not_the_old_one(): void {
		// Was monthly on the 26th, last made 26 Sep, with 26 Oct lined up.
		// Changed on Friday 23 Oct to every Friday: due today, not on the 26th.
		self::assertSame( '2026-10-23', Sources::next_after_edit( $this->fridays(), '2026-09-01', '2026-10-23', '2026-09-26' ) );
	}

	public function test_a_day_already_made_is_not_made_again(): void {
		self::assertSame( '2026-10-30', Sources::next_after_edit( $this->fridays(), '2026-09-01', '2026-10-23', '2026-10-23' ) );
	}

	public function test_a_later_start_still_wins(): void {
		self::assertSame( '2026-11-06', Sources::next_after_edit( $this->fridays(), '2026-11-02', '2026-10-23', '' ) );
	}
}
