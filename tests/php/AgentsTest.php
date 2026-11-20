<?php
/**
 * The agent as a member of staff.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Slack\Notify;
use Blueworx\Forge\Tenancy\Agents;
use Blueworx\Forge\Tenancy\PersonReach;
use PHPUnit\Framework\TestCase;

/**
 * #689. The agent holds seats, its hours are its own, and the deliverer looks
 * after the task while it is the agent's turn.
 */
final class AgentsTest extends TestCase {

	public function test_default_hours_are_twelve_on_weekdays_and_none_at_weekends(): void {
		$hours = Agents::default_hours();

		$this->assertCount( 7, $hours );

		foreach ( array( 'hours_mon', 'hours_tue', 'hours_wed', 'hours_thu', 'hours_fri' ) as $day ) {
			$this->assertSame( 12.0, $hours[ $day ], $day );
		}

		$this->assertSame( 0.0, $hours['hours_sat'] );
		$this->assertSame( 0.0, $hours['hours_sun'] );
	}

	// ---- Seats ---------------------------------------------------------

	public function test_agent_cannot_deliver(): void {
		$this->assertSame(
			array( 'deliverer_id' => 'The agent can\'t deliver work. Pick a person to deliver it.' ),
			Agents::seat_refusals( array( 'deliverer_id' => 'usr_a' ), array( 'usr_a' ) )
		);
	}

	public function test_agent_seat_needs_a_deliverer(): void {
		$copy = 'With the agent on this task, pick a person to deliver it. They look after it while the agent has it.';

		foreach ( array( 'primary_user_id', 'designer_id', 'reviewer_id' ) as $seat ) {
			$this->assertSame(
				array( 'deliverer_id' => $copy ),
				Agents::seat_refusals( array( $seat => 'usr_a', 'deliverer_id' => '' ), array( 'usr_a' ) ),
				$seat
			);
		}

		// No deliverer named at all is the same as an empty one.
		$this->assertSame( array( 'deliverer_id' => $copy ), Agents::seat_refusals( array( 'primary_user_id' => 'usr_a' ), array( 'usr_a' ) ) );
	}

	public function test_agent_seat_with_a_deliverer_is_fine(): void {
		$this->assertSame( array(), Agents::seat_refusals( array( 'primary_user_id' => 'usr_a', 'deliverer_id' => 'usr_b' ), array( 'usr_a' ) ) );
	}

	public function test_the_agent_cannot_review_work_its_deliverer_did(): void {
		$item = array( 'primary_user_id' => 'usr_b', 'reviewer_id' => 'usr_a', 'deliverer_id' => 'usr_b' );

		$this->assertSame(
			array( 'reviewer_id' => 'With the agent reviewing, the person delivering it would approve their own work. Pick somebody else to deliver it, or to review it.' ),
			Agents::seat_refusals( $item, array( 'usr_a' ) )
		);
		// Principal may review their own work, so may approve it for the agent.
		$this->assertSame( array(), Agents::seat_refusals( $item, array( 'usr_a' ), true ) );
	}

	public function test_the_agent_is_refused_where_nobody_can_act_for_it(): void {
		$refused = Agents::refuse_in(
			array( 'primary_user_id' => 'usr_a', 'reviewer_id' => 'usr_x', 'assignees' => array( 'usr_y', 'usr_a' ) ),
			array( 'primary_user_id', 'reviewer_id', 'assignees' ),
			array( 'usr_a' ),
			'Not here.'
		);

		$this->assertSame( array( 'primary_user_id' => 'Not here.', 'assignees' => 'Not here.' ), $refused );
		$this->assertSame( array(), Agents::refuse_in( array( 'primary_user_id' => 'usr_x' ), array( 'primary_user_id' ), array( 'usr_a' ), 'Not here.' ) );
	}

	public function test_people_without_the_agent(): void {
		$people = array(
			array( 'id' => 'usr_x', 'is_agent' => false ),
			array( 'id' => 'usr_a', 'is_agent' => true ),
			array( 'id' => 'usr_y' ),
		);

		$this->assertSame( array( 'usr_x', 'usr_y' ), array_column( Agents::without( $people ), 'id' ) );
	}

	public function test_no_agent_no_refusal(): void {
		$this->assertSame( array(), Agents::seat_refusals( array( 'primary_user_id' => 'usr_x', 'deliverer_id' => '' ), array( 'usr_a' ) ) );
	}

	public function test_a_partial_edit_is_judged_on_the_task_as_it_would_be(): void {
		// Review Focus 2: only the primary seat is sent, the deliverer is already there.
		$item = array_merge(
			array( 'primary_user_id' => 'usr_x', 'deliverer_id' => 'usr_b' ),
			array( 'primary_user_id' => 'usr_a' )
		);

		$this->assertSame( array(), Agents::seat_refusals( $item, array( 'usr_a' ) ) );
	}

	// ---- Acting for the agent -----------------------------------------

	public function test_the_agent_reaches_every_site_without_a_membership(): void {
		// Review Focus 4: no membership on this client, and still seatable.
		$agent = array( 'id' => 'usr_a', 'status' => 'active', 'is_agent' => true );

		$this->assertTrue( PersonReach::reaches( $agent, array(), false, 'cli_a', 'cst_1', true ) );
		$this->assertFalse( PersonReach::reaches( array_merge( $agent, array( 'status' => 'inactive' ) ), array(), false, 'cli_a', 'cst_1', true ) );
	}

	public function test_the_deliverer_acts_for_the_agent_in_its_seat(): void {
		// Review Focus 1: the agent reviews, the deliverer can approve.
		$item = array( 'reviewer_id' => 'usr_a', 'primary_user_id' => 'usr_x', 'deliverer_id' => 'usr_b' );

		$this->assertTrue( Agents::acts_for( $item, 'reviewer_id', 'usr_b', array( 'usr_a' ) ) );
		$this->assertFalse( Agents::acts_for( $item, 'primary_user_id', 'usr_b', array( 'usr_a' ) ) );
		$this->assertFalse( Agents::acts_for( $item, 'reviewer_id', 'usr_x', array( 'usr_a' ) ) );
		$this->assertFalse( Agents::acts_for( $item, 'reviewer_id', '', array( 'usr_a' ) ) );
	}

	public function test_slack_never_tells_the_agent_it_was_given_a_seat(): void {
		// Review Focus 5.
		$changes = Notify::seat_changes(
			array(),
			array( 'primary_user_id' => 'usr_a', 'deliverer_id' => 'usr_b' ),
			array( 'usr_a' )
		);

		$this->assertSame( array( 'deliverer_id' => 'usr_b' ), $changes );
	}

	public function test_stand_in_hands_the_agent_seat_to_the_deliverer(): void {
		$this->assertSame( 'usr_b', Agents::stand_in( array( 'primary_user_id' => 'usr_a', 'deliverer_id' => 'usr_b' ), 'primary_user_id', array( 'usr_a' ) ) );
		$this->assertSame( 'usr_x', Agents::stand_in( array( 'primary_user_id' => 'usr_x', 'deliverer_id' => 'usr_b' ), 'primary_user_id', array( 'usr_a' ) ) );
		$this->assertSame( '', Agents::stand_in( array( 'primary_user_id' => '' ), 'primary_user_id', array( 'usr_a' ) ) );
	}
}
