<?php
/**
 * The client site's side of a review decision.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Client\Review;
use PHPUnit\Framework\TestCase;

/**
 * #391. The client approves, or sends back with a note, and the site says so
 * before anything crosses the wire when it cannot be sent.
 */
final class ClientReviewDecisionTest extends TestCase {

	public function test_an_approval_needs_nothing_else(): void {
		$this->assertSame( '', Review::problem( Review::APPROVE, '' ) );
	}

	public function test_sending_back_needs_a_note(): void {
		$this->assertSame( 'note_required', Review::problem( Review::SEND_BACK, '  ' ) );
		$this->assertSame( '', Review::problem( Review::SEND_BACK, 'The wrong logo.' ) );
	}

	public function test_there_are_only_two_decisions(): void {
		$this->assertSame( 'unknown_decision', Review::problem( 'reopen', 'Please.' ) );
	}

	public function test_it_goes_to_the_one_review_route(): void {
		$this->assertSame( '/client/work-items/wrk_a/review', Review::route( 'wrk_a' ) );
	}

	public function test_nothing_is_sent_without_a_connection(): void {
		$sent = Review::send( 'wrk_a', Review::APPROVE, '', 'Jo' );

		$this->assertFalse( $sent['ok'] );
		$this->assertSame( 'not_connected', $sent['result'] );
	}
}
