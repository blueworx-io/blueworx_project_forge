<?php
/**
 * Whether a site has the hours, asked separately from whether anybody has the time.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Commerce\HoursGate;
use Blueworx\Forge\Commerce\Support;
use Blueworx\Forge\Work\Gates;
use PHPUnit\Framework\TestCase;

/**
 * #150, COMM-3. The support-hours gate at Up Next.
 *
 * **Two questions, two answers, and neither one hides the other.** Capacity
 * asks whether the people have the time; support hours asks whether the client
 * has the money. They fail for different reasons, they are fixed by different
 * people, and a gate that reported only the first failure it met would send
 * somebody to rearrange a week when the actual problem was a lapsed package —
 * and then, having rearranged it, refuse them again for the reason nobody
 * mentioned.
 *
 * So the assertion this issue turns on is the boring-looking one: a pass on one
 * and a failure on the other reports both.
 */
final class SupportHoursGateTest extends TestCase {

	/**
	 * An item planned at thirteen hours.
	 *
	 * @param array<string, mixed> $overrides Anything to say differently.
	 * @return array<string, mixed>
	 */
	private function item( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'               => 'wrk_one',
				'client_site_id'   => 'cst_one',
				'stage'            => 'up-next',
				'prior_stage'      => '',
				'terminal_outcome' => '',
				'commercial_class' => 'chargeable',
				'hours_primary'    => 10.0,
				'hours_review'     => 2.0,
				'hours_delivery'   => 1.0,
			),
			$overrides
		);
	}

	/**
	 * A site's position, as Commerce\Support answers it.
	 *
	 * @param string $state One of Support::STATES.
	 * @return array<string, mixed>
	 */
	private function entitlement( string $state = Support::ACTIVE ): array {
		return array(
			'state'         => $state,
			'may_use_hours' => Support::ACTIVE === $state,
		);
	}

	/* ------------------------------------------------------ the arithmetic */

	public function test_a_site_with_the_hours_passes(): void {
		$result = HoursGate::assess( $this->item(), $this->entitlement(), 40.0, 0.0 );

		$this->assertTrue( $result['sufficient'] );
		$this->assertSame( 13.0, $result['needed'] );
		$this->assertSame( 40.0, $result['available'] );
		$this->assertSame( 0.0, $result['shortfall'] );
	}

	public function test_hours_this_work_already_holds_are_its_own_to_spend(): void {
		/*
		 * The mistake that would make this gate refuse everything it is meant to
		 * allow. Planned work has already reserved its hours, so the site's
		 * balance no longer contains them — and a check that compared the plan
		 * against the balance alone would refuse the very work whose reservation
		 * emptied it.
		 */
		$result = HoursGate::assess( $this->item(), $this->entitlement(), 0.0, 13.0 );

		$this->assertTrue( $result['sufficient'] );
		$this->assertSame( 13.0, $result['available'] );
	}

	public function test_a_site_short_of_hours_is_refused_and_says_by_how_many(): void {
		// By how many, because "not enough" is not a figure anybody can act on:
		// somebody has to decide whether to sell four hours or cut the plan.
		$result = HoursGate::assess( $this->item(), $this->entitlement(), 9.0, 0.0 );

		$this->assertFalse( $result['sufficient'] );
		$this->assertSame( 4.0, $result['shortfall'] );
		$this->assertSame( HoursGate::NOT_ENOUGH, $result['because'] );
	}

	/* ------------------------------------------- and what the hours are for */

	public function test_a_site_that_may_not_use_hours_is_refused_however_many_it_has(): void {
		/*
		 * COMM-4: a lapsed site's balance is frozen, not voided. The hours are
		 * still there and still the client's, and they are still not spendable
		 * until the package is renewed — so a check that read the balance alone
		 * would happily spend a lapsed client's frozen hours.
		 */
		foreach ( array( Support::LAPSED, Support::SUSPENDED, Support::NONE, Support::SCHEDULED ) as $state ) {
			$result = HoursGate::assess( $this->item(), $this->entitlement( $state ), 500.0, 0.0 );

			$this->assertFalse( $result['sufficient'], $state . ' was allowed to spend hours' );
			$this->assertSame( HoursGate::NO_PACKAGE, $result['because'] );
			$this->assertSame( $state, $result['state'] );
		}
	}

	public function test_work_that_costs_nothing_passes_whatever_the_site_s_position(): void {
		// A free bug on a lapsed site is still fixed. COMM-5 says the client is
		// not paying for it, so there is nothing here for a package to authorise.
		$free = $this->item( array( 'commercial_class' => 'free-bug' ) );

		$result = HoursGate::assess( $free, $this->entitlement( Support::NONE ), 0.0, 0.0 );

		$this->assertTrue( $result['sufficient'] );
		$this->assertSame( 0.0, $result['needed'] );
	}

	public function test_work_planned_at_no_hours_passes(): void {
		$nothing = $this->item( array( 'hours_primary' => 0, 'hours_review' => 0, 'hours_delivery' => 0 ) );

		$this->assertTrue( HoursGate::assess( $nothing, $this->entitlement( Support::LAPSED ), 0.0, 0.0 )['sufficient'] );
	}

	public function test_work_that_costs_nothing_is_answered_without_reading_anything(): void {
		/*
		 * Not an optimisation for its own sake. The standup board evaluates the
		 * next gate for every card it draws, and three ledger reads per card is
		 * a query count that grows with the studio's work — which is how a
		 * screen that was fine in testing becomes slow in the second year.
		 *
		 * So the short circuit has to give the same answer as the long way
		 * round, and that is what is asserted rather than that it is faster.
		 */
		$nothing = $this->item( array( 'hours_primary' => 0, 'hours_review' => 0, 'hours_delivery' => 0 ) );
		$free    = $this->item( array( 'commercial_class' => 'free-bug' ) );

		$this->assertFalse( HoursGate::chargeable( $nothing ) );
		$this->assertFalse( HoursGate::chargeable( $free ) );
		$this->assertTrue( HoursGate::chargeable( $this->item() ) );

		$this->assertSame(
			HoursGate::assess( $free, $this->entitlement( Support::NONE ), 0.0, 0.0 )['sufficient'],
			HoursGate::free()['sufficient']
		);
		$this->assertSame( 0.0, HoursGate::free()['needed'] );
		$this->assertSame( HoursGate::CLEAR, HoursGate::free()['because'] );
	}

	/* --------------------------------------------- the two-answers property */

	/**
	 * The gate, in the shape Work\Transition hands it its answers.
	 *
	 * @param array<string, mixed> $support What the hours check found.
	 * @param array<int, mixed>    $over    Who is over-booked, if anybody.
	 * @return array{unmet: array<int, array<string, mixed>>, checks: array<int, array<string, mixed>>}
	 */
	private function evaluate( array $support, array $over = array() ): array {
		return Gates::evaluate(
			'G-UP-NEXT',
			$this->item(
				array(
					'primary_user_id' => 'usr_a',
					'reviewer_id'     => 'usr_b',
					'deliverer_id'    => 'usr_c',
					'planned_start'   => '2026-10-05',
					'planned_due'     => '2026-10-09',
					'priority'        => 'normal',
				)
			),
			array(
				'G-UP-NEXT-4' => array( 'actor' => 3 ),
				'G-UP-NEXT-7' => array( 'actor' => 3 ),
			),
			array(
				'capacity'      => array(
					'over'   => $over,
					'reason' => '',
				),
				'support_hours' => $support,
			)
		);
	}

	public function test_the_hours_check_now_refuses_a_move_rather_than_reporting(): void {
		// It was deferred from #105 until the ledger existed. The ledger exists.
		$short = HoursGate::assess( $this->item(), $this->entitlement(), 0.0, 0.0 );
		$result = $this->evaluate( $short );

		$this->assertContains( 'G-UP-NEXT-9', array_column( $result['unmet'], 'id' ) );
	}

	public function test_a_refusal_carries_the_figures_it_was_refused_on(): void {
		$refused = array_column( $this->evaluate( HoursGate::assess( $this->item(), $this->entitlement(), 9.0, 0.0 ) )['unmet'], null, 'id' );

		$this->assertSame( 4.0, $refused['G-UP-NEXT-9']['hours']['shortfall'] );
		$this->assertSame( 13.0, $refused['G-UP-NEXT-9']['hours']['needed'] );
	}

	public function test_a_pass_on_one_and_a_failure_on_the_other_reports_both(): void {
		/*
		 * #150's acceptance criterion, both ways round. The failure mode it
		 * rules out is the cheap implementation of a gate: stop at the first
		 * thing that is wrong. Told only about capacity, somebody rearranges a
		 * week and is then refused for hours; told only about hours, somebody
		 * sells a top-up and is then refused for capacity.
		 */
		$plenty = HoursGate::assess( $this->item(), $this->entitlement(), 500.0, 0.0 );
		$none   = HoursGate::assess( $this->item(), $this->entitlement( Support::LAPSED ), 500.0, 0.0 );
		$booked = array( array( 'user_id' => 'usr_a', 'week_from' => '2026-10-05', 'excess' => 12 ) );

		$hours_only = $this->evaluate( $none );
		$room_only  = $this->evaluate( $plenty, $booked );

		foreach ( array( 'hours short' => $hours_only, 'room short' => $room_only ) as $case => $result ) {
			$checks = array_column( $result['checks'], null, 'id' );

			$this->assertArrayHasKey( 'G-UP-NEXT-8', $checks, $case . ' lost the capacity answer' );
			$this->assertArrayHasKey( 'G-UP-NEXT-9', $checks, $case . ' lost the hours answer' );
		}

		// And each says the one that failed, rather than both going down
		// together.
		$this->assertSame( 'pass', array_column( $hours_only['checks'], null, 'id' )['G-UP-NEXT-8']['result'] );
		$this->assertSame( 'fail', array_column( $hours_only['checks'], null, 'id' )['G-UP-NEXT-9']['result'] );
		$this->assertSame( 'fail', array_column( $room_only['checks'], null, 'id' )['G-UP-NEXT-8']['result'] );
		$this->assertSame( 'pass', array_column( $room_only['checks'], null, 'id' )['G-UP-NEXT-9']['result'] );
	}

	public function test_the_capacity_reason_does_not_excuse_a_shortage_of_hours(): void {
		/*
		 * CAP-4 lets a studio administrator go ahead with an over-booked week
		 * for a stated reason. That is a decision about our own people's time.
		 * It is not a decision about the client's money, and one override
		 * quietly answering both questions is how work gets done that nobody
		 * has hours for.
		 */
		$result = Gates::evaluate(
			'G-UP-NEXT',
			$this->item(
				array(
					'primary_user_id' => 'usr_a',
					'reviewer_id'     => 'usr_b',
					'deliverer_id'    => 'usr_c',
					'planned_start'   => '2026-10-05',
					'planned_due'     => '2026-10-09',
					'priority'        => 'normal',
				)
			),
			array(
				'G-UP-NEXT-4' => array( 'actor' => 3 ),
				'G-UP-NEXT-7' => array( 'actor' => 3 ),
			),
			array(
				'capacity'      => array(
					'over'   => array( array( 'user_id' => 'usr_a' ) ),
					'reason' => 'The client has agreed the overtime.',
				),
				'support_hours' => HoursGate::assess( $this->item(), $this->entitlement(), 0.0, 0.0 ),
			)
		);

		$unmet = array_column( $result['unmet'], 'id' );

		$this->assertNotContains( 'G-UP-NEXT-8', $unmet, 'the capacity reason was not accepted' );
		$this->assertContains( 'G-UP-NEXT-9', $unmet, 'the capacity reason excused the hours' );
	}
}
