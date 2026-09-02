<?php
/**
 * Part-year hours, on exact dates, across a leap year.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Commerce\Entries;
use Blueworx\Forge\Commerce\ProRata;
use PHPUnit\Framework\TestCase;

/**
 * #147, COMM-2. "The preview matches what the ledger receives, to the hour,
 * across a leap-year boundary."
 *
 * Every number here ends up on an invoice, so the tests are the awkward cases
 * rather than the tidy one:
 *
 * - the leap day, which PHP's own month arithmetic gets wrong by two days,
 * - the ends of a term, where an off-by-one costs a day's hours on every
 *   part-year assignment ever made,
 * - the rounding boundary, which is the figure somebody queries.
 *
 * A whole-month approximation would pass none of these, which is why COMM-2
 * refused one.
 */
final class ProRataTest extends TestCase {

	/**
	 * A ten-hour package.
	 *
	 * @param array<string, mixed> $overrides Anything else.
	 * @return array<string, mixed>
	 */
	private function package( array $overrides = array() ): array {
		return array_merge(
			array(
				'hours'           => 10.0,
				'price'           => 1200,
				'currency'        => 'GBP',
				'validity_months' => 12,
			),
			$overrides
		);
	}

	/* ------------------------------------------------------- counting days */

	public function test_a_year_is_three_hundred_and_sixty_five_days(): void {
		// Both ends counted, because a term is the days a client has cover on.
		$this->assertSame( 365, ProRata::days( '2026-01-01', '2026-12-31' ) );
	}

	public function test_a_leap_year_is_three_hundred_and_sixty_six(): void {
		$this->assertSame( 366, ProRata::days( '2028-01-01', '2028-12-31' ) );
	}

	public function test_one_day_is_one_day(): void {
		// The smallest case, and the one an exclusive count gets wrong by
		// calling it nothing at all.
		$this->assertSame( 1, ProRata::days( '2026-06-01', '2026-06-01' ) );
	}

	public function test_february_has_whatever_length_it_actually_had(): void {
		$this->assertSame( 28, ProRata::days( '2026-02-01', '2026-02-28' ) );
		$this->assertSame( 29, ProRata::days( '2028-02-01', '2028-02-29' ) );
	}

	public function test_a_range_that_runs_backwards_is_no_days(): void {
		$this->assertSame( 0, ProRata::days( '2026-12-31', '2026-01-01' ) );
	}

	public function test_something_that_is_not_a_date_is_no_days(): void {
		$this->assertSame( 0, ProRata::days( 'soon', '2026-01-01' ) );
	}

	/* --------------------------------------------------- where a term ends */

	public function test_a_year_from_the_first_of_january_ends_on_new_years_eve(): void {
		// The day before the anniversary, so a term does not overlap its own
		// renewal by a day.
		$this->assertSame( '2026-12-31', ProRata::term_end( '2026-01-01', 12 ) );
	}

	public function test_a_year_from_the_first_of_march_ends_at_the_end_of_february(): void {
		$this->assertSame( '2027-02-28', ProRata::term_end( '2026-03-01', 12 ) );
	}

	public function test_a_year_from_the_leap_day_ends_the_day_before_it_would_have_been(): void {
		/*
		 * The trap. PHP's own month arithmetic turns the twenty-ninth of
		 * February plus twelve months into the first of March, and a term that
		 * quietly gains a day gains a day of hours with it — on the one date in
		 * four years where nobody thinks to check.
		 */
		$this->assertSame( '2029-02-27', ProRata::term_end( '2028-02-29', 12 ) );
	}

	public function test_a_month_from_the_thirty_first_lands_inside_the_next_month(): void {
		// The same clamp, in the case that comes up every month rather than
		// every four years: the thirty-first of January plus one month is
		// February, not the second of March.
		$this->assertSame( '2026-02-27', ProRata::term_end( '2026-01-31', 1 ) );
	}

	public function test_a_term_of_no_months_is_not_a_term(): void {
		$this->assertSame( '', ProRata::term_end( '2026-01-01', 0 ) );
	}

	/* ------------------------------------------------------------ the sums */

	public function test_half_a_year_is_half_the_hours(): void {
		$preview = ProRata::preview( $this->package(), '2026-01-01', '2026-07-01' );

		$this->assertSame( 182, $preview['days'] );
		$this->assertSame( 365, $preview['term_days'] );
		$this->assertSame( 5.0, $preview['hours'] );
	}

	public function test_a_whole_term_is_the_whole_package(): void {
		// The boundary that must not lose an hour to rounding: somebody
		// assigning a full year through the pro-rata path gets the full year.
		$preview = ProRata::preview( $this->package(), '2026-01-01', '2026-12-31' );

		$this->assertSame( 1.0, $preview['ratio'] );
		$this->assertSame( 10.0, $preview['hours'] );
		$this->assertSame( 1200, $preview['price'] );
	}

	public function test_hours_land_on_a_half_hour(): void {
		// COMM-2 rounds to the nearest half hour. A figure of 3.42 hours on an
		// invoice is a figure somebody asks about.
		$preview = ProRata::preview( $this->package(), '2026-01-01', '2026-05-01' );

		$this->assertSame( 0.0, fmod( $preview['hours'] * 2, 1.0 ) );
	}

	public function test_price_is_a_whole_currency_unit(): void {
		$preview = ProRata::preview( $this->package(), '2026-01-01', '2026-05-01' );

		$this->assertSame( $preview['price'], (int) $preview['price'] );
	}

	public function test_a_dates_muddle_cannot_bill_for_more_than_a_package(): void {
		// A part cannot exceed the whole. A caller who has the dates the wrong
		// way round should get a full package, not a bill for two.
		$preview = ProRata::preview( $this->package(), '2026-01-01', '2029-12-31' );

		$this->assertSame( 1.0, $preview['ratio'] );
		$this->assertSame( 10.0, $preview['hours'] );
	}

	public function test_the_price_is_prorated_by_the_same_ratio_as_the_hours(): void {
		/*
		 * COMM-2 says the same ratio does both. Two ratios is how a client ends
		 * up paying eight months for seven months of hours, and it is the sort
		 * of thing that survives for years because each half looks right on its
		 * own.
		 */
		$preview = ProRata::preview( $this->package(), '2026-04-01', '2026-12-31' );

		$this->assertSame( ProRata::hours( 10.0, $preview['ratio'] ), $preview['hours'] );
		$this->assertSame( ProRata::price( 1200, $preview['ratio'] ), $preview['price'] );
	}

	/* -------------------------------------------- across a leap-year boundary */

	public function test_a_part_term_over_a_leap_day_counts_the_leap_day(): void {
		// Two months either side of the twenty-ninth. A whole-month
		// approximation cannot see the difference; the client can.
		$over = ProRata::preview( $this->package(), '2028-02-01', '2028-03-31' );
		$past = ProRata::preview( $this->package(), '2026-02-01', '2026-03-31' );

		$this->assertSame( 60, $over['days'] );
		$this->assertSame( 59, $past['days'] );
	}

	public function test_a_term_starting_in_a_leap_year_is_measured_against_its_own_length(): void {
		/*
		 * The denominator is the term this client would otherwise have had, not
		 * a flat 365. A term from March 2028 runs to February 2029 and is 365
		 * days; one from January 2028 covers the leap day and is 366. Using one
		 * number for both over-pays half the clients and under-pays the rest.
		 */
		$this->assertSame( 366, ProRata::preview( $this->package(), '2028-01-01', '2028-06-30' )['term_days'] );
		$this->assertSame( 365, ProRata::preview( $this->package(), '2028-03-01', '2028-06-30' )['term_days'] );
	}

	/* ------------------------------- the preview is what the ledger receives */

	public function test_the_ledger_takes_the_previewed_figure_unchanged(): void {
		/*
		 * The acceptance criterion, and the reason the preview carries the
		 * number rather than the dates. An allocation written from this preview
		 * must be for exactly these hours — a second rounding on the way into
		 * the ledger is how a preview and an invoice come to differ by half an
		 * hour, which is precisely the amount nobody can explain.
		 */
		foreach ( array( '2028-01-15', '2028-02-29', '2028-06-01', '2026-11-30' ) as $from ) {
			$preview = ProRata::preview( $this->package(), $from, '2029-01-31' );
			$booked  = Entries::signed( Entries::ALLOCATION, $preview['hours'] );

			$this->assertSame( $preview['hours'], $booked, $from );
		}
	}

	public function test_a_preview_carries_the_full_figures_it_was_worked_out_from(): void {
		// So the person agreeing to it can see the sum rather than the answer.
		// A number with no working shown is a number people either accept
		// without checking or refuse without knowing why.
		$preview = ProRata::preview( $this->package(), '2026-07-01', '2026-12-31' );

		$this->assertSame( 10.0, $preview['full_hours'] );
		$this->assertSame( 1200, $preview['full_price'] );
		$this->assertSame( 'GBP', $preview['currency'] );
		$this->assertSame( '2026-07-01', $preview['from'] );
		$this->assertSame( '2026-12-31', $preview['to'] );
	}

	/* ------------------------------------------------------ upgrade credits */

	public function test_the_unused_part_of_an_outgoing_package_is_what_is_left_of_it(): void {
		// A client three months into a year, upgrading. The credit is the rest
		// of the term, worked out the same way the charge was.
		$credit = ProRata::unused( $this->package(), '2026-01-01', '2026-04-01' );

		$this->assertSame( '2026-12-31', $credit['to'] );
		$this->assertSame( 275, $credit['days'] );
		$this->assertSame( 904, $credit['price'] );
	}

	public function test_an_upgrade_on_the_last_day_credits_one_day(): void {
		$credit = ProRata::unused( $this->package(), '2026-01-01', '2026-12-31' );

		$this->assertSame( 1, $credit['days'] );
	}

	public function test_a_credit_and_a_charge_for_the_same_days_agree(): void {
		/*
		 * They have to, or an upgrade quietly gains or loses the client hours.
		 * The same method does both, which is the only way to be sure — two
		 * that agree today are two that can stop agreeing.
		 */
		$credit = ProRata::unused( $this->package(), '2026-01-01', '2026-04-01' );
		$charge = ProRata::preview( $this->package(), '2026-04-01', '2026-12-31' );

		$this->assertSame( $charge['days'], $credit['days'] );
		$this->assertSame( $charge['hours'], $credit['hours'] );
		$this->assertSame( $charge['price'], $credit['price'] );
	}

	public function test_there_is_no_way_to_work_out_a_downgrade(): void {
		/*
		 * COMM-2 does not permit one mid-term, only at renewal. A calculation
		 * nobody may use is a calculation somebody eventually uses, so there
		 * isn't one — checked by reading the class, because the guarantee is an
		 * absence and no ordinary test can make it.
		 */
		foreach ( get_class_methods( ProRata::class ) as $method ) {
			$this->assertStringNotContainsString( 'downgrade', $method );
		}
	}
}
