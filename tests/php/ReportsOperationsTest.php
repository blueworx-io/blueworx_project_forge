<?php
/**
 * The numbers about running the studio, as against delivering work.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Commerce\Entries;
use Blueworx\Forge\Reports\Operations;
use Blueworx\Forge\Work\Events;
use PHPUnit\Framework\TestCase;

/**
 * #261. The six reports #176 listed and left.
 *
 * Each is the records counted, now — nothing is stored, so "it reconciles to
 * the records behind it" is true by construction. What these tests are really
 * about is the handful of places where the obvious count is the wrong one:
 *
 * - somebody with no hours set up is not over-booked, they are not set up,
 * - one job pushed through three times is three decisions, not one item,
 * - hours held are committed, not spent, and adding them together says a client
 *   has used what they have only set aside,
 * - a hundred per cent of nothing is the most reassuring wrong number a screen
 *   can show.
 */
final class ReportsOperationsTest extends TestCase {

	/**
	 * One ledger entry, signed the way the ledger stores it.
	 *
	 * @param string $type  Event type.
	 * @param float  $hours Magnitude.
	 * @return array<string, mixed>
	 */
	private function entry( string $type, float $hours ): array {
		return array(
			'event_type' => $type,
			'hours'      => Entries::signed( $type, $hours ),
		);
	}

	/* ------------------------------------------------ whether people have room */

	public function test_capacity_reports_the_share_as_well_as_the_hours(): void {
		// Forty hours committed means nothing without knowing whether the week
		// holds thirty-five or fifty.
		$found = Operations::compute(
			array(
				'capacity' => array(
					array(
						'committed' => 30.0,
						'available' => 40.0,
					),
					array(
						'committed' => 10.0,
						'available' => 40.0,
					),
				),
			)
		)['capacity_utilisation'];

		$this->assertSame( 2, $found['people'] );
		$this->assertSame( 40.0, $found['committed'] );
		$this->assertSame( 80.0, $found['available'] );
		$this->assertSame( 0.5, $found['share'] );
		$this->assertSame( 0, $found['over'] );
	}

	public function test_somebody_over_their_hours_is_counted(): void {
		$found = Operations::compute(
			array(
				'capacity' => array(
					array(
						'committed' => 50.0,
						'available' => 40.0,
					),
				),
			)
		)['capacity_utilisation'];

		$this->assertSame( 1, $found['over'] );
	}

	public function test_somebody_with_no_hours_set_up_is_not_over_booked(): void {
		/*
		 * They are not set up. Counting them would put every new person on the
		 * list the day they are created, and a list that is always wrong is a
		 * list nobody looks at.
		 */
		$found = Operations::compute(
			array(
				'capacity' => array(
					array(
						'committed' => 12.0,
						'available' => 0.0,
					),
				),
			)
		)['capacity_utilisation'];

		$this->assertSame( 0, $found['over'] );
	}

	public function test_nobody_at_all_has_no_share_rather_than_a_hundred_per_cent(): void {
		$this->assertNull( Operations::compute( array() )['capacity_utilisation']['share'] );
	}

	/* ------------------------------------------ how often we overrode ourselves */

	public function test_the_two_kinds_of_override_are_counted_apart(): void {
		/*
		 * CAP-E3 keeps them as separate marks because they answer different
		 * questions: a workflow override is a gate somebody went round, a
		 * capacity override is a week somebody agreed to overfill. Adding them
		 * together would undo that.
		 */
		$found = Operations::compute(
			array(
				'items' => array(
					array(
						'override_used'          => true,
						'capacity_override_used' => false,
					),
					array(
						'override_used'          => false,
						'capacity_override_used' => true,
					),
					array(
						'override_used'          => true,
						'capacity_override_used' => true,
					),
				),
			)
		)['overrides'];

		$this->assertSame( 2, $found['workflow'] );
		$this->assertSame( 2, $found['capacity'] );
		$this->assertSame( 3, $found['items'] );
	}

	public function test_overrides_are_counted_as_decisions_rather_than_items(): void {
		// One job pushed through three times is three decisions somebody made,
		// and the item count would show it as one.
		$found = Operations::compute(
			array(
				'items'  => array( array( 'capacity_override_used' => true ) ),
				'events' => array(
					array( 'action' => Events::OVER_ALLOCATED ),
					array( 'action' => Events::OVER_ALLOCATED ),
					array( 'action' => Events::OVER_ALLOCATED ),
					array( 'action' => Events::MOVED ),
				),
			)
		)['overrides'];

		$this->assertSame( 3, $found['occasions'] );
		$this->assertSame( 1, $found['capacity'] );
	}

	/* --------------------------------------------- where clients' hours went */

	public function test_work_and_meeting_spend_are_reported_apart(): void {
		// "We spent forty hours" is a different conversation from "thirty of
		// those were meetings".
		$found = Operations::compute(
			array(
				'ledger' => array(
					$this->entry( Entries::ALLOCATION, 40 ),
					$this->entry( Entries::WORK_USAGE, 10 ),
					$this->entry( Entries::MEETING_USAGE, 6 ),
				),
			)
		)['hours'];

		$this->assertSame( 40.0, $found['granted'] );
		$this->assertSame( 10.0, $found['work_used'] );
		$this->assertSame( 6.0, $found['meeting_used'] );
		$this->assertSame( 16.0, $found['spent'] );
	}

	public function test_hours_only_set_aside_are_not_reported_as_spent(): void {
		/*
		 * Held hours are committed and not yet used. Adding them to spend would
		 * tell a client they have used what they have only reserved — and the
		 * conversation that follows is about an invoice for work nobody has
		 * done.
		 */
		$found = Operations::compute(
			array(
				'ledger' => array(
					$this->entry( Entries::ALLOCATION, 40 ),
					$this->entry( Entries::WORK_RESERVATION, 13 ),
					$this->entry( Entries::MEETING_RESERVATION, 2 ),
				),
			)
		)['hours'];

		$this->assertSame( 0.0, $found['spent'] );
		$this->assertSame( 15.0, $found['held'] );
	}

	public function test_released_hours_stop_being_held(): void {
		$found = Operations::compute(
			array(
				'ledger' => array(
					$this->entry( Entries::WORK_RESERVATION, 13 ),
					$this->entry( Entries::WORK_RELEASE, 13 ),
					$this->entry( Entries::WORK_USAGE, 13 ),
				),
			)
		)['hours'];

		$this->assertSame( 0.0, $found['work_held'] );
		$this->assertSame( 13.0, $found['work_used'] );
	}

	public function test_an_adjustment_is_reported_with_its_direction(): void {
		// It is the one entry that goes both ways, and which way is the whole
		// point of reporting it: hours written off and hours charged after the
		// fact are different things.
		$found = Operations::compute(
			array(
				'ledger' => array(
					$this->entry( Entries::ADJUSTMENT, -5 ),
					$this->entry( Entries::ADJUSTMENT, 2 ),
				),
			)
		)['hours'];

		$this->assertSame( -3.0, $found['adjusted'] );
	}

	/* ------------------------------------------ which sites are stuck getting live */

	public function test_readiness_is_the_launch_answer_and_not_the_percentage(): void {
		/*
		 * ONB-1 counts completion over required steps and readiness over
		 * launch-critical ones, so a site can be at a hundred per cent with a
		 * critical step outstanding. Reading readiness off the percentage would
		 * declare that site ready, which is the one mistake that is found after
		 * it is live.
		 */
		$found = Operations::compute(
			array(
				'onboarding' => array(
					'cst_ready'   => array(
						array(
							'status'          => 'approved',
							'launch_critical' => true,
						),
					),
					'cst_not_yet' => array(
						array(
							'status'          => 'pending',
							'launch_critical' => true,
						),
					),
				),
			)
		)['onboarding_readiness'];

		$this->assertSame( 2, $found['sites'] );
		$this->assertSame( 1, $found['ready'] );
		$this->assertSame( 1, $found['not_ready'] );
	}

	public function test_no_sites_have_no_median_rather_than_nought(): void {
		// Nought would read as "every site is at the very beginning", which is
		// a different and much more alarming statement than "there are none".
		$this->assertNull( Operations::compute( array() )['onboarding_readiness']['median'] );
	}

	/* ------------------------------- what happens to what clients ask for */

	public function test_every_intake_state_is_present_even_when_empty(): void {
		/*
		 * The same rule the stage distribution follows: a state that disappears
		 * when it empties reads as "not applicable" rather than "none", and the
		 * two look identical on a chart.
		 */
		$found = Operations::compute(
			array(
				'submissions' => array(
					array(
						'intake_state' => 'received',
						'type'         => 'bug',
					),
					array(
						'intake_state' => 'converted',
						'type'         => 'request',
					),
				),
			)
		)['request_funnel'];

		$this->assertSame( 2, $found['total'] );
		$this->assertSame(
			array( 'received', 'in-review', 'accepted', 'declined', 'converted' ),
			array_keys( $found['states'] )
		);
		$this->assertSame( 1, $found['states']['received'] );
		$this->assertSame( 0, $found['states']['accepted'] );
	}

	public function test_the_funnel_says_what_kind_of_thing_people_asked_for(): void {
		$found = Operations::compute(
			array(
				'submissions' => array(
					array(
						'intake_state' => 'received',
						'type'         => 'bug',
					),
					array(
						'intake_state' => 'received',
						'type'         => 'bug',
					),
					array(
						'intake_state' => 'received',
						'type'         => 'idea',
					),
				),
			)
		)['request_funnel'];

		$this->assertSame( 2, $found['kinds']['bug'] );
		$this->assertSame( 1, $found['kinds']['idea'] );
	}

	/* ------------------------------------------ whether our email is arriving */

	public function test_delivery_reports_what_arrived_and_what_did_not(): void {
		$found = Operations::compute(
			array(
				'notifications' => array(
					array( 'outcome' => 'sent' ),
					array( 'outcome' => 'sent' ),
					array( 'outcome' => 'sent' ),
					array( 'outcome' => 'failed' ),
				),
			)
		)['email_delivery'];

		$this->assertSame( 4, $found['total'] );
		$this->assertSame( 3, $found['delivered'] );
		$this->assertSame( 1, $found['failed'] );
		$this->assertSame( 0.75, $found['share'] );
	}

	public function test_nothing_sent_has_no_share_rather_than_a_perfect_one(): void {
		/*
		 * A hundred per cent of nothing is the most reassuring wrong number a
		 * screen can show, and email is exactly where somebody would act on it
		 * — the report is being read because a client says they never heard
		 * from us.
		 */
		$this->assertNull( Operations::compute( array() )['email_delivery']['share'] );
		$this->assertSame( 0, Operations::compute( array() )['email_delivery']['total'] );
	}

	/* -------------------------------------------------------- and all six */

	public function test_all_six_are_answered_even_with_nothing_to_go_on(): void {
		// A screen draws six sections whatever happens, so six answers have to
		// come back — an absent key is a section that renders as broken.
		$found = Operations::compute( array() );

		foreach (
			array(
				'capacity_utilisation',
				'overrides',
				'hours',
				'onboarding_readiness',
				'request_funnel',
				'email_delivery',
			) as $report
		) {
			$this->assertArrayHasKey( $report, $found );
		}
	}
}
