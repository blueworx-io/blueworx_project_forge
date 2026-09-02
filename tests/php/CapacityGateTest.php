<?php
/**
 * What the capacity check does to a gate.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\Gates;
use PHPUnit\Framework\TestCase;

/**
 * #141 and #142. The placeholder left by #105 becomes a real refusal, and the
 * gate keeps its promise to report everything at once rather than one thing at
 * a time.
 *
 * G-UP-NEXT is the gate on **leaving** Up Next for In Development, which is the
 * one crossing both issues describe: the hours and dates it checks are entered
 * while the item sits at Up Next, so there is nothing to weigh before it gets
 * there. #141 is the plan being real; #142 is that answer being worked out
 * afresh at the moment of the move rather than remembered from when the plan
 * was made.
 */
final class CapacityGateTest extends TestCase {

	/**
	 * An item with every field-satisfied Up Next requirement met.
	 *
	 * @param array<string, mixed> $overrides Anything to change.
	 * @return array<string, mixed>
	 */
	private function ready_item( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'              => 'wrk_one',
				'created_by'      => 1,
				'primary_user_id' => 'usr_a',
				'reviewer_id'     => 'usr_b',
				'deliverer_id'    => 'usr_c',
				'planned_start'   => '2026-09-07',
				'planned_due'     => '2026-09-11',
				'priority'        => 'high',
			),
			$overrides
		);
	}

	/**
	 * Records satisfying every by-record requirement of G-UP-NEXT.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function records(): array {
		return array(
			'G-UP-NEXT-4' => array( 'actor' => 3 ),
			'G-UP-NEXT-7' => array( 'actor' => 3 ),
		);
	}

	/**
	 * A capacity context in which one person is over-booked.
	 *
	 * @param string $reason A reason offered with the move, if any.
	 * @return array<string, mixed>
	 */
	private function no_room( string $reason = '' ): array {
		return array(
			'capacity' => array(
				'over'   => array(
					array(
						'user_id'   => 'usr_a',
						'week_from' => '2026-09-07',
						'week_to'   => '2026-09-13',
						'available' => 40.0,
						'committed' => 50.0,
						'excess'    => 10.0,
					),
				),
				'reason' => $reason,
			),
		);
	}

	/**
	 * A capacity context in which everybody has room.
	 *
	 * @return array<string, mixed>
	 */
	private function room(): array {
		return array(
			'capacity' => array(
				'over'   => array(),
				'reason' => '',
			),
		);
	}

	public function test_room_for_the_work_passes_the_check(): void {
		$result = Gates::evaluate( 'G-UP-NEXT', $this->ready_item(), $this->records(), $this->room() );

		$this->assertSame( array(), $result['unmet'] );
	}

	public function test_no_room_refuses_the_move(): void {
		$result = Gates::evaluate( 'G-UP-NEXT', $this->ready_item(), $this->records(), $this->no_room() );

		$this->assertSame( array( 'G-UP-NEXT-8' ), array_column( $result['unmet'], 'id' ) );
	}

	public function test_a_reason_permits_the_over_allocation(): void {
		// CAP-4: over-allocation does not hard block. It costs a reason.
		$result = Gates::evaluate(
			'G-UP-NEXT',
			$this->ready_item(),
			$this->records(),
			$this->no_room( 'Client has agreed the overtime.' )
		);

		$this->assertSame( array(), $result['unmet'] );
	}

	public function test_a_blank_reason_is_not_a_reason(): void {
		$result = Gates::evaluate( 'G-UP-NEXT', $this->ready_item(), $this->records(), $this->no_room( '   ' ) );

		$this->assertContains( 'G-UP-NEXT-8', array_column( $result['unmet'], 'id' ) );
	}

	public function test_capacity_is_reported_alongside_everything_else_missing(): void {
		/*
		 * #107. Somebody missing dates and over-booking a reviewer is told
		 * both, not told one, told the other, and refused twice.
		 */
		$result = Gates::evaluate(
			'G-UP-NEXT',
			$this->ready_item(
				array(
					'planned_start' => '',
					'planned_due'   => '',
				)
			),
			$this->records(),
			$this->no_room()
		);

		$ids = array_column( $result['unmet'], 'id' );

		$this->assertContains( 'G-UP-NEXT-5', $ids );
		$this->assertContains( 'G-UP-NEXT-8', $ids );
	}

	public function test_the_refusal_says_who_is_over_and_when(): void {
		$result = Gates::evaluate( 'G-UP-NEXT', $this->ready_item(), $this->records(), $this->no_room() );

		$this->assertSame( 'usr_a', $result['unmet'][0]['over'][0]['user_id'] );
		$this->assertSame( '2026-09-07', $result['unmet'][0]['over'][0]['week_from'] );
		$this->assertSame( 10.0, $result['unmet'][0]['over'][0]['excess'] );
	}

	public function test_the_answer_is_the_one_handed_in_not_one_remembered_on_the_item(): void {
		/*
		 * CAP-E4, and the whole of #142. A reason given when the plan was made
		 * is written onto the item, and it must not satisfy the check at the
		 * moment of the move — that answer was about a picture that has since
		 * changed. Only a reason offered with this move counts.
		 */
		$result = Gates::evaluate(
			'G-UP-NEXT',
			$this->ready_item(
				array(
					'capacity_override_used'   => 1,
					'capacity_override_reason' => 'Agreed weeks ago.',
				)
			),
			$this->records(),
			$this->no_room()
		);

		$this->assertContains( 'G-UP-NEXT-8', array_column( $result['unmet'], 'id' ) );
	}

	public function test_support_hours_is_a_question_this_gate_does_not_answer(): void {
		/*
		 * #150 made it real, and SupportHoursGateTest is where its rules are
		 * argued. What matters here is that the two checks stayed independent:
		 * a capacity context alone leaves the hours answer to whoever supplies
		 * one, rather than #141 quietly deciding it from the room.
		 */
		$result = Gates::evaluate( 'G-UP-NEXT', $this->ready_item(), $this->records(), $this->room() );
		$checks = array_column( $result['checks'], null, 'id' );

		$this->assertArrayHasKey( 'G-UP-NEXT-9', $checks );
		$this->assertSame( 'pass', $checks['G-UP-NEXT-9']['result'] );
	}

	public function test_both_results_are_still_always_reported(): void {
		$result = Gates::evaluate( 'G-UP-NEXT', $this->ready_item(), $this->records(), $this->no_room() );
		$checks = array_column( $result['checks'], null, 'id' );

		$this->assertSame( 'fail', $checks['G-UP-NEXT-8']['result'] );
		$this->assertArrayHasKey( 'G-UP-NEXT-9', $checks );
	}
}
