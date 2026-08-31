<?php
/**
 * Which of the things Forge records are worth a person's attention.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Signals\Kinds;
use Blueworx\Forge\Work\Events;
use PHPUnit\Framework\TestCase;

/**
 * #175. The in-product signals that do not warrant an email.
 *
 * This is a test about restraint. Every action Forge records is a candidate,
 * and the failure mode is not missing one — it is including them all, at which
 * point the list is unread and the signal that mattered arrives in the same
 * silence as the noise. So most of what follows asserts an absence, with the
 * reason next to it, and the last test makes sure a new action added later
 * cannot arrive quietly.
 */
final class SignalsKindsTest extends TestCase {

	/* ------------------------------------------------------- what is left out */

	public function test_an_edited_field_is_not_a_signal(): void {
		// By volume these are most of the history, and a message saying somebody
		// changed a date is about a field rather than about the work.
		$this->assertFalse( Kinds::says( Events::EDITED ) );
		$this->assertFalse( Kinds::says( Events::CORRECTED ) );
	}

	public function test_new_work_appearing_is_not_a_signal(): void {
		// That is what the board is for, and a planning session that creates
		// fifty items would bury everything else in one afternoon.
		$this->assertFalse( Kinds::says( Events::CREATED ) );
	}

	public function test_the_email_machinery_reporting_on_itself_is_not_a_signal(): void {
		// A send that failed is a real problem and it is already on the daily
		// list (#174). Here as well would put it in front of the same person
		// twice, which is how people learn to skim both.
		$this->assertFalse( Kinds::says( Events::NOTIFIED ) );
	}

	public function test_removing_a_dependency_is_not_a_signal_though_adding_one_is(): void {
		// Adding one is somebody deciding this work now waits on something.
		// Removing one is usually tidying that decision up, and only the first
		// changes what anybody has to do.
		$this->assertTrue( Kinds::says( Events::DEPENDENCY_ADDED ) );
		$this->assertFalse( Kinds::says( Events::DEPENDENCY_REMOVED ) );
	}

	/* -------------------------------------------------------- what is kept in */

	public function test_work_coming_back_is_a_signal(): void {
		$this->assertTrue( Kinds::says( Events::RETURNED ) );
	}

	public function test_work_stopping_and_starting_again_are_both_signals(): void {
		$this->assertTrue( Kinds::says( Events::BLOCKED ) );
		$this->assertTrue( Kinds::says( Events::UNBLOCKED ) );
	}

	public function test_the_two_the_studio_agreed_would_be_visible_are_signals(): void {
		/*
		 * WF-5 and CAP-4. Going through a gate, or committing somebody past
		 * their hours, is allowed precisely because it is visible when it
		 * happens — a quiet override is a different product.
		 */
		$this->assertTrue( Kinds::says( Events::OVERRIDDEN ) );
		$this->assertTrue( Kinds::says( Events::OVER_ALLOCATED ) );
		$this->assertTrue( Kinds::is_governance( Events::OVERRIDDEN ) );
		$this->assertTrue( Kinds::is_governance( Events::OVER_ALLOCATED ) );
	}

	public function test_ordinary_progress_is_not_governance(): void {
		// The distinction the screen paints. If everything were governance, the
		// colour would say nothing.
		$this->assertFalse( Kinds::is_governance( Events::MOVED ) );
		$this->assertFalse( Kinds::is_governance( Events::RETURNED ) );
	}

	/* ----------------------------------------------------------- how it reads */

	public function test_every_signal_reads_as_something_a_person_would_say(): void {
		/*
		 * Checked across the whole list rather than one by one, so an action
		 * added later cannot reach a screen still called by its engine name.
		 * "over-allocated" is not a sentence anybody says out loud.
		 */
		foreach ( Kinds::WORTH_SAYING as $action ) {
			$this->assertNotSame( $action, Kinds::word( $action ), $action );
			$this->assertNotSame( '', Kinds::word( $action ), $action );
		}
	}

	public function test_every_signal_has_a_tone(): void {
		foreach ( Kinds::WORTH_SAYING as $action ) {
			$this->assertContains( Kinds::tone( $action ), array( 'late', 'stopped', 'waiting' ), $action );
		}
	}

	public function test_the_two_loud_ones_are_the_loud_tone(): void {
		$this->assertSame( 'stopped', Kinds::tone( Events::OVERRIDDEN ) );
		$this->assertSame( 'stopped', Kinds::tone( Events::OVER_ALLOCATED ) );
		$this->assertSame( 'late', Kinds::tone( Events::RETURNED ) );
		$this->assertSame( 'waiting', Kinds::tone( Events::MOVED ) );
	}

	/* ------------------------------------------------- and nothing invented */

	public function test_every_signal_is_a_real_recorded_action(): void {
		// A list naming something the engine never writes would be a feature
		// that silently does nothing, and would pass every other test here.
		foreach ( Kinds::WORTH_SAYING as $action ) {
			$this->assertContains( $action, Events::ACTIONS, $action );
		}
	}

	public function test_governance_is_a_subset_of_what_is_said_at_all(): void {
		foreach ( Kinds::GOVERNANCE as $action ) {
			$this->assertContains( $action, Kinds::WORTH_SAYING, $action );
		}
	}

	public function test_most_of_what_is_recorded_is_still_left_out(): void {
		/*
		 * A blunt guard against the drift this whole file exists to prevent.
		 * The moment a majority of recorded actions are signals, somebody has
		 * been adding them one at a time and this test is the conversation.
		 */
		$this->assertLessThan( count( Events::ACTIONS ), count( Kinds::WORTH_SAYING ) );
	}
}
