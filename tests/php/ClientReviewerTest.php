<?php
/**
 * The client as the reviewer.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\PersonReach;
use Blueworx\Forge\Work\ClientReviewer;
use Blueworx\Forge\Work\RoleHours;
use Blueworx\Forge\Work\Validate;
use PHPUnit\Framework\TestCase;

/**
 * #391. "The client" can be the reviewer from Up Next on, and their review
 * time counts against nobody.
 */
final class ClientReviewerTest extends TestCase {

	// ---- Validation ----------------------------------------------------

	public function test_the_client_can_be_the_reviewer_from_up_next_on(): void {
		foreach ( array( 'up-next', 'in-development', 'in-review', 'completed', 'released' ) as $stage ) {
			$checked = Validate::item( array( 'reviewer_id' => 'client' ), true, $stage );

			$this->assertSame( array(), $checked['errors'], "refused at {$stage}" );
			$this->assertSame( 'client', $checked['values']['reviewer_id'] );
		}
	}

	public function test_not_before_up_next(): void {
		foreach ( array( 'future-idea', 'triage', 'bug-tracking', 'documentation-period', 'technical-audit', 'design-process', '' ) as $stage ) {
			$checked = Validate::item( array( 'reviewer_id' => 'client' ), true, $stage );

			$this->assertSame(
				'The client can be the reviewer from Up Next onwards.',
				$checked['errors']['reviewer_id'] ?? '',
				"allowed at '{$stage}'"
			);
		}
	}

	public function test_new_work_cannot_start_with_the_client_reviewing(): void {
		$checked = Validate::item(
			array(
				'title'       => 'A task',
				'level'       => 'feature',
				'work_type'   => 'feature',
				'reviewer_id' => 'client',
			),
			false
		);

		$this->assertArrayHasKey( 'reviewer_id', $checked['errors'] );
	}

	public function test_only_the_reviewer_seat_takes_the_client(): void {
		foreach ( array( 'primary_user_id', 'deliverer_id', 'reviewer_substitute_id', 'deliverer_substitute_id' ) as $seat ) {
			$checked = Validate::item( array( $seat => 'client' ), true, 'in-review' );

			$this->assertSame( 'That is not a person.', $checked['errors'][ $seat ] ?? '', "{$seat} took the client" );
		}
	}

	public function test_the_client_is_never_the_person_who_did_the_work(): void {
		$checked = Validate::item(
			array(
				'primary_user_id' => 'usr_abc',
				'reviewer_id'     => 'client',
			),
			true,
			'up-next'
		);

		$this->assertSame( array(), $checked['errors'] );
	}

	public function test_it_knows_the_client_when_it_sees_it(): void {
		$this->assertTrue( ClientReviewer::is( array( 'reviewer_id' => 'client' ) ) );
		$this->assertFalse( ClientReviewer::is( array( 'reviewer_id' => 'usr_client' ) ) );
		$this->assertFalse( ClientReviewer::is( array() ) );
	}

	public function test_a_blocked_item_is_judged_by_where_it_was(): void {
		$this->assertSame(
			'in-review',
			ClientReviewer::stage_of(
				array(
					'stage'       => 'blocked',
					'prior_stage' => 'in-review',
				)
			)
		);
		$this->assertSame( 'triage', ClientReviewer::stage_of( array( 'stage' => 'triage' ) ) );
	}

	// ---- Hours ---------------------------------------------------------

	public function test_the_review_share_is_not_seeded_for_the_client(): void {
		$seeded = RoleHours::seed(
			array(
				'hours_primary' => 10.0,
				'reviewer_id'   => 'client',
			)
		);

		$this->assertArrayNotHasKey( 'hours_review', $seeded );
		$this->assertSame( 1.0, $seeded['hours_delivery'], 'the deliverer is still seeded' );
	}

	public function test_nor_when_the_item_already_names_the_client(): void {
		$seeded = RoleHours::seed( array( 'hours_primary' => 10.0 ), array( 'reviewer_id' => 'client' ) );

		$this->assertArrayNotHasKey( 'hours_review', $seeded );
	}

	public function test_review_hours_are_zero_when_the_client_reviews(): void {
		$settled = ClientReviewer::settle(
			array(
				'reviewer_id'  => 'client',
				'hours_review' => 4.0,
			),
			array( 'hours_review' => 2.0 )
		);

		$this->assertSame( 0.0, $settled['hours_review'] );
	}

	public function test_choosing_the_client_zeroes_hours_already_there(): void {
		$settled = ClientReviewer::settle( array( 'reviewer_id' => 'client' ), array( 'hours_review' => 3.0 ) );

		$this->assertSame( 0.0, $settled['hours_review'] );
	}

	public function test_and_clears_the_stand_in(): void {
		$settled = ClientReviewer::settle(
			array( 'reviewer_id' => 'client' ),
			array(
				'hours_review'           => 0.0,
				'reviewer_substitute_id' => 'usr_sub',
			)
		);

		$this->assertSame( '', $settled['reviewer_substitute_id'] );
	}

	public function test_an_edit_that_changes_nothing_writes_nothing(): void {
		$settled = ClientReviewer::settle(
			array(),
			array(
				'reviewer_id'            => 'client',
				'hours_review'           => 0.0,
				'reviewer_substitute_id' => '',
			)
		);

		$this->assertSame( array(), $settled );
	}

	public function test_a_person_reviewing_is_left_alone(): void {
		$changes = array(
			'reviewer_id'            => 'usr_abc',
			'hours_review'           => 4.0,
			'reviewer_substitute_id' => 'usr_sub',
		);

		$this->assertSame( $changes, ClientReviewer::settle( $changes, array( 'reviewer_id' => 'client' ) ) );
	}

	// ---- Seat access (#393) -------------------------------------------

	public function test_the_client_is_never_dropped_as_somebody_without_access(): void {
		$kept = PersonReach::drop_unreached(
			array(
				'primary_user_id' => 'usr_gone',
				'reviewer_id'     => 'client',
			),
			'cli_a',
			'sit_a',
			function ( string $id ): bool {
				$this->assertNotSame( 'client', $id, 'the client was asked about as a person' );

				return false;
			}
		);

		$this->assertSame( 'client', $kept['reviewer_id'] );
		$this->assertSame( '', $kept['primary_user_id'] );
	}

	public function test_nor_refused_as_one(): void {
		$this->assertSame( array(), PersonReach::seat_refusals( array( 'reviewer_id' => 'client' ), 'cli_a', 'sit_a' ) );
	}
}
