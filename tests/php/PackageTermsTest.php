<?php
/**
 * What a package offers, and what counts as changing it.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Commerce\Terms;
use PHPUnit\Framework\TestCase;

/**
 * #145, COMM-1. Editing a package must never rewrite what anybody was sold.
 *
 * The half of that rule which can be argued with here is the second question:
 * *has anything actually changed?* Get it wrong in one direction and the
 * catalogue mints a version every time somebody opens a form and saves it,
 * until the history is six identical rows and "which one did they buy" has no
 * useful answer. Get it wrong in the other and a real price change quietly
 * overwrites the terms a client is on — which is the failure the whole feature
 * exists to prevent. So most of this file is {@see Terms::differ()}.
 */
final class PackageTermsTest extends TestCase {

	/**
	 * A package's terms.
	 *
	 * @param array<string, mixed> $overrides Anything else.
	 * @return array<string, mixed>
	 */
	private function terms( array $overrides = array() ): array {
		return Terms::sanitise(
			array_merge(
				array(
					'name'            => 'Standard',
					'hours'           => 10,
					'price'           => 1200,
					'currency'        => 'GBP',
					'validity_months' => 12,
					'terms'           => 'Ten hours a year.',
				),
				$overrides
			)
		);
	}

	/* ------------------------------------------------- what counts as a change */

	public function test_saving_the_same_thing_twice_is_not_a_change(): void {
		// The one that stops the history filling with identical versions.
		$this->assertFalse( Terms::differ( $this->terms(), $this->terms() ) );
	}

	public function test_every_field_of_the_offer_is_a_change(): void {
		/*
		 * Checked across the whole list rather than one by one, so a field
		 * added later cannot arrive silently outside the comparison — which
		 * would let it be edited in place, on a version somebody was sold.
		 */
		$different = array(
			'name'            => 'Essentials',
			'hours'           => 20,
			'price'           => 2400,
			'currency'        => 'EUR',
			'validity_months' => 24,
			'terms'           => 'Twenty hours a year.',
		);

		foreach ( Terms::FIELDS as $field ) {
			$this->assertTrue(
				Terms::differ( $this->terms(), $this->terms( array( $field => $different[ $field ] ) ) ),
				$field
			);
		}
	}

	public function test_a_number_that_came_back_from_the_database_as_a_string_is_not_a_change(): void {
		/*
		 * Hours come out of a decimal column as '10.00' and go in as 10. A
		 * string comparison would call those different and mint a version every
		 * time a row made the round trip — the catalogue would grow a version
		 * per page load.
		 */
		$stored = array_merge( $this->terms(), array( 'hours' => '10.00', 'price' => '1200' ) );

		$this->assertFalse( Terms::differ( $stored, $this->terms() ) );
	}

	public function test_a_quarter_of_an_hour_is_a_change(): void {
		// The other direction. A comparison loose enough to ignore round-trip
		// noise must still be tight enough to notice a real edit.
		$this->assertTrue( Terms::differ( $this->terms(), $this->terms( array( 'hours' => 10.25 ) ) ) );
	}

	public function test_extra_fields_on_a_stored_row_are_not_a_change(): void {
		// A stored version carries an id and a created_at that the form never
		// sends. Comparing whole arrays would read every save as a change.
		$stored = array_merge(
			$this->terms(),
			array(
				'id'         => 'pkv_one',
				'package_id' => 'pkg_one',
				'version'    => 3,
				'created_at' => 1788000000,
			)
		);

		$this->assertFalse( Terms::differ( $stored, $this->terms() ) );
	}

	/* -------------------------------------------------------- cleaning up */

	public function test_hours_land_on_two_decimals(): void {
		// The column holds two. A figure that reads 7.005 on one screen and
		// 7.01 on the next is an afternoon somebody does not get back.
		$this->assertSame( 7.01, $this->terms( array( 'hours' => 7.0051 ) )['hours'] );
	}

	public function test_a_price_is_whole_currency_units(): void {
		// COMM-2 rounds price to the nearest whole unit, so there is no
		// fractional price to keep — and an integer cannot drift the way a
		// year of added-up floats quietly does.
		$this->assertSame( 1200, $this->terms( array( 'price' => 1199.6 ) )['price'] );
	}

	public function test_a_currency_is_three_letters_or_it_is_pounds(): void {
		// This sits next to a number on a client's screen. "£" or "pounds" in
		// that slot is how a price becomes ambiguous.
		$this->assertSame( 'EUR', $this->terms( array( 'currency' => 'eur' ) )['currency'] );
		$this->assertSame( 'GBP', $this->terms( array( 'currency' => '£' ) )['currency'] );
		$this->assertSame( 'GBP', $this->terms( array( 'currency' => 'pounds' ) )['currency'] );
	}

	public function test_a_missing_validity_is_a_year(): void {
		$this->assertSame( Terms::DEFAULT_VALIDITY_MONTHS, $this->terms( array( 'validity_months' => 0 ) )['validity_months'] );
	}

	public function test_a_fat_fingered_validity_is_bounded(): void {
		// Not a real offer — a bound, so 1200 cannot commit the studio to a
		// century of support at this year's price.
		$this->assertSame( Terms::MAX_VALIDITY_MONTHS, $this->terms( array( 'validity_months' => 1200 ) )['validity_months'] );
	}

	public function test_nothing_negative_survives(): void {
		$cleaned = $this->terms(
			array(
				'hours' => -5,
				'price' => -100,
			)
		);

		$this->assertSame( 0.0, $cleaned['hours'] );
		$this->assertSame( 0, $cleaned['price'] );
	}

	/* ------------------------------------------------------ what is refused */

	public function test_a_package_needs_a_name(): void {
		$this->assertNotSame( '', Terms::refuse( $this->terms( array( 'name' => '   ' ) ) ) );
	}

	public function test_a_package_with_no_hours_is_refused(): void {
		/*
		 * Deliberately not allowed as a "support, no hours" product. COMM-5
		 * already covers the only case that needs one — bug work on a site we
		 * delivered is free — and a nought-hour package would let that
		 * exemption be granted by accident, to everything, by whoever set the
		 * catalogue up.
		 */
		$this->assertNotSame( '', Terms::refuse( $this->terms( array( 'hours' => 0 ) ) ) );
	}

	public function test_an_ordinary_package_is_accepted(): void {
		$this->assertSame( '', Terms::refuse( $this->terms() ) );
	}

	public function test_a_refusal_says_what_to_do_about_it(): void {
		// It goes straight in front of whoever typed it, and every one of these
		// is a typo rather than an attack.
		$this->assertStringContainsString( 'name', Terms::refuse( $this->terms( array( 'name' => '' ) ) ) );
		$this->assertStringContainsString( 'hours', Terms::refuse( $this->terms( array( 'hours' => 0 ) ) ) );
	}

	/* ----------------------------------------------------------- statuses */

	public function test_there_are_two_statuses_and_no_others(): void {
		$this->assertTrue( Terms::is_status( Terms::ACTIVE ) );
		$this->assertTrue( Terms::is_status( Terms::RETIRED ) );
		$this->assertFalse( Terms::is_status( 'deleted' ) );
		$this->assertCount( 2, Terms::STATUSES );
	}
}
