<?php
/**
 * Nothing goes live on a site whose onboarding is not finished.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Onboarding\LaunchGate;
use PHPUnit\Framework\TestCase;

/**
 * #166. A site cannot go live with launch-critical work outstanding.
 *
 * **It gates the first go-live and nothing after it**, which was decided with
 * this issue rather than assumed. Gating every later release would mean an
 * unticked box stopping a bug fix months after the site launched — and a gate
 * that stands between somebody and an urgent fix is a gate people learn to go
 * round, which costs more than it ever protected.
 *
 * So the question it asks is not "is onboarding finished" but "would this be
 * the first thing this site has ever released". Once anything has gone live,
 * this gate has done its job and stays out of the way.
 */
final class OnboardingLaunchGateTest extends TestCase {

	/**
	 * Onboarding with something still outstanding.
	 *
	 * @return array<string, mixed>
	 */
	private function outstanding(): array {
		return array(
			'launch_ready' => false,
			'blocking'     => array(
				array(
					'id'    => 'obs_dns',
					'title' => 'Delegate the domain and DNS',
				),
				array(
					'id'    => 'obs_host',
					'title' => 'Give us access to the hosting',
				),
			),
		);
	}

	/**
	 * Onboarding that is finished.
	 *
	 * @return array<string, mixed>
	 */
	private function ready(): array {
		return array(
			'launch_ready' => true,
			'blocking'     => array(),
		);
	}

	/* ------------------------------------------------------- the first time */

	public function test_a_first_release_is_refused_while_something_is_outstanding(): void {
		$this->assertTrue( LaunchGate::refuses( $this->outstanding(), false ) );
	}

	public function test_a_first_release_is_allowed_once_onboarding_is_done(): void {
		$this->assertFalse( LaunchGate::refuses( $this->ready(), false ) );
	}

	/* -------------------------------------------------------- and afterwards */

	public function test_a_later_release_is_never_gated(): void {
		/*
		 * The decision this issue turned on. A site that is already live has
		 * been through the gate; holding a bug fix behind an unticked box
		 * months later is how a gate becomes something people route around.
		 */
		$this->assertFalse( LaunchGate::refuses( $this->outstanding(), true ) );
	}

	public function test_a_later_release_is_not_gated_even_with_everything_outstanding(): void {
		$this->assertFalse(
			LaunchGate::refuses(
				array(
					'launch_ready' => false,
					'blocking'     => array( array( 'id' => 'obs_all', 'title' => 'Everything' ) ),
				),
				true
			)
		);
	}

	/* ----------------------------------------------------- a site with none */

	public function test_a_site_with_no_checklist_is_not_held_up(): void {
		/*
		 * A site nobody has given a checklist to is not a site failing its
		 * onboarding — it is one that never started. Refusing its first release
		 * would block every site that predates the checklist, and there is
		 * nothing anybody could do about it from here.
		 *
		 * This is the one place where "not ready" and "nothing to be ready
		 * about" are deliberately answered differently, and Progress reports
		 * both as launch_ready false.
		 */
		$this->assertFalse( LaunchGate::refuses( array( 'launch_ready' => false, 'blocking' => array() ), false ) );
	}

	/* -------------------------------------------------------- what it says */

	public function test_the_refusal_names_what_is_outstanding(): void {
		/*
		 * "Onboarding is not finished" sends somebody looking. Naming the steps
		 * sends them to the right place — and this is the whole reason Progress
		 * lists them rather than counting them.
		 */
		$unmet = LaunchGate::unmet( $this->outstanding() );

		$this->assertCount( 2, $unmet );
		$this->assertSame( 'Delegate the domain and DNS', $unmet[0]['title'] );
		$this->assertSame( LaunchGate::REQUIREMENT, $unmet[0]['requirement'] );
	}

	public function test_nothing_outstanding_names_nothing(): void {
		$this->assertSame( array(), LaunchGate::unmet( $this->ready() ) );
	}

	public function test_each_named_step_carries_its_id_so_a_screen_can_link_to_it(): void {
		$unmet = LaunchGate::unmet( $this->outstanding() );

		$this->assertSame( 'obs_dns', $unmet[0]['step_id'] );
	}
}
