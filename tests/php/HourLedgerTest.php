<?php
/**
 * What can happen to a site's hours, and what each of it means.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Commerce\Entries;
use Blueworx\Forge\Commerce\Ledger;
use PHPUnit\Framework\TestCase;

/**
 * #148, COMM-3 and COMM-4. One append-only ledger every hour passes through.
 *
 * This is the record the studio and the client are both reading when they
 * disagree about a bill, so the tests are about the three ways that record can
 * quietly stop being trustworthy:
 *
 * - a balance that is not the sum of its entries,
 * - an hour charged twice because a reservation and its usage were both taken,
 * - a decision recorded with nothing said for it.
 *
 * Everything that touches the database — a balance summed in SQL, a backdated
 * entry — is proved in the browser suite against a real one. What is here is
 * every rule that can be settled without one, which is most of them.
 */
final class HourLedgerTest extends TestCase {

	private const ACTOR = 7;

	/**
	 * One entry, as a caller would offer it.
	 *
	 * @param string               $type      Event type.
	 * @param float                $hours     How many.
	 * @param array<string, mixed> $overrides Anything else.
	 * @return array<string, mixed>
	 */
	private function entry( string $type, float $hours, array $overrides = array() ): array {
		return array_merge(
			array(
				'event_type' => $type,
				'hours'      => $hours,
				'source_id'  => 'wrk_one',
				'actor'      => self::ACTOR,
				'reason'     => '',
			),
			$overrides
		);
	}

	/* -------------------------------------------------- the sign is the type's */

	public function test_an_allocation_adds_however_it_is_offered(): void {
		// The sign belongs to the event type, not to whoever typed the number.
		// A mistyped minus must not turn a package's hours into a debit.
		$this->assertSame( 10.0, Entries::signed( Entries::ALLOCATION, 10 ) );
		$this->assertSame( 10.0, Entries::signed( Entries::ALLOCATION, -10 ) );
	}

	public function test_a_reservation_consumes_however_it_is_offered(): void {
		$this->assertSame( -5.0, Entries::signed( Entries::WORK_RESERVATION, 5 ) );
		$this->assertSame( -5.0, Entries::signed( Entries::WORK_RESERVATION, -5 ) );
	}

	public function test_an_adjustment_is_the_one_that_goes_both_ways(): void {
		// Because it is the one that genuinely does: a write-off and an extra
		// charge are the same kind of decision in opposite directions.
		$this->assertSame( 3.0, Entries::signed( Entries::ADJUSTMENT, 3 ) );
		$this->assertSame( -3.0, Entries::signed( Entries::ADJUSTMENT, -3 ) );
	}

	public function test_every_event_type_is_one_kind_or_the_other(): void {
		/*
		 * Checked across the whole list so a type added later cannot arrive
		 * without a sign — it would silently become a credit, because that is
		 * what the fallback does.
		 */
		foreach ( Entries::TYPES as $type ) {
			$adds     = in_array( $type, Entries::ADDS, true );
			$consumes = in_array( $type, Entries::CONSUMES, true );
			$both     = Entries::ADJUSTMENT === $type;

			$this->assertTrue( $adds || $consumes || $both, $type );
			$this->assertFalse( $adds && $consumes, $type );
		}
	}

	public function test_the_eleven_are_the_eleven(): void {
		// The data model names eleven. A twelfth arriving without a decision
		// behind it is how the ledger stops reconciling.
		$this->assertCount( 11, Entries::TYPES );
		$this->assertTrue( Entries::exists( Entries::WORK_USAGE ) );
		$this->assertFalse( Entries::exists( 'write-off' ) );
	}

	/* --------------------------------------------- a balance is its entries */

	public function test_a_balance_is_the_sum_of_its_entries(): void {
		$entries = array(
			array( 'hours' => 10.0 ),
			array( 'hours' => -2.5 ),
			array( 'hours' => -1.25 ),
		);

		$this->assertSame( 6.25, Entries::balance( $entries ) );
	}

	public function test_an_empty_ledger_is_nought_rather_than_nothing(): void {
		// A site with no entries has no hours, which is a number. Anything else
		// makes every caller decide what "no ledger" meant.
		$this->assertSame( 0.0, Entries::balance( array() ) );
	}

	public function test_reserving_and_then_starting_work_charges_the_hours_once(): void {
		/*
		 * The one that would bill every client double. Work reserves at Up Next
		 * and converts at In Development, and converting releases the
		 * reservation as it books the usage — so the site is down five hours,
		 * not ten. Taking both without the release is the obvious
		 * implementation and it is wrong on every item that ever ships.
		 */
		$entries = array(
			array( 'hours' => Entries::signed( Entries::ALLOCATION, 10 ) ),
			array( 'hours' => Entries::signed( Entries::WORK_RESERVATION, 5 ) ),
			array( 'hours' => Entries::signed( Entries::WORK_RELEASE, 5 ) ),
			array( 'hours' => Entries::signed( Entries::WORK_USAGE, 5 ) ),
		);

		$this->assertSame( 5.0, Entries::balance( $entries ) );
	}

	public function test_cancelling_before_the_work_starts_gives_the_hours_back_in_full(): void {
		$entries = array(
			array( 'hours' => Entries::signed( Entries::ALLOCATION, 10 ) ),
			array( 'hours' => Entries::signed( Entries::WORK_RESERVATION, 4 ) ),
			array( 'hours' => Entries::signed( Entries::WORK_RELEASE, 4 ) ),
		);

		$this->assertSame( 10.0, Entries::balance( $entries ) );
	}

	/* ------------------------------------------------------- what is refused */

	public function test_an_entry_with_no_hours_in_it_is_refused(): void {
		// It says nothing and adds a row to a record people read.
		$this->assertSame( 'no_hours', Entries::refuse( $this->entry( Entries::ADJUSTMENT, 0 ), 10.0 ) );
	}

	public function test_an_entry_that_cannot_say_where_it_came_from_is_refused(): void {
		// Reconciling against the work is the only reason this table exists.
		$this->assertSame(
			'no_source',
			Entries::refuse( $this->entry( Entries::WORK_USAGE, 2, array( 'source_id' => '' ) ), 10.0 )
		);
	}

	public function test_an_entry_with_nobody_behind_it_is_refused(): void {
		// Never system-anonymous. Every hour that moved, moved because somebody
		// did something.
		$this->assertSame(
			'no_actor',
			Entries::refuse( $this->entry( Entries::WORK_USAGE, 2, array( 'actor' => 0 ) ), 10.0 )
		);
	}

	public function test_an_adjustment_must_say_why(): void {
		// It is somebody's decision rather than the machine's, and a decision
		// with no reason is one nobody can answer for six months later.
		$this->assertSame( 'no_reason', Entries::refuse( $this->entry( Entries::ADJUSTMENT, -2 ), 10.0 ) );
		$this->assertSame( '', Entries::refuse( $this->entry( Entries::ADJUSTMENT, -2, array( 'reason' => 'Extra time after review.' ) ), 10.0 ) );
	}

	public function test_an_unknown_event_type_is_refused(): void {
		$this->assertSame( 'unknown_event_type', Entries::refuse( $this->entry( 'write-off', -2 ), 10.0 ) );
	}

	public function test_an_ordinary_entry_is_accepted(): void {
		$this->assertSame( '', Entries::refuse( $this->entry( Entries::WORK_RESERVATION, 5 ), 10.0 ) );
	}

	/* ------------------------------------------------------- going negative */

	public function test_a_balance_may_not_go_below_nought_on_its_own(): void {
		$this->assertSame( 'would_go_negative', Entries::refuse( $this->entry( Entries::WORK_RESERVATION, 12 ), 10.0 ) );
	}

	public function test_spending_a_balance_exactly_to_nought_is_fine(): void {
		// The boundary. Refusing here would make the last hour of every package
		// unspendable.
		$this->assertSame( '', Entries::refuse( $this->entry( Entries::WORK_RESERVATION, 10 ), 10.0 ) );
	}

	public function test_the_override_lets_it_through_and_still_wants_a_reason(): void {
		/*
		 * COMM-3 allows a negative balance with the Primary administrator's
		 * override, "which is recorded". Recorded means a reason — an override
		 * with nothing said for it is exactly as unanswerable as an adjustment
		 * with nothing said for it, and this is the more consequential of the
		 * two.
		 */
		$this->assertSame(
			'no_reason',
			Entries::refuse( $this->entry( Entries::WORK_RESERVATION, 12 ), 10.0, true )
		);

		$this->assertSame(
			'',
			Entries::refuse(
				$this->entry( Entries::WORK_RESERVATION, 12, array( 'reason' => 'Agreed with the client, invoice to follow.' ) ),
				10.0,
				true
			)
		);
	}

	public function test_an_override_is_not_needed_for_an_entry_that_adds(): void {
		$this->assertSame( '', Entries::refuse( $this->entry( Entries::TOP_UP, 5 ), -0.0 ) );
	}

	/* --------------------------------------------------- the order they go */

	public function test_hours_expiring_soonest_are_spent_first(): void {
		$order = Entries::consumption_order(
			array(
				array(
					'id'         => 'later',
					'event_type' => Entries::TOP_UP,
					'expires_at' => 2000,
				),
				array(
					'id'         => 'sooner',
					'event_type' => Entries::TOP_UP,
					'expires_at' => 1000,
				),
			)
		);

		$this->assertSame( array( 'sooner', 'later' ), array_column( $order, 'id' ) );
	}

	public function test_package_hours_go_before_a_top_up_that_expires_the_same_day(): void {
		$order = Entries::consumption_order(
			array(
				array(
					'id'         => 'top-up',
					'event_type' => Entries::TOP_UP,
					'expires_at' => 1000,
				),
				array(
					'id'         => 'package',
					'event_type' => Entries::ALLOCATION,
					'expires_at' => 1000,
				),
			)
		);

		$this->assertSame( array( 'package', 'top-up' ), array_column( $order, 'id' ) );
	}

	public function test_hours_that_never_expire_are_kept_for_last(): void {
		/*
		 * The rule read at its limit: never expiring is later than any date.
		 * Spending the durable hours first would burn what cannot be replaced
		 * and let what was already paid for lapse.
		 */
		$order = Entries::consumption_order(
			array(
				array(
					'id'         => 'forever',
					'event_type' => Entries::ALLOCATION,
					'expires_at' => 0,
				),
				array(
					'id'         => 'dated',
					'event_type' => Entries::TOP_UP,
					'expires_at' => 5000,
				),
			)
		);

		$this->assertSame( array( 'dated', 'forever' ), array_column( $order, 'id' ) );
	}

	/* --------------------------------------------- and nothing can be edited */

	public function test_the_ledger_offers_no_way_to_change_an_entry(): void {
		/*
		 * A claim about an absence, which no ordinary test can make: the
		 * guarantee is that there is no update and no delete, so checking that
		 * a particular call fails proves nothing. This reads the class instead.
		 *
		 * It is worth a test because the pressure to add one is real and always
		 * arrives with a good reason attached — a typo somebody wants to fix, a
		 * duplicate somebody wants gone. The answer to both is another entry.
		 */
		$methods = get_class_methods( Ledger::class );

		foreach ( $methods as $method ) {
			$this->assertDoesNotMatchRegularExpression(
				'/^(update|edit|delete|remove|set|clear|reset)/',
				$method,
				$method
			);
		}

		$this->assertContains( 'append', $methods );
	}
}
