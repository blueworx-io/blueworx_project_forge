<?php
/**
 * The client's review decision.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\ClientReviewer;
use Blueworx\Forge\Work\GateRecords;
use Blueworx\Forge\Work\Transition;
use PHPUnit\Framework\TestCase;

/**
 * #391. The client approves or sends back, and only an item in review with the
 * client as its reviewer.
 */
final class ClientReviewTest extends TestCase {

	/**
	 * An item waiting on the client.
	 *
	 * @param array<string, mixed> $over What differs.
	 * @return array<string, mixed>
	 */
	private function item( array $over = array() ): array {
		return array_merge(
			array(
				'id'             => 'wrk_a',
				'client_site_id' => 'sit_a',
				'stage'          => 'in-review',
				'reviewer_id'    => 'client',
				'work_type'      => 'feature',
				'cycle'          => 1,
				'review_attempt' => 1,
				'record_version' => 3,
			),
			$over
		);
	}

	// ---- Refusals ------------------------------------------------------

	public function test_it_refuses_when_somebody_else_reviews(): void {
		$answer = Transition::client_review( $this->item( array( 'reviewer_id' => 'usr_abc' ) ), 'approve', '', 'Jane', 0 );

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 409, $answer->get_error_data()['status'] );
		$this->assertSame( ClientReviewer::NOT_THEIRS, $answer->get_error_code() );
	}

	public function test_it_refuses_work_not_in_review(): void {
		foreach ( array( 'completed', 'in-development', 'up-next', 'blocked' ) as $stage ) {
			$answer = Transition::client_review( $this->item( array( 'stage' => $stage ) ), 'approve', '', 'Jane', 0 );

			$this->assertInstanceOf( WP_Error::class, $answer );
			$this->assertSame( 409, $answer->get_error_data()['status'] );
			$this->assertSame( ClientReviewer::NOT_THEIRS, $answer->get_error_code(), "at {$stage}" );
			$this->assertSame( "This task isn't waiting for the client's review.", $answer->get_error_message() );
		}
	}

	public function test_a_second_decision_is_already_decided(): void {
		// Approved on attempt 2: still attempt 2. Sent back on 2: now attempt 3.
		$this->assertTrue( ClientReviewer::decided( array( 'review_attempt' => 2 ), 2 ) );
		$this->assertTrue( ClientReviewer::decided( array( 'review_attempt' => 3 ), 2 ) );

		// Nothing from the client yet, or only on an earlier review.
		$this->assertFalse( ClientReviewer::decided( array( 'review_attempt' => 1 ), null ) );
		$this->assertFalse( ClientReviewer::decided( array( 'review_attempt' => 4 ), 2 ) );
	}

	public function test_it_refuses_ended_work(): void {
		$answer = Transition::client_review( $this->item( array( 'terminal_outcome' => 'cancelled' ) ), 'approve', '', 'Jane', 0 );

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 409, $answer->get_error_data()['status'] );
	}

	public function test_sending_back_needs_a_note(): void {
		$answer = Transition::client_review( $this->item(), 'send_back', '   ', 'Jane', 0 );

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 400, $answer->get_error_data()['status'] );
		$this->assertSame( 'bwx_forge_feedback_required', $answer->get_error_code() );
	}

	public function test_it_knows_only_two_decisions(): void {
		$answer = Transition::client_review( $this->item(), 'reopen', 'Note.', 'Jane', 0 );

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 400, $answer->get_error_data()['status'] );
	}

	// ---- What is written down ------------------------------------------

	public function test_the_entry_names_the_client(): void {
		$this->assertSame( 'Approved by the client (Jane)', ClientReviewer::entry( 'approve', 'Jane', '' ) );
		$this->assertSame( 'Approved by the client', ClientReviewer::entry( 'approve', '', '' ) );
		$this->assertSame( 'Sent back by the client (Jane)', ClientReviewer::entry( 'send_back', 'Jane', '' ) );
	}

	public function test_or_the_admin_who_recorded_it(): void {
		$this->assertSame(
			'Approved by the client, recorded by Sam on the client\'s behalf',
			ClientReviewer::entry( 'approve', '', 'Sam' )
		);
		$this->assertSame(
			'Sent back by the client, recorded by Sam on the client\'s behalf',
			ClientReviewer::entry( 'send_back', '', 'Sam' )
		);
	}

	// ---- The gate view -------------------------------------------------

	public function test_the_approval_meets_the_reviewer_rows(): void {
		$records = GateRecords::from_client_approval(
			array(
				'id'          => 'evt_a',
				'item_id'     => 'wrk_a',
				'reason'      => 'Approved by the client (Jane)',
				'cycle'       => 1,
				'attempt'     => 2,
				'actor'       => 0,
				'occurred_at' => 100,
			)
		);

		$ids = array_column( $records, 'requirement' );
		sort( $ids );

		$this->assertSame( array( 'G-IN-REVIEW-1', 'G-IN-REVIEW-2', 'G-IN-REVIEW-4', 'G-IN-REVIEW-5' ), $ids );

		foreach ( $records as $record ) {
			$this->assertSame( 'G-IN-REVIEW', $record['gate'] );
			$this->assertSame( 2, $record['attempt'] );
			$this->assertTrue( $record['by_client'] );
		}
	}

	public function test_but_not_the_open_questions(): void {
		$records = GateRecords::from_client_approval(
			array(
				'id'          => 'evt_a',
				'item_id'     => 'wrk_a',
				'reason'      => 'Approved by the client',
				'cycle'       => 1,
				'attempt'     => 1,
				'actor'       => 0,
				'occurred_at' => 100,
			)
		);

		$this->assertNotContains( 'G-IN-REVIEW-3', array_column( $records, 'requirement' ) );
	}

	public function test_and_it_counts_only_for_its_own_attempt(): void {
		$records = GateRecords::from_client_approval(
			array(
				'id'          => 'evt_a',
				'item_id'     => 'wrk_a',
				'reason'      => 'Approved by the client',
				'cycle'       => 1,
				'attempt'     => 1,
				'actor'       => 0,
				'occurred_at' => 100,
			)
		);

		$this->assertArrayHasKey( 'G-IN-REVIEW-4', GateRecords::current_among( $this->item(), $records ) );
		$this->assertSame( array(), GateRecords::current_among( $this->item( array( 'review_attempt' => 2 ) ), $records ) );
	}
}
