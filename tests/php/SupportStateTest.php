<?php
/**
 * What a site was entitled to, on any given day.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Commerce\Support;
use PHPUnit\Framework\TestCase;

/**
 * #146. "A client's entitlement on any past date can be reconstructed from the
 * record."
 *
 * Every change of position closes one period and opens the next, so the answer
 * is whichever period covers the date. The tests below are the cases where that
 * reading has to be exactly right, because each of them is a real commercial
 * conversation with a client:
 *
 * - the boundaries, where a day belongs to one period and not the other,
 * - a site suspended twice, which the obvious flag-on-a-row design cannot
 *   answer at all,
 * - the difference between never having had a package and having let one run
 *   out, which is the difference between losing a sale and losing hours the
 *   client paid for.
 */
final class SupportStateTest extends TestCase {

	/**
	 * One period.
	 *
	 * @param string               $from      YYYY-MM-DD.
	 * @param string               $to        YYYY-MM-DD, or '' for open.
	 * @param array<string, mixed> $overrides Anything else.
	 * @return array<string, mixed>
	 */
	private function period( string $from, string $to = '', array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                 => 'spk_' . $from,
				'state'              => Support::ACTIVE,
				'starts_on'          => $from,
				'ends_on'            => $to,
				'term_ends_on'       => $to,
				'package_version_id' => 'pkv_one',
				'hours_granted'      => 10.0,
			),
			$overrides
		);
	}

	/* -------------------------------------------------------- the boundaries */

	public function test_the_first_day_of_a_period_is_covered(): void {
		$this->assertTrue( Support::covers( $this->period( '2026-01-01', '2026-12-31' ), '2026-01-01' ) );
	}

	public function test_the_last_day_of_a_period_is_covered(): void {
		// Both ends inclusive: a period is the days a client has cover on, and
		// the last one is one of them. Getting this wrong loses a client a day
		// of cover on every term they ever hold.
		$this->assertTrue( Support::covers( $this->period( '2026-01-01', '2026-12-31' ), '2026-12-31' ) );
	}

	public function test_the_day_after_is_not(): void {
		$this->assertFalse( Support::covers( $this->period( '2026-01-01', '2026-12-31' ), '2027-01-01' ) );
	}

	public function test_a_period_with_no_end_runs_on(): void {
		$this->assertTrue( Support::covers( $this->period( '2026-01-01' ), '2030-06-01' ) );
	}

	/* ------------------------------------------------------- the four states */

	public function test_a_site_with_no_periods_has_no_package(): void {
		$this->assertSame( Support::NONE, Support::state_on( array(), '2026-06-01' ) );
	}

	public function test_a_site_inside_its_term_is_on_support(): void {
		$this->assertSame(
			Support::ACTIVE,
			Support::state_on( array( $this->period( '2026-01-01', '2026-12-31' ) ), '2026-06-01' )
		);
	}

	public function test_a_site_past_the_end_of_its_term_has_lapsed(): void {
		/*
		 * Not "none". COMM-4 freezes a lapsed site's remaining balance pending
		 * renewal, so this is a renewal conversation with hours still on it —
		 * and treating it as a client who never bought anything is how those
		 * hours get lost.
		 */
		$this->assertSame(
			Support::LAPSED,
			Support::state_on( array( $this->period( '2026-01-01', '2026-12-31' ) ), '2027-03-01' )
		);
	}

	public function test_a_site_we_stopped_is_suspended_rather_than_lapsed(): void {
		// One we did on purpose and can undo, rather than one that ran out.
		$periods = array(
			$this->period( '2026-01-01', '2026-05-31' ),
			$this->period( '2026-06-01', '', array( 'state' => Support::SUSPENDED ) ),
		);

		$this->assertSame( Support::SUSPENDED, Support::state_on( $periods, '2026-08-01' ) );
	}

	public function test_a_package_agreed_for_next_month_is_scheduled(): void {
		$this->assertSame(
			Support::SCHEDULED,
			Support::state_on( array( $this->period( '2026-09-01', '2027-08-31' ) ), '2026-08-01' )
		);
	}

	public function test_a_scheduled_period_is_active_once_its_date_arrives(): void {
		/*
		 * The row is not rewritten when the start date comes round — nothing
		 * runs at midnight to do it, and a state that depends on a cron job
		 * having fired is a state that is wrong whenever it did not. The date
		 * is read instead.
		 */
		$periods = array( $this->period( '2026-09-01', '2027-08-31', array( 'state' => Support::SCHEDULED ) ) );

		$this->assertSame( Support::ACTIVE, Support::state_on( $periods, '2026-09-01' ) );
		$this->assertSame( Support::ACTIVE, Support::state_on( $periods, '2026-11-11' ) );
	}

	/* --------------------------------------------------- reconstructing a past */

	public function test_a_site_suspended_twice_can_still_say_what_was_true_when(): void {
		/*
		 * The case a suspended flag on one row cannot answer, because it only
		 * remembers the last time. Four periods, four answers, and every one of
		 * them a lookup rather than a replay.
		 */
		$periods = array(
			$this->period( '2026-01-01', '2026-02-28' ),
			$this->period( '2026-03-01', '2026-03-31', array( 'state' => Support::SUSPENDED ) ),
			$this->period( '2026-04-01', '2026-07-31' ),
			$this->period( '2026-08-01', '2026-08-31', array( 'state' => Support::SUSPENDED ) ),
			$this->period( '2026-09-01', '2026-12-31' ),
		);

		$this->assertSame( Support::ACTIVE, Support::state_on( $periods, '2026-01-15' ) );
		$this->assertSame( Support::SUSPENDED, Support::state_on( $periods, '2026-03-15' ) );
		$this->assertSame( Support::ACTIVE, Support::state_on( $periods, '2026-05-15' ) );
		$this->assertSame( Support::SUSPENDED, Support::state_on( $periods, '2026-08-15' ) );
		$this->assertSame( Support::ACTIVE, Support::state_on( $periods, '2026-10-15' ) );
		$this->assertSame( Support::LAPSED, Support::state_on( $periods, '2027-01-15' ) );
	}

	public function test_a_date_before_anything_started_is_not_lapsed(): void {
		/*
		 * Scheduled rather than lapsed, and the distinction is the point:
		 * nothing had run out because nothing had begun. Reading a date before
		 * a client's first package as lapsed would put them on a renewal list
		 * for a package they have never held.
		 */
		$this->assertSame(
			Support::SCHEDULED,
			Support::state_on( array( $this->period( '2026-01-01', '2026-12-31' ) ), '2025-06-01' )
		);
	}

	public function test_consecutive_periods_leave_no_day_uncovered(): void {
		// The join between two periods. A gap of one day here is a day a client
		// was uncovered according to the record and covered according to
		// everybody's memory of it.
		$periods = array(
			$this->period( '2026-01-01', '2026-06-30' ),
			$this->period( '2026-07-01', '2026-12-31' ),
		);

		foreach ( array( '2026-06-30', '2026-07-01' ) as $date ) {
			$this->assertSame( Support::ACTIVE, Support::state_on( $periods, $date ), $date );
		}
	}

	/* ------------------------------------------------------ what it all means */

	public function test_only_an_active_site_may_spend_its_hours(): void {
		/*
		 * Said once, here, rather than left for each caller to work out from
		 * the state. There are five states, and every caller getting it right
		 * is five chances to get it wrong — on the check that decides whether a
		 * client is billed.
		 */
		$running = array( $this->period( '2026-01-01', '2026-12-31' ) );
		$stopped = array( $this->period( '2026-01-01', '', array( 'state' => Support::SUSPENDED ) ) );

		$this->assertTrue( Support::entitlement_on( $running, '2026-06-01' )['may_use_hours'] );
		$this->assertFalse( Support::entitlement_on( $stopped, '2026-06-01' )['may_use_hours'] );
		$this->assertFalse( Support::entitlement_on( $running, '2027-06-01' )['may_use_hours'] );
		$this->assertFalse( Support::entitlement_on( array(), '2026-06-01' )['may_use_hours'] );
	}

	public function test_an_entitlement_names_the_package_it_came_from(): void {
		// So a figure a client queries can be traced to the terms they were
		// sold rather than to whatever the catalogue says today.
		$answer = Support::entitlement_on( array( $this->period( '2026-01-01', '2026-12-31' ) ), '2026-06-01' );

		$this->assertSame( 'pkv_one', $answer['package_version_id'] );
		$this->assertSame( 10.0, $answer['hours_granted'] );
		$this->assertSame( '2026-06-01', $answer['on'] );
	}

	public function test_every_state_reads_as_something_a_person_would_say(): void {
		foreach ( Support::STATES as $state ) {
			$this->assertNotSame( 'Unknown', Support::label( $state ), $state );
			$this->assertNotSame( $state, Support::label( $state ), $state );
		}
	}

	public function test_the_states_nobody_stores_are_not_stored(): void {
		/*
		 * "None" and "lapsed" are what the absence of a covering period means.
		 * A row saying "this client has nothing" is a row somebody has to
		 * remember to write, and the day they forget the client looks covered.
		 */
		$this->assertNotContains( Support::NONE, Support::PERIOD_STATES );
		$this->assertNotContains( Support::LAPSED, Support::PERIOD_STATES );
		$this->assertContains( Support::ACTIVE, Support::PERIOD_STATES );
		$this->assertContains( Support::SUSPENDED, Support::PERIOD_STATES );
	}
}
