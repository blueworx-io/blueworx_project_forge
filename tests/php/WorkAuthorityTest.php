<?php
/**
 * Reopening, overriding, and who holds which seat.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\Capabilities;
use Blueworx\Forge\Work\Override;
use Blueworx\Forge\Work\Reopen;
use Blueworx\Forge\Work\Stages;
use Blueworx\Forge\Work\Transitions;
use Blueworx\Forge\Work\Types;
use Blueworx\Forge\Work\Validate;
use PHPUnit\Framework\TestCase;

/**
 * #112, #113 and #114 as rules. The routes that enforce them are proved against
 * a real WordPress in the workflow suite; these are the rules themselves.
 */
final class WorkAuthorityTest extends TestCase {

	/**
	 * An item.
	 *
	 * @param string               $stage Where it is.
	 * @param array<string, mixed> $over  Anything else.
	 * @return array<string, mixed>
	 */
	private function item( string $stage, array $over = array() ): array {
		return array_merge(
			array(
				'id'               => 'wrk_1',
				'stage'            => $stage,
				'work_type'        => Types::FEATURE,
				'cycle'            => 1,
				'archived'         => false,
				'terminal_outcome' => '',
			),
			$over
		);
	}

	/**
	 * #112. The two moves that belong to a person rather than to a rank ask for
	 * a capability of their own; the other nine ask for ordinary permission.
	 */
	public function test_review_and_release_ask_for_their_own_capability(): void {
		$this->assertSame(
			Capabilities::APPROVE_REVIEW,
			Transitions::capability_for( 'in-review', 'completed' )
		);
		$this->assertSame(
			Capabilities::CONFIRM_RELEASE,
			Transitions::capability_for( 'completed', 'released' )
		);

		foreach ( array( array( 'future-idea', 'triage' ), array( 'up-next', 'in-development' ) ) as $move ) {
			$this->assertSame( Capabilities::MOVE_FORWARD, Transitions::capability_for( $move[0], $move[1] ) );
		}
	}

	/**
	 * A reviewer is somebody other than the person who did the work, unless the
	 * caller says the Principal grant applies (AUTH-3).
	 */
	public function test_the_reviewer_is_not_the_primary_user(): void {
		$same = Validate::item(
			array(
				'primary_user_id' => 'usr_a',
				'reviewer_id'     => 'usr_a',
			),
			true
		);

		$this->assertArrayHasKey( 'reviewer_id', $same['errors'] );

		$different = Validate::item(
			array(
				'primary_user_id' => 'usr_a',
				'reviewer_id'     => 'usr_b',
			),
			true
		);

		$this->assertSame( array(), $different['errors'] );

		$principal = Validate::item(
			array(
				'primary_user_id'       => 'usr_a',
				'reviewer_id'           => 'usr_a',
				'self_review_permitted' => true,
			),
			true
		);

		$this->assertSame( array(), $principal['errors'] );
	}

	/**
	 * A seat holds a person or nobody, and nothing else.
	 */
	public function test_a_seat_holds_a_person_or_nobody(): void {
		$nonsense = Validate::item( array( 'deliverer_id' => 'not-an-id' ), true );
		$this->assertArrayHasKey( 'deliverer_id', $nonsense['errors'] );

		// Emptying a seat is a real answer: somebody leaves.
		$cleared = Validate::item( array( 'deliverer_id' => '' ), true );
		$this->assertSame( array(), $cleared['errors'] );
		$this->assertSame( '', $cleared['values']['deliverer_id'] );
	}

	/**
	 * #113. Finished work reopens, and only from where it is finished.
	 */
	public function test_only_finished_work_reopens(): void {
		$this->assertTrue( Reopen::possible( $this->item( 'completed' ) ) );
		$this->assertTrue( Reopen::possible( $this->item( 'released' ) ) );
		$this->assertFalse( Reopen::possible( $this->item( 'in-development' ) ) );
		$this->assertFalse( Reopen::possible( $this->item( 'triage' ) ) );
	}

	/**
	 * It reopens to somewhere work is actually done. Reopening to Triage would
	 * be asking whether to do a thing that has already been delivered.
	 */
	public function test_it_reopens_into_the_work_and_not_the_deciding(): void {
		$this->assertSame(
			array( 'documentation-period', 'in-development' ),
			Reopen::targets( $this->item( 'released' ) )
		);
		$this->assertFalse( Reopen::allowed( $this->item( 'released' ), 'triage' ) );
		$this->assertTrue( Reopen::allowed( $this->item( 'released' ), 'in-development' ) );
	}

	/**
	 * WF-4: a reopen is a new cycle, so the earlier one is preserved rather
	 * than continued.
	 */
	public function test_reopening_starts_a_new_cycle(): void {
		$this->assertSame( 2, Reopen::next_cycle( $this->item( 'released' ) ) );
		$this->assertSame( 4, Reopen::next_cycle( $this->item( 'released', array( 'cycle' => 3 ) ) ) );
	}

	/**
	 * Work that ended at an outcome is not reopened — it is a decision, not an
	 * unfinished job — and neither is archived work.
	 */
	public function test_ended_and_archived_work_does_not_reopen(): void {
		$this->assertFalse( Reopen::possible( $this->item( 'completed', array( 'terminal_outcome' => 'cancelled' ) ) ) );
		$this->assertFalse( Reopen::possible( $this->item( 'released', array( 'archived' => true ) ) ) );
	}

	/**
	 * #114. The override goes anywhere — that is the whole of it — including
	 * backwards, forwards, and past every gate in between.
	 */
	public function test_the_override_goes_anywhere(): void {
		$item = $this->item( 'triage' );

		$this->assertTrue( Override::allowed( $item, 'released' ) );
		$this->assertTrue( Override::allowed( $this->item( 'released' ), 'future-idea' ) );
		$this->assertTrue( Override::allowed( $item, Stages::BLOCKED ) );
	}

	/**
	 * Two things it still cannot do. Bug Tracking exists only for bugs (#110,
	 * WF-1), and that is a property of the stage rather than a rule about who
	 * may move work — an override that could put a Feature there would leave it
	 * in a stage whose gate assumes it is a bug.
	 */
	public function test_the_override_cannot_put_work_where_its_type_may_never_go(): void {
		$this->assertFalse( Override::allowed( $this->item( 'triage' ), Stages::BUG_TRACKING ) );
		$this->assertTrue(
			Override::allowed( $this->item( 'triage', array( 'work_type' => Types::BUG ) ), Stages::BUG_TRACKING )
		);

		// And it does not move work that is out of the way. Bring it back
		// first, so un-archiving is its own decision with its own record.
		$this->assertFalse( Override::allowed( $this->item( 'released', array( 'archived' => true ) ), 'triage' ) );

		// Nor is "move it to where it already is" an override of anything.
		$this->assertFalse( Override::allowed( $this->item( 'triage' ), 'triage' ) );
	}

	/**
	 * The mark is permanent and carries its reason. An item that was overridden
	 * once has a hole in its history for ever, and a later reader needs to know.
	 */
	public function test_the_override_marks_the_item_with_its_reason(): void {
		$mark = Override::mark( '  Put in the wrong stage during the migration.  ' );

		$this->assertSame( 1, $mark['override_used'] );
		$this->assertSame( 'Put in the wrong stage during the migration.', $mark['override_reason'] );
		$this->assertLessThanOrEqual( Override::MAX_REASON, mb_strlen( $mark['override_reason'] ) );
	}

	/**
	 * A reason too long for its column is cut rather than refused. The reason
	 * is a note to a future reader, not an identifier.
	 */
	public function test_a_long_reason_is_shortened_rather_than_lost(): void {
		$mark = Override::mark( str_repeat( 'x', Override::MAX_REASON + 50 ) );

		$this->assertSame( Override::MAX_REASON, mb_strlen( $mark['override_reason'] ) );
	}
}
