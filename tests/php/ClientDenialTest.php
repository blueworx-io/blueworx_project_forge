<?php
/**
 * What a client is told when something is not theirs to see or do.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Client\Denial;
use Blueworx\Forge\Client\Sync;
use PHPUnit\Framework\TestCase;

/**
 * #134. A client who cannot do something understands why.
 *
 * The issue is closed on one sentence — no denial surfaces as a silent failure
 * or an unexplained error — and a sentence like that is only kept by a test
 * that walks everything. So this walks: every state a client screen can be in,
 * against every subject a client screen can show, and asserts that all of them
 * produce words a person can act on.
 *
 * That is the shape that catches the failure this issue is actually about.
 * Nobody sets out to write an unexplained error; what happens is that a sixth
 * state arrives and five screens do not know about it. A test that checks the
 * cases somebody remembered would have passed the whole time.
 */
final class ClientDenialTest extends TestCase {

	/**
	 * Every state a screen has to explain, whatever the subject.
	 *
	 * @return array<int, string>
	 */
	private function states(): array {
		return array( Sync::STATE_NOT_CONFIGURED, Sync::STATE_REFUSED, Sync::STATE_UNREACHABLE );
	}

	// ---- Every state, every subject -------------------------------------

	/**
	 * The test the issue turns on: nothing anywhere comes back empty.
	 */
	public function test_every_state_and_subject_has_something_to_say(): void {
		foreach ( $this->states() as $state ) {
			foreach ( Denial::SUBJECTS as $subject ) {
				$this->assertNotSame(
					'',
					trim( Denial::sentence( $state, $subject ) ),
					"{$state} on {$subject} says nothing"
				);
			}
		}
	}

	/**
	 * And nothing is a code, a slug or a status.
	 *
	 * An "unexplained error" in practice is rarely a blank screen. It is
	 * `bwx_forge_client_refused` in a box, or a 401, or the word `unreachable`
	 * — a true statement in a language the reader does not speak.
	 */
	public function test_nothing_shown_to_a_client_is_machine_language(): void {
		foreach ( $this->states() as $state ) {
			foreach ( Denial::SUBJECTS as $subject ) {
				$said = Denial::sentence( $state, $subject );

				$this->assertStringNotContainsString( 'bwx_', $said );
				$this->assertStringNotContainsString( '_', $said, "{$state} on {$subject} reads as a slug" );
				$this->assertMatchesRegularExpression( '/[.!?]$/', trim( $said ), "{$state} on {$subject} is not a sentence" );
			}
		}
	}

	/**
	 * The three states never say the same thing.
	 *
	 * Not connected, could not be reached and refused are three different
	 * problems with three different next steps, and one sentence covering all
	 * three helps with none of them. This is the assertion that stops a future
	 * tidy-up folding them back together.
	 */
	public function test_the_three_states_are_told_apart(): void {
		foreach ( Denial::SUBJECTS as $subject ) {
			$said = array_map(
				static fn( string $state ): string => Denial::sentence( $state, $subject ),
				$this->states()
			);

			$this->assertCount(
				3,
				array_unique( $said ),
				"{$subject} gives the same answer to two different problems"
			);
		}
	}

	// ---- What each state has to get right -------------------------------

	/**
	 * A studio that cannot be reached always says nothing has been lost.
	 *
	 * The sentence that stops somebody ringing in a panic, and it is true — the
	 * studio holds the record and this site only shows it. It is asserted
	 * rather than trusted because it is the first thing a rewrite drops.
	 */
	public function test_an_outage_always_says_nothing_has_been_lost(): void {
		foreach ( Denial::SUBJECTS as $subject ) {
			$this->assertMatchesRegularExpression(
				'/nothing has been lost/i',
				Denial::sentence( Sync::STATE_UNREACHABLE, $subject ),
				"{$subject} does not reassure"
			);
		}
	}

	/**
	 * A refusal always says the connection is working.
	 *
	 * The whole point of the state existing. Before it, a revoked key read as
	 * an outage and sent people to look at their internet — so the sentence has
	 * to rule that out explicitly rather than merely not mentioning it.
	 */
	public function test_a_refusal_always_says_the_connection_is_working(): void {
		foreach ( Denial::SUBJECTS as $subject ) {
			$this->assertMatchesRegularExpression(
				'/connection (to the studio )?is working/i',
				Denial::sentence( Sync::STATE_REFUSED, $subject ),
				"{$subject} lets a refusal read as an outage"
			);
		}
	}

	/**
	 * Nothing ever tells a client their things are gone.
	 *
	 * The one failure mode worse than an unexplained error: a screen that says
	 * "you have nothing" when the truth is "we cannot see your things right
	 * now". Checked against the phrasings that would do it.
	 */
	public function test_nothing_tells_a_client_their_work_has_gone(): void {
		$never = array( 'you have no', 'nothing here', 'no results', 'empty', 'deleted', 'removed' );

		foreach ( $this->states() as $state ) {
			foreach ( Denial::SUBJECTS as $subject ) {
				$said = mb_strtolower( Denial::sentence( $state, $subject ) );

				foreach ( $never as $phrase ) {
					$this->assertStringNotContainsString(
						$phrase,
						$said,
						"{$state} on {$subject} reads as though the work is gone"
					);
				}
			}
		}
	}

	/**
	 * A refusal never says which refusal it was.
	 *
	 * The studio answers "not there" and "not yours" identically on purpose
	 * (D-1, D-2), and a client screen that unpicked that would give back
	 * exactly what the matching answers exist to hide.
	 */
	public function test_a_refusal_never_names_another_client(): void {
		foreach ( Denial::SUBJECTS as $subject ) {
			$said = mb_strtolower( Denial::sentence( Sync::STATE_REFUSED, $subject ) );

			foreach ( array( 'another client', 'someone else', 'somebody else', 'belongs to' ) as $phrase ) {
				$this->assertStringNotContainsString( $phrase, $said, "{$subject} says whose it is" );
			}
		}
	}

	// ---- Which states are denials at all --------------------------------

	/**
	 * The three that need explaining, and only those.
	 */
	public function test_only_the_three_states_with_nothing_to_show_are_denials(): void {
		foreach ( $this->states() as $state ) {
			$this->assertTrue( Denial::applies( $state ), "{$state} is not treated as a denial" );
		}
	}

	/**
	 * A stale record is not a denial.
	 *
	 * The distinction the whole class turns on. Stale means the record is on
	 * screen with its age against it and the screen carries on working — which
	 * is ARCH-4's promise, and replacing it with an apology would break the
	 * thing the promise exists for.
	 */
	public function test_a_stale_record_is_not_a_denial(): void {
		$this->assertFalse( Denial::applies( Sync::STATE_STALE ) );
		$this->assertFalse( Denial::applies( Sync::STATE_CACHED ) );
		$this->assertFalse( Denial::applies( Sync::STATE_LIVE ) );
	}

	/**
	 * A refusal is not stale, and the sync block says so.
	 *
	 * "No, as of an hour ago" is not a thing anybody needs told, and a screen
	 * that treated a refusal as stale would offer a "check again" that gets the
	 * same answer — the dead control this issue exists to remove.
	 */
	public function test_a_refusal_is_not_reported_as_stale(): void {
		$this->assertFalse( Sync::is_stale( Sync::STATE_REFUSED ) );
		$this->assertTrue( Sync::is_refusal( Sync::STATE_REFUSED ) );
	}
}
