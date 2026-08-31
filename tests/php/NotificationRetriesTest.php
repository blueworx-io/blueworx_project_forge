<?php
/**
 * How long to wait before trying an email again.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Notifications\Register;
use Blueworx\Forge\Notifications\Retries;
use PHPUnit\Framework\TestCase;

/**
 * #174, NOTIF-3. Three retries at 5, 30 and 120 minutes, then a person.
 *
 * The half of this worth testing hardest is the end. Retrying for ever is the
 * tempting implementation — nothing is ever lost and nothing needs a person —
 * and it is how a client goes a fortnight without hearing from us while a queue
 * quietly churns. So most of what follows is about the ladder stopping, and
 * about a failure that has stopped being retried being visibly different from
 * one that has not.
 */
final class NotificationRetriesTest extends TestCase {

	private const NOW = 1788000000;

	public function test_the_first_failure_waits_five_minutes(): void {
		$this->assertSame( self::NOW + 300, Retries::due_at( 1, self::NOW ) );
	}

	public function test_the_second_waits_half_an_hour(): void {
		$this->assertSame( self::NOW + 1800, Retries::due_at( 2, self::NOW ) );
	}

	public function test_the_third_waits_two_hours(): void {
		$this->assertSame( self::NOW + 7200, Retries::due_at( 3, self::NOW ) );
	}

	public function test_the_gaps_widen(): void {
		/*
		 * Asserted as a shape rather than as three numbers, because the reason
		 * is the shape: a mail server refusing a connection for a moment is
		 * fixed in five minutes, and a misconfigured site is not — hammering it
		 * turns one problem into a reputation problem with the client's own
		 * mail provider.
		 */
		$gaps = Retries::LADDER;

		for ( $i = 1; $i < count( $gaps ); $i++ ) {
			$this->assertGreaterThan( $gaps[ $i - 1 ], $gaps[ $i ] );
		}
	}

	/* ------------------------------------------------------- the ladder ends */

	public function test_a_fourth_failure_is_not_tried_again(): void {
		$this->assertFalse( Retries::again_after( Retries::LIMIT ) );
		$this->assertSame( 0, Retries::due_at( Retries::LIMIT, self::NOW ) );
	}

	public function test_the_ladder_has_exactly_as_many_rungs_as_it_claims(): void {
		// One attempt, then three retries. A ladder longer than the constant
		// says would retry past the point anybody is watching.
		$this->assertCount( Retries::LIMIT - 1, Retries::LADDER );
	}

	public function test_every_attempt_before_the_last_has_somewhere_to_go(): void {
		for ( $attempt = 1; $attempt < Retries::LIMIT; $attempt++ ) {
			$this->assertTrue( Retries::again_after( $attempt ), 'attempt ' . $attempt );
			$this->assertGreaterThan( self::NOW, Retries::due_at( $attempt, self::NOW ) );
		}
	}

	public function test_nothing_is_due_before_it_has_been_tried(): void {
		$this->assertFalse( Retries::again_after( 0 ) );
		$this->assertSame( 0, Retries::due_at( 0, self::NOW ) );
	}

	/* ------------------------------------------- retrying is not the same as failed */

	public function test_a_failure_with_road_left_is_only_retrying(): void {
		/*
		 * The distinction Standup reads. An event due to be tried again in five
		 * minutes must not appear on somebody's daily list, or the list fills up
		 * with problems that fix themselves and people stop reading it.
		 */
		foreach ( array( 1, 2, 3 ) as $attempt ) {
			$this->assertSame( Register::RETRYING, Retries::outcome_after( $attempt ), 'attempt ' . $attempt );
		}
	}

	public function test_the_last_failure_is_somebody_s_problem(): void {
		$this->assertSame( Register::FAILED, Retries::outcome_after( Retries::LIMIT ) );
	}

	public function test_retrying_is_not_counted_as_settled(): void {
		// It looks settled and is not. A caller treating it as finished would
		// stop asking a site to send an email that is still owed.
		$this->assertNotContains( Register::RETRYING, Register::SETTLED );
		$this->assertContains( Register::FAILED, Register::SETTLED );
		$this->assertContains( Register::SENT, Register::SETTLED );
		$this->assertContains( Register::SUPPRESSED, Register::SETTLED );
	}

	public function test_retrying_is_a_real_outcome(): void {
		$this->assertContains( Register::RETRYING, Register::OUTCOMES );
	}
}
