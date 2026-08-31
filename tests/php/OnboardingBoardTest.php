<?php
/**
 * Every client's launch readiness in one view.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Onboarding\Board;
use Blueworx\Forge\Onboarding\Statuses;
use Blueworx\Forge\Onboarding\TemplateSteps;
use PHPUnit\Framework\TestCase;

/**
 * #165. The cross-client onboarding board.
 *
 * Two rules run through everything below, and they are the two the screen would
 * be worthless without.
 *
 * **A filter narrows what is shown, never what is counted.** Filtering to
 * overdue steps must not move a client from 60% to 100% because the four steps
 * they have finished are no longer on screen. Every figure on a row is worked
 * out from that site's whole checklist; the list of steps under it is the part
 * that narrows. Getting this the other way round would produce a board that
 * says something different about the same client depending on what somebody
 * last clicked.
 *
 * **A filter is a closed list, in its name and in its values.** Anything
 * unrecognised is dropped rather than applied, so a mistyped filter shows the
 * unfiltered board rather than silently widening one.
 */
final class OnboardingBoardTest extends TestCase {

	private const TODAY = '2026-03-10';

	/**
	 * One step, as a site holds it.
	 *
	 * @param string               $status    Where it is.
	 * @param array<string, mixed> $overrides Anything else.
	 * @return array<string, mixed>
	 */
	private function step( string $status, array $overrides = array() ): array {
		return array_merge(
			array(
				'id'              => 'obs_' . $status,
				'title'           => ucfirst( $status ),
				'status'          => $status,
				'optional'        => 0,
				'launch_critical' => 0,
				'owner_side'      => TemplateSteps::CLIENT,
				'owner_id'        => '',
				'due_on'          => '',
			),
			$overrides
		);
	}

	/**
	 * The identifying half of a row: who it is about.
	 *
	 * @param array<string, mixed> $overrides Anything else.
	 * @return array<string, mixed>
	 */
	private function site( array $overrides = array() ): array {
		return array_merge(
			array(
				'client_id'        => 'cli_one',
				'client_name'      => 'Acme',
				'client_site_id'   => 'cst_one',
				'site_name'        => 'acme.example',
				'site_url'         => 'https://acme.example',
				'template_id'      => 'obt_one',
				'template_name'    => 'Standard onboarding',
				'template_version' => 1,
				'contact_id'       => 'usr_poc',
				'contact_name'     => 'Dana',
				'assigned_at'      => 1740000000,
			),
			$overrides
		);
	}

	/* ------------------------------------------------------------ the row */

	public function test_a_row_says_how_far_through_the_site_is(): void {
		$row = Board::row(
			$this->site(),
			array(
				$this->step( Statuses::APPROVED ),
				$this->step( Statuses::NOT_STARTED, array( 'id' => 'obs_b' ) ),
			),
			array(),
			self::TODAY
		);

		$this->assertSame( 'Acme', $row['client_name'] );
		$this->assertSame( 2, $row['required'] );
		$this->assertSame( 1, $row['approved'] );
		$this->assertSame( 50.0, $row['completion'] );
	}

	public function test_a_row_names_what_is_holding_the_launch(): void {
		$row = Board::row(
			$this->site(),
			array(
				$this->step( Statuses::APPROVED, array( 'launch_critical' => 1 ) ),
				$this->step(
					Statuses::SUBMITTED,
					array(
						'id'              => 'obs_dns',
						'title'           => 'DNS delegated',
						'launch_critical' => 1,
					)
				),
			),
			array(),
			self::TODAY
		);

		$this->assertFalse( $row['launch_ready'] );
		$this->assertSame( array( array( 'id' => 'obs_dns', 'title' => 'DNS delegated' ) ), $row['blocking'] );
	}

	public function test_a_row_counts_what_somebody_has_to_do_something_about(): void {
		/*
		 * Three counts rather than one, because they go to three different
		 * people. Waiting on us is the studio's queue; overdue is a
		 * conversation with the client; blocked is somebody else entirely.
		 */
		$row = Board::row(
			$this->site(),
			array(
				$this->step( Statuses::SUBMITTED, array( 'id' => 'obs_a' ) ),
				$this->step( Statuses::SUBMITTED, array( 'id' => 'obs_b' ) ),
				$this->step( Statuses::BLOCKED, array( 'id' => 'obs_c' ) ),
				$this->step( Statuses::IN_PROGRESS, array( 'id' => 'obs_d', 'due_on' => '2026-03-01' ) ),
				$this->step( Statuses::APPROVED, array( 'id' => 'obs_e', 'due_on' => '2026-01-01' ) ),
			),
			array(),
			self::TODAY
		);

		$this->assertSame( 2, $row['awaiting_review'] );
		$this->assertSame( 1, $row['blocked'] );

		// One overdue, not two: finished work is never late (Statuses).
		$this->assertSame( 1, $row['overdue'] );
	}

	public function test_a_row_says_what_is_due_next(): void {
		$row = Board::row(
			$this->site(),
			array(
				$this->step( Statuses::APPROVED, array( 'id' => 'obs_done', 'due_on' => '2026-02-01' ) ),
				$this->step( Statuses::NOT_STARTED, array( 'id' => 'obs_b', 'due_on' => '2026-04-01' ) ),
				$this->step( Statuses::IN_PROGRESS, array( 'id' => 'obs_c', 'due_on' => '2026-03-20' ) ),
			),
			array(),
			self::TODAY
		);

		// The soonest date anybody is still waiting on. The approved step's
		// earlier date is not a date anybody is waiting for.
		$this->assertSame( '2026-03-20', $row['next_due'] );
	}

	public function test_a_site_nobody_has_dated_has_nothing_due_next(): void {
		$row = Board::row( $this->site(), array( $this->step( Statuses::NOT_STARTED ) ), array(), self::TODAY );

		$this->assertSame( '', $row['next_due'] );
	}

	public function test_every_step_carries_whether_it_is_late(): void {
		$row = Board::row(
			$this->site(),
			array( $this->step( Statuses::IN_PROGRESS, array( 'due_on' => '2026-03-01' ) ) ),
			array(),
			self::TODAY
		);

		$this->assertTrue( $row['steps'][0]['overdue'] );
	}

	/* -------------------------------------------- filtering narrows the list */

	public function test_a_step_filter_narrows_the_steps_shown(): void {
		$row = Board::row(
			$this->site(),
			array(
				$this->step( Statuses::SUBMITTED, array( 'id' => 'obs_waiting' ) ),
				$this->step( Statuses::APPROVED, array( 'id' => 'obs_done' ) ),
			),
			array( 'status' => array( Statuses::SUBMITTED ) ),
			self::TODAY
		);

		$this->assertCount( 1, $row['steps'] );
		$this->assertSame( 'obs_waiting', $row['steps'][0]['id'] );
	}

	public function test_a_step_filter_does_not_move_the_figures(): void {
		/*
		 * The rule this whole file is built around. Filter to the one thing
		 * outstanding and the client is still half done, not nought per cent.
		 */
		$steps = array(
			$this->step( Statuses::APPROVED, array( 'id' => 'obs_done' ) ),
			$this->step( Statuses::SUBMITTED, array( 'id' => 'obs_waiting' ) ),
		);

		$unfiltered = Board::row( $this->site(), $steps, array(), self::TODAY );
		$filtered   = Board::row( $this->site(), $steps, array( 'status' => array( Statuses::SUBMITTED ) ), self::TODAY );

		$this->assertSame( $unfiltered['completion'], $filtered['completion'] );
		$this->assertSame( $unfiltered['approved'], $filtered['approved'] );
		$this->assertSame( $unfiltered['required'], $filtered['required'] );
		$this->assertSame( $unfiltered['launch_ready'], $filtered['launch_ready'] );
	}

	public function test_overdue_is_a_filter_of_its_own(): void {
		$row = Board::row(
			$this->site(),
			array(
				$this->step( Statuses::IN_PROGRESS, array( 'id' => 'obs_late', 'due_on' => '2026-03-01' ) ),
				$this->step( Statuses::IN_PROGRESS, array( 'id' => 'obs_soon', 'due_on' => '2026-04-01' ) ),
			),
			array( 'overdue' => 'yes' ),
			self::TODAY
		);

		$this->assertCount( 1, $row['steps'] );
		$this->assertSame( 'obs_late', $row['steps'][0]['id'] );
	}

	public function test_blocked_is_a_filter_of_its_own(): void {
		$row = Board::row(
			$this->site(),
			array(
				$this->step( Statuses::BLOCKED, array( 'id' => 'obs_stuck' ) ),
				$this->step( Statuses::IN_PROGRESS, array( 'id' => 'obs_going' ) ),
			),
			array( 'blocked' => 'yes' ),
			self::TODAY
		);

		$this->assertCount( 1, $row['steps'] );
		$this->assertSame( 'obs_stuck', $row['steps'][0]['id'] );
	}

	public function test_who_a_step_belongs_to_is_a_filter(): void {
		$row = Board::row(
			$this->site(),
			array(
				$this->step( Statuses::IN_PROGRESS, array( 'id' => 'obs_ours', 'owner_side' => TemplateSteps::INTERNAL ) ),
				$this->step( Statuses::IN_PROGRESS, array( 'id' => 'obs_theirs' ) ),
			),
			array( 'owner_side' => array( TemplateSteps::INTERNAL ) ),
			self::TODAY
		);

		$this->assertCount( 1, $row['steps'] );
		$this->assertSame( 'obs_ours', $row['steps'][0]['id'] );
	}

	public function test_a_named_owner_is_a_filter(): void {
		$row = Board::row(
			$this->site(),
			array(
				$this->step( Statuses::IN_PROGRESS, array( 'id' => 'obs_dana', 'owner_id' => 'usr_dana' ) ),
				$this->step( Statuses::IN_PROGRESS, array( 'id' => 'obs_nobody' ) ),
			),
			array( 'owner_id' => array( 'usr_dana' ) ),
			self::TODAY
		);

		$this->assertCount( 1, $row['steps'] );
		$this->assertSame( 'obs_dana', $row['steps'][0]['id'] );
	}

	public function test_two_step_filters_are_an_and(): void {
		$row = Board::row(
			$this->site(),
			array(
				$this->step( Statuses::SUBMITTED, array( 'id' => 'obs_both', 'due_on' => '2026-03-01' ) ),
				$this->step( Statuses::SUBMITTED, array( 'id' => 'obs_ontime', 'due_on' => '2026-04-01' ) ),
				$this->step( Statuses::IN_PROGRESS, array( 'id' => 'obs_late', 'due_on' => '2026-03-01' ) ),
			),
			array(
				'status'  => array( Statuses::SUBMITTED ),
				'overdue' => 'yes',
			),
			self::TODAY
		);

		$this->assertCount( 1, $row['steps'] );
		$this->assertSame( 'obs_both', $row['steps'][0]['id'] );
	}

	/* ------------------------------------------- filtering narrows the board */

	/**
	 * Two sites under two clients, each with one step.
	 *
	 * @param array<string, mixed> $filters The filter set.
	 * @return array<int, array<string, mixed>>
	 */
	private function twoSites( array $filters ): array {
		$rows = array(
			Board::row(
				$this->site(),
				array( $this->step( Statuses::SUBMITTED, array( 'id' => 'obs_acme' ) ) ),
				$filters,
				self::TODAY
			),
			Board::row(
				$this->site(
					array(
						'client_id'      => 'cli_two',
						'client_name'    => 'Bolt',
						'client_site_id' => 'cst_two',
						'template_id'    => 'obt_two',
						'contact_id'     => 'usr_other',
					)
				),
				array( $this->step( Statuses::APPROVED, array( 'id' => 'obs_bolt', 'launch_critical' => 1 ) ) ),
				$filters,
				self::TODAY
			),
		);

		return Board::keep( $rows, $filters );
	}

	public function test_no_filters_shows_every_site(): void {
		$this->assertCount( 2, $this->twoSites( array() ) );
	}

	public function test_a_client_filter_narrows_the_board(): void {
		$kept = $this->twoSites( array( 'client_id' => array( 'cli_two' ) ) );

		$this->assertCount( 1, $kept );
		$this->assertSame( 'Bolt', $kept[0]['client_name'] );
	}

	public function test_a_template_filter_narrows_the_board(): void {
		$kept = $this->twoSites( array( 'template_id' => array( 'obt_one' ) ) );

		$this->assertCount( 1, $kept );
		$this->assertSame( 'Acme', $kept[0]['client_name'] );
	}

	public function test_a_point_of_contact_filter_narrows_the_board(): void {
		$kept = $this->twoSites( array( 'contact_id' => array( 'usr_poc' ) ) );

		$this->assertCount( 1, $kept );
		$this->assertSame( 'Acme', $kept[0]['client_name'] );
	}

	public function test_launch_readiness_narrows_the_board(): void {
		$ready = $this->twoSites( array( 'launch' => 'ready' ) );

		$this->assertCount( 1, $ready );
		$this->assertSame( 'Bolt', $ready[0]['client_name'] );

		$waiting = $this->twoSites( array( 'launch' => 'not-ready' ) );

		$this->assertCount( 1, $waiting );
		$this->assertSame( 'Acme', $waiting[0]['client_name'] );
	}

	public function test_a_site_with_nothing_matching_drops_off_the_board(): void {
		/*
		 * A step filter is somebody asking "who has one of these?". A site with
		 * none of them is not a row with an empty list under it — it is not an
		 * answer to the question at all.
		 */
		$kept = $this->twoSites( array( 'status' => array( Statuses::SUBMITTED ) ) );

		$this->assertCount( 1, $kept );
		$this->assertSame( 'Acme', $kept[0]['client_name'] );
	}

	public function test_a_site_with_no_steps_still_appears_when_nothing_is_filtered(): void {
		/*
		 * A checklist assigned this morning and untouched is exactly the row
		 * somebody needs to see. Dropping it because nothing has happened yet
		 * would hide the clients furthest from launching.
		 */
		$row = Board::row( $this->site(), array(), array(), self::TODAY );

		$this->assertCount( 1, Board::keep( array( $row ), array() ) );
		$this->assertFalse( $row['launch_ready'] );
	}

	/* ---------------------------------------------------------- the totals */

	public function test_the_board_totals_what_is_on_it(): void {
		$totals = Board::totals( $this->twoSites( array() ) );

		$this->assertSame( 2, $totals['sites'] );
		$this->assertSame( 1, $totals['launch_ready'] );
		$this->assertSame( 1, $totals['awaiting_review'] );
		$this->assertSame( 0, $totals['overdue'] );
		$this->assertSame( 0, $totals['blocked'] );
	}

	/* -------------------------------------------------------- the filter set */

	public function test_an_invented_filter_is_dropped(): void {
		$this->assertSame( array(), Board::sanitise( array( 'colour' => 'blue' ) ) );
	}

	public function test_an_invented_status_is_dropped(): void {
		$this->assertSame( array(), Board::sanitise( array( 'status' => array( 'nearly' ) ) ) );
	}

	public function test_a_real_status_survives_alongside_an_invented_one(): void {
		$this->assertSame(
			array( 'status' => array( Statuses::SUBMITTED ) ),
			Board::sanitise( array( 'status' => array( Statuses::SUBMITTED, 'nearly' ) ) )
		);
	}

	public function test_a_comma_separated_list_is_read_as_a_list(): void {
		// It arrives on a query string, where that is how a person writes a set.
		$this->assertSame(
			array( 'status' => array( Statuses::SUBMITTED, Statuses::BLOCKED ) ),
			Board::sanitise( array( 'status' => Statuses::SUBMITTED . ',' . Statuses::BLOCKED ) )
		);
	}

	public function test_an_id_filter_is_taken_as_given(): void {
		/*
		 * Not checked against a list of clients. Whether a client id means
		 * anything to this person is the reach's question and has already been
		 * answered; a second, weaker copy of that check here would be the one
		 * somebody trusted.
		 */
		$this->assertSame(
			array( 'client_id' => array( 'cli_whatever' ) ),
			Board::sanitise( array( 'client_id' => 'cli_whatever' ) )
		);
	}

	public function test_an_empty_id_filter_is_no_filter(): void {
		$this->assertSame( array(), Board::sanitise( array( 'client_id' => '' ) ) );
	}

	public function test_a_flag_only_counts_when_it_says_yes(): void {
		$this->assertSame( array( 'overdue' => 'yes' ), Board::sanitise( array( 'overdue' => 'yes' ) ) );
		$this->assertSame( array(), Board::sanitise( array( 'overdue' => 'no' ) ) );
		$this->assertSame( array(), Board::sanitise( array( 'overdue' => '' ) ) );
	}

	public function test_launch_readiness_takes_one_of_two_answers(): void {
		$this->assertSame( array( 'launch' => 'ready' ), Board::sanitise( array( 'launch' => 'ready' ) ) );
		$this->assertSame( array( 'launch' => 'not-ready' ), Board::sanitise( array( 'launch' => 'not-ready' ) ) );
		$this->assertSame( array(), Board::sanitise( array( 'launch' => 'maybe' ) ) );
	}

	public function test_an_invented_owner_side_is_dropped(): void {
		$this->assertSame( array(), Board::sanitise( array( 'owner_side' => 'everybody' ) ) );
	}
}
