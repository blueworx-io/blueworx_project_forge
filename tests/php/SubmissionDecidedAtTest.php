<?php
/**
 * When a request was answered (#419).
 *
 * @package Blueworx\Forge\Tests
 */

declare( strict_types=1 );

use Blueworx\Forge\Work\Submissions;
use PHPUnit\Framework\TestCase;

/**
 * The Waiting column counts to the answer, not to today, so the answer's time
 * is kept: set the first time a request is accepted, declined or converted,
 * and cleared if it is put back to waiting.
 */
final class SubmissionDecidedAtTest extends TestCase {

	public function test_answering_a_request_records_when(): void {
		foreach ( array( 'accepted', 'declined', 'converted' ) as $state ) {
			$this->assertSame( 500, Submissions::decided_at( array( 'decided_at' => 0 ), $state, 500 ), $state );
		}
	}

	public function test_a_second_answer_keeps_the_first_time(): void {
		$this->assertSame( 300, Submissions::decided_at( array( 'decided_at' => 300 ), 'converted', 500 ) );
	}

	public function test_putting_it_back_to_waiting_clears_it(): void {
		foreach ( array( 'received', 'in-review' ) as $state ) {
			$this->assertSame( 0, Submissions::decided_at( array( 'decided_at' => 300 ), $state, 500 ), $state );
		}
	}
}
