<?php
/**
 * The client as the reviewer.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Slack\Notify;
use Blueworx\Forge\Standup\Rules;
use Blueworx\Forge\Tenancy\PersonReach;
use Blueworx\Forge\Work\ClientReviewer;
use Blueworx\Forge\Work\Gates;
use Blueworx\Forge\Work\RoleHours;
use Blueworx\Forge\Work\Validate;
use PHPUnit\Framework\TestCase;

/**
 * #391. "The client" can be the reviewer at any stage (#468), and their review
 * time counts against nobody.
 */
final class ClientReviewerTest extends TestCase {

	// ---- Validation ----------------------------------------------------

	public function test_the_client_can_be_the_reviewer_at_any_stage(): void {
		// #468. From the start, not only from Up Next: nothing about the
		// stage is asked.
		$checked = Validate::item( array( 'reviewer_id' => 'client' ), true );

		$this->assertSame( array(), $checked['errors'] );
		$this->assertSame( 'client', $checked['values']['reviewer_id'] );
	}

	public function test_new_work_can_start_with_the_client_reviewing(): void {
		$checked = Validate::item(
			array(
				'title'       => 'A task',
				'level'       => 'feature',
				'work_type'   => 'feature',
				'reviewer_id' => 'client',
			),
			false
		);

		$this->assertArrayNotHasKey( 'reviewer_id', $checked['errors'] );
		$this->assertSame( 'client', $checked['values']['reviewer_id'] );
	}

	public function test_only_the_reviewer_seat_takes_the_client(): void {
		foreach ( array( 'primary_user_id', 'deliverer_id', 'reviewer_substitute_id', 'deliverer_substitute_id' ) as $seat ) {
			$checked = Validate::item( array( $seat => 'client' ), true );

			$this->assertSame( 'That is not a person.', $checked['errors'][ $seat ] ?? '', "{$seat} took the client" );
		}
	}

	public function test_the_client_is_never_the_person_who_did_the_work(): void {
		$checked = Validate::item(
			array(
				'primary_user_id' => 'usr_abc',
				'reviewer_id'     => 'client',
			),
			true
		);

		$this->assertSame( array(), $checked['errors'] );
	}

	public function test_it_knows_the_client_when_it_sees_it(): void {
		$this->assertTrue( ClientReviewer::is( array( 'reviewer_id' => 'client' ) ) );
		$this->assertFalse( ClientReviewer::is( array( 'reviewer_id' => 'usr_client' ) ) );
		$this->assertFalse( ClientReviewer::is( array() ) );
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

	public function test_up_next_asks_for_no_review_hours_from_the_client(): void {
		$requirement = (array) Gates::requirement( 'G-UP-NEXT-4' );
		$item        = array(
			'hours_primary'  => 4.0,
			'hours_review'   => 0.0,
			'hours_delivery' => 1.0,
		);

		$this->assertFalse( Gates::satisfied( $requirement, $item + array( 'reviewer_id' => 'usr_abc' ), array() ) );
		$this->assertTrue( Gates::satisfied( $requirement, $item + array( 'reviewer_id' => 'client' ), array() ) );
	}

	public function test_slack_does_not_tell_the_client_they_were_given_a_seat(): void {
		$this->assertSame(
			array( 'primary_user_id' => 'usr_abc' ),
			Notify::seat_changes(
				array(),
				array(
					'primary_user_id' => 'usr_abc',
					'reviewer_id'     => 'client',
				)
			)
		);
	}

	public function test_standup_says_it_waits_on_the_client(): void {
		$cards = Rules::for_item(
			array(
				'id'             => 'wrk_a',
				'title'          => 'A task',
				'stage'          => 'in-review',
				'reviewer_id'    => 'client',
				'client_id'      => 'cli_a',
				'client_site_id' => 'sit_a',
				// In review, it is on the standup once due (2026-09-29).
				'planned_due'    => '2026-09-26',
			),
			'2026-09-26'
		);
		$review = array_values( array_filter( $cards, static fn( array $card ): bool => Rules::AWAITING_REVIEW === $card['rule'] ) );

		$this->assertSame( 'The client', $review[0]['detail']['waiting_on_name'] ?? '' );
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
