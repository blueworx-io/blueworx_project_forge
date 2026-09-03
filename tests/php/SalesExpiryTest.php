<?php
/**
 * When hours somebody bought run out.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Commerce\Sales;
use PHPUnit\Framework\TestCase;

/**
 * #157, COMM-4. Twelve months from the day they were bought.
 *
 * A small sum with one awkward case, and it is the case that decides whether a
 * client loses a day they paid for. Hours bought on the thirty-first of a month
 * expire at the end of the corresponding month a year later — and where that
 * month has no thirty-first, the day is clamped to its last rather than rolled
 * into the next month. Rolling forward would be arguable; rolling *backwards*,
 * which is what naive month arithmetic does when it normalises, would quietly
 * take a day off the client.
 */
final class SalesExpiryTest extends TestCase {

	/**
	 * An expiry, as a date, for reading in an assertion.
	 *
	 * @param string $from YYYY-MM-DD.
	 * @return string
	 */
	private function expires( string $from ): string {
		return gmdate( 'Y-m-d', Sales::expiry_for( $from ) );
	}

	public function test_hours_last_twelve_months(): void {
		$this->assertSame( '2027-03-02', $this->expires( '2026-03-02' ) );
	}

	public function test_the_expiry_is_the_end_of_its_day_rather_than_the_start(): void {
		/*
		 * Hours that ran out at midnight would be unusable on the last day the
		 * client believes they have them, and that day is exactly when somebody
		 * hurries to use them up.
		 */
		$at = Sales::expiry_for( '2026-03-02' );

		$this->assertSame( '23:59:59', gmdate( 'H:i:s', $at ) );
	}

	public function test_a_day_the_target_month_has_not_got_is_clamped_to_its_last(): void {
		// Six months from the thirty-first of August is February, which has no
		// thirty-first — so the client keeps the whole month rather than losing
		// three days to a date that does not exist.
		$this->assertSame( '2027-02-28', gmdate( 'Y-m-d', Sales::expiry_for( '2026-08-31', 6 ) ) );
	}

	public function test_a_leap_year_gives_the_extra_day(): void {
		$this->assertSame( '2028-02-29', gmdate( 'Y-m-d', Sales::expiry_for( '2027-08-31', 6 ) ) );
	}

	public function test_a_thirty_first_that_survives_the_year_keeps_its_day(): void {
		// Twelve months from the thirty-first of August is the thirty-first of
		// August, and clamping must not fire where there is nothing to clamp.
		$this->assertSame( '2027-08-31', $this->expires( '2026-08-31' ) );
	}

	public function test_the_end_of_a_month_that_survives_is_left_alone(): void {
		$this->assertSame( '2027-01-31', $this->expires( '2026-01-31' ) );
	}

	public function test_a_shorter_term_can_be_asked_for(): void {
		$this->assertSame( '2026-06-02', gmdate( 'Y-m-d', Sales::expiry_for( '2026-03-02', 3 ) ) );
	}

	public function test_a_term_crossing_a_year_boundary_lands_in_the_right_year(): void {
		$this->assertSame( '2027-01-15', gmdate( 'Y-m-d', Sales::expiry_for( '2026-11-15', 2 ) ) );
	}

	public function test_a_date_that_is_not_one_has_no_expiry(): void {
		// Nought rather than a guess. The ledger stores nought for "never
		// expires", and a malformed date must not quietly become permanent
		// hours — so the caller is refused rather than the client given a gift.
		foreach ( array( '', 'soon', '2026-02-30', '31/08/2026' ) as $bad ) {
			$this->assertSame( 0, Sales::expiry_for( $bad ), $bad . ' was read as a date' );
		}
	}

	public function test_a_term_of_no_months_has_no_expiry(): void {
		$this->assertSame( 0, Sales::expiry_for( '2026-03-02', 0 ) );
	}
}
