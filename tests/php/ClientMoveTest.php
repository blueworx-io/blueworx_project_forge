<?php
/**
 * Tests for when a task's client may change.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\ClientMove;
use PHPUnit\Framework\TestCase;

/**
 * #390. A task's client can change until work starts, and only when the task
 * stands on its own.
 */
final class ClientMoveTest extends TestCase {

	/**
	 * An item at a stage.
	 *
	 * @param string               $stage The stage.
	 * @param array<string, mixed> $also  Anything else on it.
	 * @return array<string, mixed>
	 */
	private function item( string $stage, array $also = array() ): array {
		return array_merge(
			array(
				'stage'            => $stage,
				'prior_stage'      => '',
				'terminal_outcome' => '',
			),
			$also
		);
	}

	/**
	 * Before In Development, a task on its own may move.
	 */
	public function test_work_that_has_not_started_may_move(): void {
		foreach ( array( 'future-idea', 'triage', 'documentation-period', 'technical-audit', 'design-process', 'up-next' ) as $stage ) {
			$this->assertNull( ClientMove::refusal( $this->item( $stage ), false ), $stage );
		}
	}

	/**
	 * From In Development on the hours are counted against the client.
	 */
	public function test_work_that_has_started_may_not(): void {
		foreach ( array( 'in-development', 'in-review', 'completed', 'released' ) as $stage ) {
			$refusal = ClientMove::refusal( $this->item( $stage ), false );

			$this->assertSame( ClientMove::STARTED, $refusal['code'] ?? '', $stage );
			$this->assertStringContainsString( 'Hours are already counted against this client', $refusal['message'] );
		}
	}

	/**
	 * Blocked is read through to where it was blocked from.
	 */
	public function test_blocked_work_is_judged_by_where_it_came_from(): void {
		$this->assertNull( ClientMove::refusal( $this->item( 'blocked', array( 'prior_stage' => 'up-next' ) ), false ) );
		$this->assertSame( ClientMove::STARTED, ClientMove::refusal( $this->item( 'blocked', array( 'prior_stage' => 'in-development' ) ), false )['code'] );
	}

	/**
	 * Work that has ended stays with the client it ended under.
	 */
	public function test_ended_work_may_not_move(): void {
		$refusal = ClientMove::refusal( $this->item( 'triage', array( 'terminal_outcome' => 'cancelled' ) ), false );

		$this->assertSame( ClientMove::ENDED, $refusal['code'] ?? '' );
	}

	/**
	 * Linked work moves on its own first.
	 */
	public function test_linked_work_may_not_move(): void {
		$refusal = ClientMove::refusal( $this->item( 'triage' ), true );

		$this->assertSame( ClientMove::LINKED, $refusal['code'] ?? '' );
		$this->assertSame( 'Move it on its own first: it is linked to other work.', $refusal['message'] );
	}

	/**
	 * Work a client asked for, or has talked about, is theirs.
	 */
	public function test_work_the_client_is_part_of_may_not_move(): void {
		$this->assertSame( ClientMove::REQUESTED, ClientMove::refusal( $this->item( 'triage' ), false, true )['code'] ?? '' );
		$this->assertSame( ClientMove::CLIENT_SEEN, ClientMove::refusal( $this->item( 'triage' ), false, false, true )['code'] ?? '' );
	}

	/**
	 * Who was taken off, in seat order, each once.
	 */
	public function test_taken_off_lists_each_cleared_person_once(): void {
		$before = array(
			'primary_user_id'         => 'usr_a',
			'reviewer_id'             => 'usr_b',
			'deliverer_id'            => 'usr_a',
			'reviewer_substitute_id'  => '',
			'deliverer_substitute_id' => 'usr_c',
		);
		$after  = array(
			'primary_user_id'         => '',
			'reviewer_id'             => 'usr_b',
			'deliverer_id'            => '',
			'reviewer_substitute_id'  => '',
			'deliverer_substitute_id' => '',
		);

		$this->assertSame( array( 'usr_a', 'usr_c' ), ClientMove::taken_off( $before, $after ) );
		$this->assertSame( array(), ClientMove::taken_off( $before, $before ) );
	}
}
