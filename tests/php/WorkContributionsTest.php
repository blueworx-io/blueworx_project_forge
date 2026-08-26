<?php
/**
 * What a client may add to their work, and what it can never be.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\Capabilities;
use Blueworx\Forge\Tenancy\Roles;
use Blueworx\Forge\Work\Comments;
use Blueworx\Forge\Work\Contributions;
use Blueworx\Forge\Work\Stages;
use PHPUnit\Framework\TestCase;

/**
 * #133. Clients can participate without being able to move anything.
 *
 * The issue is closed on one sentence — each action succeeds and leaves the
 * stage untouched — and that sentence has two halves that fail in opposite
 * directions. A contribution that is refused is a client who goes back to
 * email; a contribution that moves work is the client transition lock gone.
 *
 * So both halves are tested here, and the second is tested as a property of the
 * shape rather than as an absence of one particular bug. Work\Contributions
 * cannot express a stage: every key it produces is enumerated below, none of
 * them is one, and a caller sending one gets a comment back.
 */
final class WorkContributionsTest extends TestCase {

	/**
	 * A work item a client is contributing to.
	 *
	 * @return array<string, mixed>
	 */
	private function item(): array {
		return array(
			'id'             => 'wrk_1',
			'client_site_id' => 'site_ours',
			'client_id'      => 'cli_ours',
			'stage'          => 'in-development',
		);
	}

	/**
	 * A question outstanding on that item.
	 *
	 * @param string $id The question's id.
	 * @return array<int, array<string, mixed>>
	 */
	private function asking( string $id = 'cmt_q1' ): array {
		return array(
			array(
				'id'   => $id,
				'kind' => Comments::QUESTION,
				'body' => 'Which page is this happening on?',
			),
		);
	}

	// ---- Nothing here moves work ----------------------------------------

	/**
	 * The test the whole issue turns on, written as a property of the read.
	 *
	 * A stage cannot survive the trip. Every plausible way of naming one is
	 * offered at once and none of them is in what comes out — so there is no
	 * route, however it is called, through which a client contribution reaches
	 * a transition.
	 */
	public function test_a_contribution_cannot_carry_a_stage(): void {
		$asked = Contributions::read(
			array(
				'body'       => 'Any news?',
				'stage'      => 'released',
				'to'         => 'released',
				'to_stage'   => 'released',
				'transition' => 'released',
				'move'       => 'forward',
			)
		);

		$this->assertSame( array( 'kind', 'body', 'url', 'answers' ), array_keys( $asked ) );

		foreach ( $asked as $value ) {
			$this->assertNotContains( $value, Stages::ALL );
		}
	}

	/**
	 * And neither can the entry it becomes.
	 *
	 * The entry is what reaches the writer, so this is the last point at which
	 * a stage could appear. The item's own stage is not copied onto it either:
	 * a comment carrying the stage it was written at would be a column
	 * somebody eventually writes back.
	 */
	public function test_the_entry_a_contribution_becomes_names_no_stage(): void {
		$entry = Contributions::entry(
			Contributions::read( array( 'body' => 'Any news?', 'stage' => 'released' ) ),
			$this->item(),
			'site_registry_1',
			'Someone at the client'
		);

		foreach ( array( 'stage', 'to', 'to_stage', 'prior_stage', 'transition' ) as $forbidden ) {
			$this->assertArrayNotHasKey( $forbidden, $entry );
		}
	}

	/**
	 * The item, its site and its client come from the item, never from what was
	 * sent. A contribution that could name a site would be a way to write onto
	 * another client's work (D-1, D-2).
	 */
	public function test_the_entry_takes_its_tenancy_from_the_item(): void {
		$entry = Contributions::entry(
			Contributions::read(
				array(
					'body'           => 'Any news?',
					'client_id'      => 'cli_theirs',
					'client_site_id' => 'site_theirs',
					'item_id'        => 'wrk_theirs',
				)
			),
			$this->item(),
			'site_registry_1',
			'Someone at the client'
		);

		$this->assertSame( 'wrk_1', $entry['item_id'] );
		$this->assertSame( 'site_ours', $entry['client_site_id'] );
		$this->assertSame( 'cli_ours', $entry['client_id'] );
	}

	/**
	 * Nothing a client writes is ever an internal note. There is nowhere for
	 * one of theirs to be internal to.
	 */
	public function test_a_client_contribution_is_always_client_visible(): void {
		$entry = Contributions::entry(
			Contributions::read( array( 'body' => 'Any news?', 'visibility' => 'internal' ) ),
			$this->item(),
			'site_registry_1',
			'Someone'
		);

		$this->assertSame( Comments::CLIENT, $entry['visibility'] );
	}

	/**
	 * A client writes as their site rather than as one of our users. Both at
	 * once would be a row two different stories could be told about.
	 */
	public function test_a_client_contribution_is_attributed_to_the_site(): void {
		$entry = Contributions::entry(
			Contributions::read( array( 'body' => 'Any news?' ) ),
			$this->item(),
			'site_registry_1',
			'Someone at the client'
		);

		$this->assertSame( 0, $entry['author'] );
		$this->assertSame( 'site_registry_1', $entry['author_site'] );
		$this->assertSame( 'Someone at the client', $entry['author_name'] );
	}

	// ---- The three things a client may do -------------------------------

	/**
	 * A comment goes through.
	 */
	public function test_a_client_may_comment(): void {
		$this->assertSame(
			Contributions::ALLOWED,
			Contributions::refuse( Contributions::read( array( 'body' => 'Still waiting on this?' ) ) )
		);
	}

	/**
	 * So does evidence, with something to look at.
	 */
	public function test_a_client_may_attach_evidence(): void {
		$this->assertSame(
			Contributions::ALLOWED,
			Contributions::refuse(
				Contributions::read(
					array(
						'kind' => Comments::EVIDENCE,
						'body' => 'This is the page.',
						'url'  => 'https://example.test/broken',
					)
				)
			)
		);
	}

	/**
	 * And so does an answer to something outstanding.
	 */
	public function test_a_client_may_answer_an_outstanding_question(): void {
		$this->assertSame(
			Contributions::ALLOWED,
			Contributions::refuse(
				Contributions::read(
					array(
						'body'    => 'The bookings page.',
						'answers' => 'cmt_q1',
					)
				),
				$this->asking()
			)
		);
	}

	// ---- And the ways each is refused -----------------------------------

	/**
	 * Evidence with nothing to look at is a comment claiming to be evidence.
	 */
	public function test_evidence_needs_something_to_look_at(): void {
		$this->assertSame(
			Contributions::EVIDENCE_UNSUPPORTED,
			Contributions::refuse( Contributions::read( array( 'kind' => Comments::EVIDENCE, 'body' => 'Look.' ) ) )
		);
	}

	/**
	 * Nothing said and nothing linked is nothing to send.
	 */
	public function test_an_empty_contribution_is_refused(): void {
		$this->assertSame(
			Contributions::EMPTY_ENTRY,
			Contributions::refuse( Contributions::read( array() ) )
		);
	}

	/**
	 * A client does not ask themselves for information. The question is the
	 * studio's kind, and a client sending one would be able to make their own
	 * work look as though it were waiting on us.
	 */
	public function test_a_client_cannot_ask_an_information_request(): void {
		$this->assertSame(
			Contributions::NOT_THEIRS,
			Contributions::refuse( Contributions::read( array( 'kind' => Comments::QUESTION, 'body' => 'Well?' ) ) )
		);
	}

	/**
	 * An answer names a question that is genuinely outstanding on this item.
	 *
	 * Otherwise "the studio is waiting on the client" would be a flag a client
	 * could clear by sending the right string — which is a worse failure than
	 * an unanswered question, because it is silent.
	 */
	public function test_an_answer_to_an_invented_question_is_refused(): void {
		$this->assertSame(
			Contributions::NO_SUCH_QUESTION,
			Contributions::refuse(
				Contributions::read( array( 'body' => 'Done.', 'answers' => 'cmt_made_up' ) ),
				$this->asking()
			)
		);
	}

	/**
	 * And to a question on somebody else's item, which reaches this the same
	 * way: the caller's list of outstanding questions is the one for the item
	 * they named, so an id from elsewhere is simply not in it.
	 */
	public function test_an_answer_to_another_items_question_is_refused(): void {
		$this->assertSame(
			Contributions::NO_SUCH_QUESTION,
			Contributions::refuse(
				Contributions::read( array( 'body' => 'Done.', 'answers' => 'cmt_q1' ) ),
				array()
			)
		);
	}

	/**
	 * Evidence does not answer a question on its own. Somebody replying with a
	 * screenshot writes a sentence with it, and the sentence is the answer.
	 */
	public function test_evidence_does_not_answer_a_question(): void {
		$this->assertSame(
			Contributions::NO_SUCH_QUESTION,
			Contributions::refuse(
				Contributions::read(
					array(
						'kind'    => Comments::EVIDENCE,
						'url'     => 'https://example.test/shot.png',
						'answers' => 'cmt_q1',
					)
				),
				$this->asking()
			)
		);
	}

	/**
	 * Every refusal has a sentence, so none of them reaches a person as a code.
	 */
	public function test_every_refusal_has_words(): void {
		foreach ( array( Contributions::NOT_THEIRS, Contributions::EMPTY_ENTRY, Contributions::EVIDENCE_UNSUPPORTED, Contributions::NO_SUCH_QUESTION ) as $code ) {
			$this->assertNotSame( '', trim( Contributions::reason( $code ) ), "{$code} has no message" );
			$this->assertNotSame( $code, Contributions::reason( $code ), "{$code} reads as its own code" );
		}
	}

	// ---- Which permission each exercises --------------------------------

	/**
	 * Three kinds, three rows of the matrix. One question for all three would
	 * give whoever may comment the ability to speak for the organisation.
	 */
	public function test_each_contribution_asks_for_its_own_capability(): void {
		$this->assertSame(
			Capabilities::COMMENT,
			Contributions::capability( Contributions::read( array( 'body' => 'Hello.' ) ) )
		);

		$this->assertSame(
			Capabilities::ATTACH_EVIDENCE,
			Contributions::capability( Contributions::read( array( 'kind' => Comments::EVIDENCE, 'url' => 'https://example.test/a' ) ) )
		);

		$this->assertSame(
			Capabilities::ANSWER_INFORMATION,
			Contributions::capability( Contributions::read( array( 'body' => 'Yes.', 'answers' => 'cmt_q1' ) ) )
		);
	}

	/**
	 * A client administrator holds all three on their own site — which is what
	 * makes this issue's "each action succeeds" half true rather than a hope.
	 */
	public function test_a_client_administrator_may_do_all_three(): void {
		$context = array(
			'role'      => Roles::CLIENT_ADMIN,
			'interface' => Capabilities::CLIENT,
			'own_site'  => true,
		);

		foreach ( array( Capabilities::COMMENT, Capabilities::ATTACH_EVIDENCE, Capabilities::ANSWER_INFORMATION ) as $capability ) {
			$this->assertTrue( Capabilities::allows( $capability, $context ), "{$capability} is refused" );
		}
	}

	/**
	 * And holds none of the ways work moves, on either interface. The other
	 * half, and the reason the first half is safe to grant.
	 */
	public function test_a_client_administrator_moves_nothing(): void {
		foreach ( array( Capabilities::STUDIO, Capabilities::CLIENT ) as $interface ) {
			foreach ( Capabilities::workflow() as $capability ) {
				$decision = Capabilities::decide(
					$capability,
					array(
						'role'      => Roles::CLIENT_ADMIN,
						'interface' => $interface,
						'own_site'  => true,
					)
				);

				$this->assertFalse( $decision['allowed'], "{$capability} allowed on {$interface}" );
			}
		}
	}

	/**
	 * A client viewer comments and does not answer.
	 *
	 * The one place the three capabilities genuinely differ, and the reason
	 * they are three rows rather than one: an answer is somebody speaking for
	 * their organisation, and a viewer is somebody speaking for themselves.
	 */
	public function test_a_client_viewer_comments_but_does_not_answer(): void {
		$context = array(
			'role'      => Roles::CLIENT_VIEWER,
			'interface' => Capabilities::CLIENT,
			'own_site'  => true,
		);

		$this->assertTrue( Capabilities::allows( Capabilities::COMMENT, $context ) );
		$this->assertFalse( Capabilities::allows( Capabilities::ANSWER_INFORMATION, $context ) );
		$this->assertFalse( Capabilities::allows( Capabilities::ATTACH_EVIDENCE, $context ) );
	}
}
