<?php
/**
 * Both interfaces agree about money, always.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Commerce\Entries;
use Blueworx\Forge\Commerce\Reconcile;
use PHPUnit\Framework\TestCase;

/**
 * #158, COMM-3. The ledger checked against itself.
 *
 * A balance is the sum of its entries and nothing stores a total, so the
 * obvious failures are already impossible. What is left is subtler and all of
 * it happens one source at a time — a reservation released twice, a usage
 * booked without its release, spend quietly handed back.
 *
 * **None of those makes a balance look wrong.** Each makes it wrong by a few
 * hours, on one client, found when somebody queries an invoice months later.
 * That is precisely the situation an append-only record exists to make
 * unreachable, so each one is stated here as the sequence of entries that
 * produces it.
 */
final class ReconcileTest extends TestCase {

	/**
	 * One entry against a work item, signed the way the ledger stores it.
	 *
	 * @param string $type  Event type.
	 * @param float  $hours Magnitude.
	 * @param string $id    Which item.
	 * @return array<string, mixed>
	 */
	private function work( string $type, float $hours, string $id = 'wrk_one' ): array {
		return array(
			'event_type'  => $type,
			'hours'       => Entries::signed( $type, $hours ),
			'source_type' => 'work-item',
			'source_id'   => $id,
		);
	}

	/**
	 * One entry that is not against anything that reserves.
	 *
	 * @param string $type  Event type.
	 * @param float  $hours Magnitude.
	 * @return array<string, mixed>
	 */
	private function granted( string $type, float $hours ): array {
		return array(
			'event_type'  => $type,
			'hours'       => Entries::signed( $type, $hours ),
			'source_type' => 'assignment',
			'source_id'   => 'spk_one',
		);
	}

	/* ------------------------------------------------------ a healthy record */

	public function test_a_whole_life_of_a_piece_of_work_holds_together(): void {
		$entries = array(
			$this->work( Entries::WORK_RESERVATION, 13 ),
			$this->work( Entries::WORK_RELEASE, 13 ),
			$this->work( Entries::WORK_USAGE, 13 ),
		);

		$this->assertSame( array(), Reconcile::faults( $entries ) );
		$this->assertSame( 0.0, Reconcile::position( $entries )['held'] );
		$this->assertSame( 13.0, Reconcile::position( $entries )['used'] );
	}

	public function test_work_still_only_planned_is_holding_hours_and_that_is_fine(): void {
		$entries = array( $this->work( Entries::WORK_RESERVATION, 13 ) );

		$this->assertSame( array(), Reconcile::faults( $entries ) );
		$this->assertSame( 13.0, Reconcile::position( $entries )['held'] );
	}

	public function test_work_that_was_cancelled_holds_nothing_and_spent_nothing(): void {
		$entries = array(
			$this->work( Entries::WORK_RESERVATION, 13 ),
			$this->work( Entries::WORK_RELEASE, 13 ),
		);

		$this->assertSame( array(), Reconcile::faults( $entries ) );
		$this->assertSame( 0.0, Reconcile::position( $entries )['held'] );
		$this->assertSame( 0.0, Reconcile::position( $entries )['used'] );
	}

	/* ------------------------------------------------------ and the three faults */

	public function test_hours_released_twice_are_caught(): void {
		/*
		 * The client has been credited hours they never held. The balance is
		 * higher than the truth, which is the direction nobody complains about
		 * and nobody notices.
		 */
		$entries = array(
			$this->work( Entries::WORK_RESERVATION, 13 ),
			$this->work( Entries::WORK_RELEASE, 13 ),
			$this->work( Entries::WORK_RELEASE, 13 ),
		);

		$this->assertSame( array( Reconcile::OVER_RELEASED ), Reconcile::faults( $entries ) );
	}

	public function test_a_usage_booked_without_its_release_is_caught(): void {
		// The client is being charged twice for one piece of work: once as a
		// reservation still held, once as spend. This is the fault the whole
		// reserve-and-convert design exists to prevent.
		$entries = array(
			$this->work( Entries::WORK_RESERVATION, 13 ),
			$this->work( Entries::WORK_USAGE, 13 ),
		);

		$this->assertSame( array( Reconcile::DOUBLE_CHARGED ), Reconcile::faults( $entries ) );
	}

	public function test_spend_handed_back_is_caught(): void {
		/*
		 * Found by walking the entries rather than by totalling them, because
		 * the totals cannot show it: four spent and four un-spent sums to
		 * nothing and looks exactly like work nobody ever charged for.
		 *
		 * Giving spent hours back is a decision with a reason on it, which is
		 * an adjustment. Doing it under the usage type hides it from the one
		 * place somebody would look.
		 */
		$entries = array(
			$this->work( Entries::WORK_RESERVATION, 13 ),
			$this->work( Entries::WORK_RELEASE, 13 ),
			$this->work( Entries::WORK_USAGE, 13 ),
			array(
				'event_type'  => Entries::WORK_USAGE,
				'hours'       => 13.0,
				'source_type' => 'work-item',
				'source_id'   => 'wrk_one',
			),
		);

		$this->assertContains( Reconcile::SPEND_REVERSED, Reconcile::faults( $entries ) );
	}

	/* ---------------------------------------------------- across a whole site */

	public function test_a_healthy_site_reports_nothing(): void {
		$entries = array(
			$this->granted( Entries::ALLOCATION, 40 ),
			$this->work( Entries::WORK_RESERVATION, 13 ),
			$this->work( Entries::WORK_RELEASE, 13 ),
			$this->work( Entries::WORK_USAGE, 13 ),
			$this->work( Entries::WORK_RESERVATION, 4, 'wrk_two' ),
		);

		$this->assertSame( array(), Reconcile::check( $entries ) );
	}

	public function test_the_faulty_source_is_named_rather_than_the_site(): void {
		// "This site does not add up" is not something anybody can act on. The
		// item is.
		$entries = array(
			$this->granted( Entries::ALLOCATION, 40 ),
			$this->work( Entries::WORK_RESERVATION, 13 ),
			$this->work( Entries::WORK_RELEASE, 13 ),
			$this->work( Entries::WORK_USAGE, 13 ),
			$this->work( Entries::WORK_RESERVATION, 4, 'wrk_two' ),
			$this->work( Entries::WORK_USAGE, 4, 'wrk_two' ),
		);

		$found = Reconcile::check( $entries );

		$this->assertCount( 1, $found );
		$this->assertSame( 'wrk_two', $found[0]['source_id'] );
		$this->assertSame( array( Reconcile::DOUBLE_CHARGED ), $found[0]['faults'] );
	}

	public function test_a_package_and_a_top_up_are_not_unreleased_reservations(): void {
		/*
		 * They are single entries by design — there is no pairing for them to
		 * get wrong. Grouping them with the things that reserve would report
		 * every allocation a client has ever had as a fault, and a checker that
		 * cries wolf is a checker nobody reads.
		 */
		$entries = array(
			$this->granted( Entries::ALLOCATION, 40 ),
			$this->granted( Entries::TOP_UP, 10 ),
			$this->granted( Entries::ADJUSTMENT, -5 ),
		);

		$this->assertSame( array(), Reconcile::check( $entries ) );
	}

	/* -------------------------------------------- and what both ends are claiming */

	public function test_a_shown_balance_that_is_the_sum_of_the_entries_agrees(): void {
		$entries = array(
			$this->granted( Entries::ALLOCATION, 40 ),
			$this->work( Entries::WORK_RESERVATION, 13 ),
		);

		$this->assertTrue( Reconcile::agrees( $entries, 27.0 ) );
	}

	public function test_a_shown_balance_that_is_not_is_refused(): void {
		/*
		 * #158's criterion in one line. Both interfaces claim their figure is
		 * the sum of the entries; this is that claim, checkable — and it is the
		 * test that fails the day somebody introduces a cached total to make a
		 * screen faster.
		 */
		$entries = array(
			$this->granted( Entries::ALLOCATION, 40 ),
			$this->work( Entries::WORK_RESERVATION, 13 ),
		);

		$this->assertFalse( Reconcile::agrees( $entries, 40.0 ) );
		$this->assertFalse( Reconcile::agrees( $entries, 27.5 ) );
	}

	public function test_pennies_of_rounding_do_not_count_as_disagreement(): void {
		// Hours are kept to two places, and a comparison that failed on the
		// float representation of a quarter of an hour would fail constantly
		// and be switched off.
		$entries = array( $this->granted( Entries::ALLOCATION, 0.1 ), $this->granted( Entries::TOP_UP, 0.2 ) );

		$this->assertTrue( Reconcile::agrees( $entries, 0.3 ) );
	}
}
