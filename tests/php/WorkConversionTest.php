<?php
/**
 * Turning a request into work, in the right client's pipeline and no other.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\Conversion;
use Blueworx\Forge\Work\Levels;
use Blueworx\Forge\Work\Stages;
use Blueworx\Forge\Work\Types;
use PHPUnit\Framework\TestCase;

/**
 * #132. An accepted request becomes real work, for the right client, without
 * losing what was asked.
 *
 * Two claims are worth a test suite of their own, and they are the two the
 * issue is closed on:
 *
 * - **Conversion into another client's pipeline is impossible.** Every id a
 *   caller may send is checked against the site the submission itself came
 *   from, and one that resolves anywhere else is answered exactly as one that
 *   does not resolve at all. That last part matters as much as the refusal:
 *   the queue is the one studio screen that spans clients, and a distinguishable
 *   refusal there is a way to enumerate other clients' work (D-1, D-2, D-40).
 * - **The submission text survives conversion unchanged.** Proved in
 *   WorkSubmissionTriageTest as an allowlist and here as a shape: what a
 *   conversion writes onto the *item* is copied from the request, and nothing a
 *   conversion produces writes back over it.
 */
final class WorkConversionTest extends TestCase {

	/**
	 * A submission belonging to one site.
	 *
	 * @param array<string, mixed> $with Anything to override.
	 * @return array<string, mixed>
	 */
	private function submission( array $with = array() ): array {
		return array_merge(
			array(
				'id'                => 'sub_a1',
				'client_id'         => 'cli_ours',
				'client_site_id'    => 'site_ours',
				'type'              => 'request',
				'title'             => 'A booking form that takes deposits',
				'description'       => 'People ring up to pay and half of them never call back.',
				'desired_outcome'   => 'Money arrives with the booking.',
				'evidence'          => 'https://example.test/bookings',
				'intake_state'      => 'accepted',
				'converted_item_id' => '',
			),
			$with
		);
	}

	/**
	 * An item on a site.
	 *
	 * @param string $site  Which site it sits on.
	 * @param string $level Which rung it is.
	 * @return array<string, mixed>
	 */
	private function item( string $site, string $level = Levels::FEATURE ): array {
		return array(
			'id'             => 'wrk_1',
			'client_site_id' => $site,
			'level'          => $level,
		);
	}

	/**
	 * A conversion request.
	 *
	 * @param array<string, mixed> $with Anything to set.
	 * @return array<string, mixed>
	 */
	private function asked( array $with = array() ): array {
		return Conversion::read( $with );
	}

	// ---- Which pipeline the work lands in -------------------------------

	/**
	 * The test the whole issue turns on: a parent on another client's site is
	 * refused.
	 */
	public function test_a_parent_on_another_clients_site_is_refused(): void {
		$refused = Conversion::refuse(
			$this->submission(),
			$this->asked( array( 'parent_id' => 'wrk_theirs' ) ),
			$this->item( 'site_theirs' )
		);

		$this->assertSame( Conversion::UNKNOWN_PARENT, $refused );
	}

	/**
	 * And work on another client's site cannot be linked to this request
	 * either. Linking is the quieter of the two routes into D-40 — it creates
	 * nothing, so it looks like a bookkeeping change — and it would put one
	 * client's work behind another client's "this became" link.
	 */
	public function test_work_on_another_clients_site_cannot_be_linked(): void {
		$refused = Conversion::refuse(
			$this->submission(),
			$this->asked( array( 'item_id' => 'wrk_theirs' ) ),
			null,
			$this->item( 'site_theirs' )
		);

		$this->assertSame( Conversion::UNKNOWN_TARGET, $refused );
	}

	/**
	 * A record on another site is refused in the same words as one that is not
	 * there, so the two cannot be told apart.
	 *
	 * Written as an assertion about the codes rather than about a sentence,
	 * because the codes are what a caller compares. Two identical messages
	 * under two different codes would leak exactly what the identical message
	 * was hiding.
	 */
	public function test_someone_elses_record_is_indistinguishable_from_a_missing_one(): void {
		$missing = Conversion::refuse(
			$this->submission(),
			$this->asked( array( 'parent_id' => 'wrk_nothing' ) ),
			null
		);

		$elsewhere = Conversion::refuse(
			$this->submission(),
			$this->asked( array( 'parent_id' => 'wrk_theirs' ) ),
			$this->item( 'site_theirs' )
		);

		$this->assertSame( $missing, $elsewhere );
		$this->assertSame( Conversion::reason( $missing ), Conversion::reason( $elsewhere ) );
		$this->assertSame( Conversion::status( $missing ), Conversion::status( $elsewhere ) );
	}

	/**
	 * A parent on the submission's own site is allowed, which is the case all
	 * the refusals above have to leave working.
	 */
	public function test_a_parent_on_the_submissions_own_site_is_allowed(): void {
		$refused = Conversion::refuse(
			$this->submission(),
			$this->asked( array( 'parent_id' => 'wrk_ours' ) ),
			$this->item( 'site_ours' )
		);

		$this->assertSame( Conversion::ALLOWED, $refused );
	}

	/**
	 * A submission with no site on it matches nothing.
	 *
	 * The blank-equals-blank case, which is the one a rule written as a plain
	 * comparison gets wrong: an item with no site would otherwise be "on the
	 * same site" as a submission with no site, and both of those are rows
	 * something has already gone wrong with.
	 */
	public function test_a_submission_with_no_site_matches_nothing(): void {
		$refused = Conversion::refuse(
			$this->submission( array( 'client_site_id' => '' ) ),
			$this->asked( array( 'parent_id' => 'wrk_nowhere' ) ),
			$this->item( '' )
		);

		$this->assertSame( Conversion::UNKNOWN_PARENT, $refused );
	}

	/**
	 * Nothing a caller sends can name a client or a site.
	 *
	 * The read is the whole surface of what a conversion request may say, so
	 * asserting on its keys is asserting that no such parameter exists — which
	 * is a stronger claim than any check of one could be.
	 */
	public function test_a_conversion_request_carries_no_client_or_site(): void {
		$keys = array_keys(
			Conversion::read(
				array(
					'client_id'      => 'cli_theirs',
					'client_site_id' => 'site_theirs',
					'entry_stage'    => Stages::FIRST,
				)
			)
		);

		foreach ( $keys as $key ) {
			$this->assertStringNotContainsString( 'client', $key );
			$this->assertStringNotContainsString( 'site', $key );
		}
	}

	// ---- What the client asked for --------------------------------------

	/**
	 * The words the work is created with come from the request, so nothing is
	 * lost in becoming work.
	 */
	public function test_the_work_carries_the_clients_words_across(): void {
		$submission = $this->submission();
		$values     = Conversion::values( $submission, $this->asked() );

		$this->assertSame( $submission['title'], $values['title'] );
		$this->assertSame( $submission['description'], $values['problem'] );
	}

	/**
	 * And nothing conversion produces writes back over them. The values are the
	 * item's, and none of them is a column on the submission that a caller
	 * could reach through this route.
	 */
	public function test_conversion_writes_nothing_onto_the_request_itself(): void {
		$values = Conversion::values( $this->submission(), $this->asked() );

		foreach ( array( 'description', 'desired_outcome', 'evidence', 'submitted_by', 'intake_state', 'response' ) as $theirs ) {
			$this->assertArrayNotHasKey( $theirs, $values );
		}
	}

	/**
	 * A different title may be given to the work without touching the request.
	 * "Booking form" is a fine name for a card and a poor record of what
	 * somebody asked for; the two records are allowed to differ, which is why
	 * there are two of them.
	 */
	public function test_the_work_may_be_titled_differently_from_the_request(): void {
		$values = Conversion::values( $this->submission(), $this->asked( array( 'title' => 'Deposits at booking' ) ) );

		$this->assertSame( 'Deposits at booking', $values['title'] );
	}

	/**
	 * A parent created along the way takes none of the client's words.
	 *
	 * A parent is what other work hangs under. One client's paragraph at the
	 * head of it would sit above everything that ever joins it.
	 */
	public function test_a_created_parent_takes_none_of_the_clients_words(): void {
		$values = Conversion::parent_values( $this->asked( array( 'parent_title' => 'Bookings', 'parent_level' => Levels::PROJECT ) ) );

		$this->assertSame( 'Bookings', $values['title'] );
		$this->assertArrayNotHasKey( 'problem', $values );
	}

	// ---- Where it enters ------------------------------------------------

	/**
	 * Two entry stages, and they are the two the programme names.
	 */
	public function test_work_enters_at_future_idea_or_triage(): void {
		$this->assertTrue( Conversion::is_entry_stage( Stages::FIRST ) );
		$this->assertTrue( Conversion::is_entry_stage( 'triage' ) );
	}

	/**
	 * And nowhere else. A conversion that could name Up Next would be a way to
	 * skip four gates by choosing a dropdown value.
	 */
	public function test_no_other_stage_may_be_entered_at(): void {
		foreach ( Stages::ALL as $stage ) {
			if ( in_array( $stage, Conversion::ENTRY_STAGES, true ) ) {
				continue;
			}

			$this->assertSame(
				Conversion::BAD_ENTRY_STAGE,
				Conversion::refuse( $this->submission(), $this->asked( array( 'entry_stage' => $stage ) ) ),
				"{$stage} was accepted as an entry stage"
			);
		}
	}

	/**
	 * Future Idea when nothing says otherwise. The safe default is the stage
	 * everything else starts in.
	 */
	public function test_future_idea_is_the_default(): void {
		$this->assertSame( Stages::FIRST, Conversion::read( array() )['entry_stage'] );
	}

	// ---- Which requests may be converted --------------------------------

	/**
	 * A request becomes work once. Converting a second time would repoint what
	 * the client reads on their own site at different work, silently.
	 */
	public function test_a_request_is_converted_once(): void {
		$this->assertSame(
			Conversion::ALREADY_CONVERTED,
			Conversion::refuse( $this->submission( array( 'converted_item_id' => 'wrk_already' ) ), $this->asked() )
		);
	}

	/**
	 * A request we told the client we were not doing is not quietly made into
	 * work. Somebody who has changed their mind says so on the request first,
	 * where the client can read it.
	 */
	public function test_a_declined_request_is_not_converted(): void {
		$this->assertSame(
			Conversion::NOT_CONVERTIBLE,
			Conversion::refuse( $this->submission( array( 'intake_state' => 'declined' ) ), $this->asked() )
		);
	}

	/**
	 * A request still being read may be converted without a separate save.
	 * Triage is not always two sittings.
	 */
	public function test_a_request_still_being_read_may_be_converted(): void {
		foreach ( array( 'received', 'in-review', 'accepted' ) as $state ) {
			$this->assertSame(
				Conversion::ALLOWED,
				Conversion::refuse( $this->submission( array( 'intake_state' => $state ) ), $this->asked() ),
				"{$state} was refused"
			);
		}
	}

	// ---- The parent -----------------------------------------------------

	/**
	 * Work with no parent is ordinary, not incomplete.
	 */
	public function test_a_conversion_needs_no_parent(): void {
		$this->assertSame( Conversion::ALLOWED, Conversion::refuse( $this->submission(), $this->asked() ) );
	}

	/**
	 * Choosing a parent and creating one are alternatives, not both.
	 */
	public function test_choosing_and_creating_a_parent_are_alternatives(): void {
		$refused = Conversion::refuse(
			$this->submission(),
			$this->asked(
				array(
					'parent_id'    => 'wrk_ours',
					'parent_title' => 'Bookings',
					'parent_level' => Levels::PROJECT,
				)
			),
			$this->item( 'site_ours' )
		);

		$this->assertSame( Conversion::AMBIGUOUS_PARENT, $refused );
	}

	/**
	 * A new parent needs both a title and a level. Half a parent is a row with
	 * no name or no place in the hierarchy.
	 */
	public function test_a_new_parent_needs_a_title_and_a_level(): void {
		$this->assertSame(
			Conversion::PARENT_UNNAMED,
			Conversion::refuse( $this->submission(), $this->asked( array( 'parent_title' => 'Bookings' ) ) )
		);

		$this->assertSame(
			Conversion::PARENT_UNNAMED,
			Conversion::refuse( $this->submission(), $this->asked( array( 'parent_level' => Levels::PROJECT ) ) )
		);
	}

	/**
	 * A parent has to outrank the work beneath it, whether it is chosen or
	 * created. Equal levels are the case that makes a cycle, and a cycle in the
	 * hierarchy is a progress calculation that never returns.
	 */
	public function test_a_parent_must_outrank_the_work_beneath_it(): void {
		$this->assertSame(
			Conversion::BAD_PARENT_LEVEL,
			Conversion::refuse(
				$this->submission(),
				$this->asked( array( 'parent_id' => 'wrk_ours' ) ),
				$this->item( 'site_ours', Levels::SUB_FEATURE )
			)
		);

		$this->assertSame(
			Conversion::BAD_PARENT_LEVEL,
			Conversion::refuse(
				$this->submission(),
				$this->asked(
					array(
						'parent_title' => 'Another sub-feature',
						'parent_level' => Levels::SUB_FEATURE,
					)
				)
			)
		);
	}

	/**
	 * Every level above a sub-feature may parent one, skipped rungs included.
	 */
	public function test_any_higher_level_may_parent_converted_work(): void {
		foreach ( array( Levels::PROJECT, Levels::MILESTONE, Levels::FEATURE ) as $level ) {
			$this->assertSame(
				Conversion::ALLOWED,
				Conversion::refuse(
					$this->submission(),
					$this->asked(
						array(
							'parent_title' => 'Somewhere to put it',
							'parent_level' => $level,
						)
					)
				),
				"{$level} was refused as a parent"
			);
		}
	}

	// ---- Linking rather than creating -----------------------------------

	/**
	 * Linking takes the work as it stands. A parent sent alongside would
	 * re-parent somebody else's item as a side effect of answering a request.
	 */
	public function test_linking_refuses_a_parent_sent_with_it(): void {
		$refused = Conversion::refuse(
			$this->submission(),
			$this->asked(
				array(
					'item_id'   => 'wrk_ours',
					'parent_id' => 'wrk_parent',
				)
			),
			$this->item( 'site_ours' ),
			$this->item( 'site_ours' )
		);

		$this->assertSame( Conversion::AMBIGUOUS_TARGET, $refused );
	}

	/**
	 * Linking to work on this client's own site is the ordinary case: two
	 * clients asking for the same thing, one piece of work.
	 */
	public function test_linking_to_this_clients_own_work_is_allowed(): void {
		$refused = Conversion::refuse(
			$this->submission(),
			$this->asked( array( 'item_id' => 'wrk_ours' ) ),
			null,
			$this->item( 'site_ours' )
		);

		$this->assertSame( Conversion::ALLOWED, $refused );
	}

	// ---- What kind of work ----------------------------------------------

	/**
	 * A task when nobody says otherwise. The three things a client may send
	 * describe how firmly they are asking, not what the job is, and mapping one
	 * onto the other would be the studio guessing.
	 */
	public function test_converted_work_is_a_task_unless_told_otherwise(): void {
		$this->assertSame( Types::TASK, Conversion::values( $this->submission(), $this->asked() )['work_type'] );

		$this->assertSame(
			Types::TASK,
			Conversion::values( $this->submission(), $this->asked( array( 'work_type' => 'suggestion' ) ) )['work_type']
		);
	}

	/**
	 * And whatever the converting person chose, when it is a real work type.
	 */
	public function test_the_work_type_may_be_chosen(): void {
		$this->assertSame(
			Types::BUG,
			Conversion::values( $this->submission(), $this->asked( array( 'work_type' => Types::BUG ) ) )['work_type']
		);
	}

	/**
	 * Converted work is a Sub-feature: the rung work actually gets done on.
	 */
	public function test_converted_work_is_a_sub_feature(): void {
		$this->assertSame( Levels::SUB_FEATURE, Conversion::values( $this->submission(), $this->asked() )['level'] );
	}

	// ---- How refusals read ----------------------------------------------

	/**
	 * Every refusal has a sentence, so none of them reaches a person as a code.
	 */
	public function test_every_refusal_has_words(): void {
		$codes = array(
			Conversion::ALREADY_CONVERTED,
			Conversion::NOT_CONVERTIBLE,
			Conversion::BAD_ENTRY_STAGE,
			Conversion::AMBIGUOUS_TARGET,
			Conversion::AMBIGUOUS_PARENT,
			Conversion::PARENT_UNNAMED,
			Conversion::BAD_PARENT_LEVEL,
			Conversion::UNKNOWN_PARENT,
			Conversion::UNKNOWN_TARGET,
		);

		foreach ( $codes as $code ) {
			$this->assertNotSame( '', trim( Conversion::reason( $code ) ), "{$code} has no message" );
			$this->assertNotSame( $code, Conversion::reason( $code ), "{$code} reads as its own code" );
		}
	}

	/**
	 * A record that is not there answers 404, and a request that was wrong
	 * answers 409. The first is the answer an unused id already gets, which is
	 * how "not yours" stays indistinguishable from "not there".
	 */
	public function test_a_hidden_record_answers_as_a_missing_one(): void {
		$this->assertSame( 404, Conversion::status( Conversion::UNKNOWN_PARENT ) );
		$this->assertSame( 404, Conversion::status( Conversion::UNKNOWN_TARGET ) );
		$this->assertSame( 409, Conversion::status( Conversion::BAD_ENTRY_STAGE ) );
		$this->assertSame( 409, Conversion::status( Conversion::ALREADY_CONVERTED ) );
	}
}
