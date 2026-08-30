<?php
/**
 * The client's own view of their checklist.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Client\Checklist;
use PHPUnit\Framework\TestCase;

/**
 * #162. A client can see their checklist, and can tell whose turn each step is.
 *
 * The distinction this must never lose is the one every other client read
 * carries: "you have no checklist" and "we cannot see your checklist" are
 * different sentences, and a screen that answers both with an empty list tells
 * a client their onboarding vanished. `ok` means the studio answered.
 */
final class ClientChecklistTest extends TestCase {

	/**
	 * A step, as the studio sends one.
	 *
	 * @param array<string, mixed> $overrides Anything to change.
	 * @return array<string, mixed>
	 */
	private function step( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                    => 'obs_one',
				'section'               => 'foundations',
				'title'                 => 'Delegate the domain and DNS',
				'status'                => 'not-started',
				'owner_side'            => 'client',
				'launch_critical'       => 1,
				'allows_not_applicable' => 0,
				'position'              => 10,
				'overdue'               => false,
			),
			$overrides
		);
	}

	/* ------------------------------------------------------------ whose turn */

	public function test_a_client_owned_step_is_theirs_to_do(): void {
		$this->assertTrue( Checklist::is_theirs( $this->step() ) );
	}

	public function test_a_step_we_own_is_not_theirs(): void {
		$this->assertFalse( Checklist::is_theirs( $this->step( array( 'owner_side' => 'internal' ) ) ) );
	}

	public function test_a_step_already_handed_over_is_no_longer_theirs_to_do(): void {
		/*
		 * Submitted, approved and not-applicable are all "nothing for you to do
		 * here". Leaving a submitted step looking actionable is how somebody
		 * answers the same question twice and wonders why nothing changed.
		 */
		foreach ( array( 'submitted', 'approved', 'not-applicable' ) as $status ) {
			$this->assertFalse(
				Checklist::is_theirs( $this->step( array( 'status' => $status ) ) ),
				$status . ' must not read as outstanding'
			);
		}
	}

	public function test_a_returned_step_is_theirs_again(): void {
		// The whole point of returning one with feedback (ONB-2).
		$this->assertTrue( Checklist::is_theirs( $this->step( array( 'status' => 'returned' ) ) ) );
	}

	/* -------------------------------------------------------------- grouping */

	public function test_steps_are_grouped_into_the_three_sections_in_order(): void {
		$grouped = Checklist::sections(
			array(
				$this->step( array( 'id' => 'obs_c', 'section' => 'launch' ) ),
				$this->step( array( 'id' => 'obs_a', 'section' => 'foundations' ) ),
				$this->step( array( 'id' => 'obs_b', 'section' => 'build-reviews' ) ),
			)
		);

		$this->assertSame( array( 'foundations', 'build-reviews', 'launch' ), array_keys( $grouped ) );
	}

	public function test_a_section_with_nothing_in_it_is_left_out(): void {
		/*
		 * An empty heading reads as work that is missing rather than work that
		 * does not apply to this client.
		 */
		$grouped = Checklist::sections( array( $this->step( array( 'section' => 'foundations' ) ) ) );

		$this->assertSame( array( 'foundations' ), array_keys( $grouped ) );
	}

	public function test_a_step_in_no_known_section_is_still_shown(): void {
		/*
		 * A template edited on the studio could name a section this artifact
		 * has not heard of. Dropping the step would hide work; showing it under
		 * its own heading is the lesser of the two.
		 */
		$grouped = Checklist::sections( array( $this->step( array( 'section' => 'something-new' ) ) ) );

		$this->assertArrayHasKey( 'something-new', $grouped );
	}

	public function test_steps_keep_the_order_the_studio_sent_them_in(): void {
		$grouped = Checklist::sections(
			array(
				$this->step( array( 'id' => 'obs_second', 'position' => 20 ) ),
				$this->step( array( 'id' => 'obs_first', 'position' => 10 ) ),
			)
		);

		// Position is the studio's to decide (#161) and this artifact does not
		// re-sort it — a client seeing a different order from the studio is a
		// support call about a checklist that "changed".
		$this->assertSame( 'obs_second', $grouped['foundations'][0]['id'] );
	}

	/* -------------------------------------------------------- what to do next */

	public function test_the_next_thing_is_the_first_outstanding_step_of_theirs(): void {
		$next = Checklist::next_of(
			array(
				$this->step( array( 'id' => 'obs_done', 'status' => 'approved' ) ),
				$this->step( array( 'id' => 'obs_ours', 'owner_side' => 'internal' ) ),
				$this->step( array( 'id' => 'obs_theirs' ) ),
				$this->step( array( 'id' => 'obs_later' ) ),
			)
		);

		$this->assertSame( 'obs_theirs', $next['id'] );
	}

	public function test_nothing_outstanding_means_nothing_to_point_at(): void {
		$this->assertSame(
			array(),
			Checklist::next_of( array( $this->step( array( 'status' => 'approved' ) ) ) )
		);
	}

	public function test_an_overdue_step_of_theirs_comes_first(): void {
		/*
		 * Position decides the order of the list; lateness decides what the
		 * screen points at. A client with one late step and nine on time should
		 * be sent to the late one.
		 */
		$next = Checklist::next_of(
			array(
				$this->step( array( 'id' => 'obs_ontime', 'position' => 10 ) ),
				$this->step( array( 'id' => 'obs_late', 'position' => 40, 'overdue' => true ) ),
			)
		);

		$this->assertSame( 'obs_late', $next['id'] );
	}
}
