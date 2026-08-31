<?php
/**
 * Exactly one email per qualifying event.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Notifications\Events;
use Blueworx\Forge\Work\Stages;
use PHPUnit\Framework\TestCase;

/**
 * #172, NOTIF-3. The identity of a notification.
 *
 * The rule underneath every test here is that the id comes from *what
 * happened*, never from when anybody noticed it. That is what makes a replayed
 * sync, a retried send and a second page load all compute the same string, and
 * it is why none of these tests mention time.
 *
 * The one place identity is allowed to change is which time round: work that is
 * reopened and released again really has been released twice, and a client
 * should hear about it twice. Both halves of that are tested, because getting
 * either wrong is silent — one suppresses a real email for ever, the other
 * sends a duplicate.
 */
final class NotificationEventsTest extends TestCase {

	/* ------------------------------------------------------------- identity */

	public function test_the_same_event_always_has_the_same_id(): void {
		$this->assertSame(
			Events::id_for( Events::RELEASED, 'wrk_abc', 1 ),
			Events::id_for( Events::RELEASED, 'wrk_abc', 1 )
		);
	}

	public function test_two_different_items_are_two_events(): void {
		$this->assertNotSame(
			Events::id_for( Events::RELEASED, 'wrk_abc' ),
			Events::id_for( Events::RELEASED, 'wrk_def' )
		);
	}

	public function test_two_things_happening_to_one_item_are_two_events(): void {
		// Ready and live are different messages, so they cannot share an id —
		// or sending the first would suppress the second.
		$this->assertNotSame(
			Events::id_for( Events::COMPLETED, 'wrk_abc' ),
			Events::id_for( Events::RELEASED, 'wrk_abc' )
		);
	}

	public function test_a_second_time_round_is_a_second_event(): void {
		/*
		 * Reopened and released again. NOTIF-2 makes released the message that
		 * matters, and a client who has had work released to them twice should
		 * have been told twice.
		 */
		$this->assertNotSame(
			Events::id_for( Events::RELEASED, 'wrk_abc', 1 ),
			Events::id_for( Events::RELEASED, 'wrk_abc', 2 )
		);
	}

	public function test_a_missing_occurrence_is_the_first_one(): void {
		// So a caller that does not know about cycles cannot accidentally
		// create a second event for the first release.
		$this->assertSame(
			Events::id_for( Events::RELEASED, 'wrk_abc', 1 ),
			Events::id_for( Events::RELEASED, 'wrk_abc' )
		);
	}

	public function test_a_nonsense_occurrence_is_still_the_first_one(): void {
		$this->assertSame(
			Events::id_for( Events::RELEASED, 'wrk_abc', 1 ),
			Events::id_for( Events::RELEASED, 'wrk_abc', 0 )
		);
	}

	/* ---------------------------------------------------------- the id itself */

	public function test_an_id_says_what_it_is(): void {
		$this->assertMatchesRegularExpression(
			'/^nev_[0-9a-f]{26}$/',
			Events::id_for( Events::RELEASED, 'wrk_abc' )
		);
	}

	public function test_an_id_fits_the_column_it_lives_in(): void {
		// varchar(32). An id that did not fit would be silently truncated, and
		// two events would then collide.
		$this->assertLessThanOrEqual( 32, strlen( Events::id_for( Events::RELEASED, 'wrk_abc' ) ) );
	}

	/* -------------------------------------------------- refusing a non-event */

	public function test_an_event_nobody_defined_has_no_id(): void {
		// The thing at the other end of this is an email to a client, so an
		// unrecognised kind is refused rather than raised under its own name.
		$this->assertSame( '', Events::id_for( 'work-nearly-done', 'wrk_abc' ) );
	}

	public function test_an_event_about_nothing_has_no_id(): void {
		$this->assertSame( '', Events::id_for( Events::RELEASED, '' ) );
	}

	public function test_the_three_are_the_three(): void {
		$this->assertSame(
			array( Events::RECEIVED, Events::COMPLETED, Events::RELEASED ),
			Events::ALL
		);

		foreach ( Events::ALL as $kind ) {
			$this->assertTrue( Events::exists( $kind ) );
		}

		$this->assertFalse( Events::exists( 'work-nearly-done' ) );
	}

	/* ------------------------------------------------ which moves tell anybody */

	public function test_finishing_and_releasing_both_tell_the_client(): void {
		// NOTIF-2: Completed still sends, worded as ready rather than done.
		$this->assertSame( Events::COMPLETED, Events::for_stage( Stages::COMPLETED ) );
		$this->assertSame( Events::RELEASED, Events::for_stage( Stages::RELEASED ) );
	}

	public function test_every_other_stage_tells_nobody(): void {
		/*
		 * Asserted over the whole list rather than over a couple of examples,
		 * so a stage added later is silent by default. A new stage that started
		 * emailing clients because it was added to a list somewhere else is
		 * exactly the accident worth spending a test on.
		 */
		foreach ( Stages::ALL as $stage ) {
			if ( Stages::COMPLETED === $stage || Stages::RELEASED === $stage ) {
				continue;
			}

			$this->assertSame( '', Events::for_stage( $stage ), $stage . ' should tell nobody' );
		}
	}

	public function test_a_stage_nobody_defined_tells_nobody(): void {
		$this->assertSame( '', Events::for_stage( 'nearly-there' ) );
	}

	/* --------------------------------------------------- what it is all about */

	public function test_a_request_notification_is_about_a_submission(): void {
		$this->assertSame( Events::SUBMISSION, Events::subject_type( Events::RECEIVED ) );
	}

	public function test_the_other_two_are_about_work(): void {
		$this->assertSame( Events::WORK_ITEM, Events::subject_type( Events::COMPLETED ) );
		$this->assertSame( Events::WORK_ITEM, Events::subject_type( Events::RELEASED ) );
	}
}
