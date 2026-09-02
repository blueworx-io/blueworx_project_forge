<?php
/**
 * A client with no package is restricted by the service, not by a hidden menu.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Commerce\HoursGate;
use Blueworx\Forge\Commerce\Restrictions;
use Blueworx\Forge\Commerce\Support;
use Blueworx\Forge\Work\Submissions;
use PHPUnit\Framework\TestCase;

/**
 * #151, COMM-2. The No Support Package state.
 *
 * Two halves that are each easy to get right on their own and are usually not
 * both got right at once:
 *
 * - **The service refuses.** Whatever a screen chooses to draw, a caller going
 *   straight to the API gets the same answer.
 * - **And the doors stay open.** A client with no package is a sales
 *   conversation, not a locked door. If reporting a bug or reaching a person
 *   stops working when somebody stops paying, the product cannot be sold back
 *   to them — and the client's first experience of the restriction is a screen
 *   that looks broken.
 */
final class NoSupportPackageTest extends TestCase {

	/**
	 * An item planned at thirteen chargeable hours.
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

	/* ------------------------------------------------------ what is refused */

	public function test_only_an_active_package_may_have_chargeable_work_done_against_it(): void {
		$this->assertTrue( Restrictions::allows( Support::ACTIVE, Restrictions::CHARGEABLE_WORK ) );

		foreach ( array( Support::NONE, Support::SCHEDULED, Support::SUSPENDED, Support::LAPSED ) as $state ) {
			$this->assertFalse(
				Restrictions::allows( $state, Restrictions::CHARGEABLE_WORK ),
				$state . ' was allowed chargeable work'
			);
		}
	}

	public function test_a_scheduled_package_is_not_an_active_one(): void {
		/*
		 * The one that reads like a rounding error and is not. A package agreed
		 * to start next month is a signed sale and no hours: work done against
		 * it now would be drawing on a term that has not begun, and the client
		 * would be billed for it twice or not at all.
		 */
		$this->assertFalse( Restrictions::allows( Support::SCHEDULED, Restrictions::CHARGEABLE_WORK ) );
	}

	/* -------------------------------------------------- and what stays open */

	public function test_every_door_that_matters_stays_open_in_every_state(): void {
		/*
		 * Asserted across all five states rather than for the no-package one,
		 * because the failure this prevents is a rule written as "active sites
		 * can do things" — which is right about chargeable work and wrong about
		 * everything else, and takes a lapsed client's ability to tell us
		 * something is broken.
		 */
		foreach ( Support::STATES as $state ) {
			foreach ( Restrictions::ALWAYS as $what ) {
				$this->assertTrue(
					Restrictions::allows( $state, $what ),
					$what . ' was refused to a ' . $state . ' site'
				);
			}
		}
	}

	public function test_a_site_with_no_package_can_still_report_a_bug_and_reach_sales(): void {
		// The acceptance criterion, said in the words it is written in.
		$this->assertTrue( Restrictions::allows( Support::NONE, Restrictions::BUG_INTAKE ) );
		$this->assertTrue( Restrictions::allows( Support::NONE, Restrictions::SALES ) );
		$this->assertTrue( Restrictions::allows( Support::NONE, Restrictions::POINT_OF_CONTACT ) );
	}

	public function test_a_client_can_say_a_thing_is_broken_in_those_words(): void {
		/*
		 * "Can still report a bug" needs somewhere to report one. Until this
		 * issue the intake offered a request, an idea and a suggestion — and a
		 * broken site filed as a request queues behind things people want,
		 * which is exactly the wrong order. The client who most needs to be
		 * heard was the one being ignored.
		 */
		$this->assertTrue( Submissions::is_type( 'bug' ) );
		$this->assertContains( 'bug', Submissions::TYPES );
	}

	public function test_a_free_bug_is_not_chargeable_work_and_is_not_gated_with_it(): void {
		// COMM-5. Forge delivered it and Forge broke it. A client who has never
		// bought anything is still not paying to have our mistake fixed, and a
		// state check that caught free bugs too would refuse exactly that.
		$free = $this->item( array( 'commercial_class' => 'free-bug' ) );

		$result = HoursGate::assess(
			$free,
			array(
				'state'         => Support::NONE,
				'may_use_hours' => false,
			),
			0.0,
			0.0
		);

		$this->assertTrue( $result['sufficient'] );
	}

	/* ------------------------------------------------- and what is published */

	public function test_the_position_is_published_with_both_lists_named(): void {
		/*
		 * Both, rather than one and an inference. A client screen given only
		 * what it may do would have to work out the rest from what is missing,
		 * and a screen that works it out shows a blank where a sentence about
		 * buying a package belongs.
		 */
		$none = Restrictions::for_state( Support::NONE );

		$this->assertSame( Support::NONE, $none['state'] );
		$this->assertSame( array( Restrictions::CHARGEABLE_WORK ), $none['refused'] );
		$this->assertSame( Restrictions::ALWAYS, $none['allowed'] );
		$this->assertNotSame( '', $none['label'] );
	}

	public function test_an_active_site_is_refused_nothing(): void {
		$active = Restrictions::for_state( Support::ACTIVE );

		$this->assertSame( array(), $active['refused'] );
		$this->assertSame( Restrictions::EVERYTHING, $active['allowed'] );
	}

	public function test_every_state_publishes_an_answer_for_every_thing(): void {
		// So a capability added later cannot go unanswered for a state, which
		// would read on the client's screen as neither allowed nor refused.
		foreach ( Support::STATES as $state ) {
			$published = Restrictions::for_state( $state );

			$this->assertSame(
				count( Restrictions::EVERYTHING ),
				count( $published['allowed'] ) + count( $published['refused'] ),
				$state . ' left something unanswered'
			);
		}
	}
}
