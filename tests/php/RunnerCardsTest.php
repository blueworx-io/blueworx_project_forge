<?php
/**
 * What the runner key reaches on a card, and what it may change.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\Runner;
use PHPUnit\Framework\TestCase;

/**
 * The runner reaches the cards the Agent is doing and nothing else, sees a
 * few fields of each, makes two moves, and on hand-over writes four things.
 * Every one of those limits is a decision, so each is a test.
 */
final class RunnerCardsTest extends TestCase {

	private const AGENT = 'usr_agent';

	/**
	 * A card the Agent is doing.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 * @return array<string, mixed>
	 */
	private function card( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                       => 'wrk_1',
				'client_site_id'           => 'cs_1',
				'client_id'                => 'cl_1',
				'parent_id'                => 'wrk_parent',
				'title'                    => 'Build the thing',
				'level'                    => 'sub-feature',
				'work_type'                => 'feature',
				'stage'                    => 'in-development',
				'priority'                 => 'normal',
				'problem'                  => '<p>It is broken.</p>',
				'scope'                    => 'All of it.',
				'non_goals'                => 'None of that.',
				'requirements'             => 'It works.',
				'acceptance_criteria'      => '<p>It works.</p>',
				'references'               => 'See the doc.',
				'design_url'               => 'https://example.test/design',
				'test_description'         => '',
				'test_steps'               => array(),
				'checklist'                => array(
					array(
						'text' => 'First',
						'done' => false,
					),
					array(
						'text' => 'Second',
						'done' => false,
					),
				),
				'links'                    => array(),
				'github_repo'              => 'blueworx-io/thing',
				'primary_user_id'          => self::AGENT,
				'designer_id'              => '',
				'reviewer_id'              => 'usr_rev',
				'deliverer_id'             => 'usr_del',
				'reviewer_substitute_id'   => '',
				'deliverer_substitute_id'  => '',
				'hours_primary'            => 3.0,
				'hours_review'             => 1.0,
				'hours_delivery'           => 1.0,
				'hours_designer'           => 0.0,
				'planned_start'            => '2026-11-23',
				'planned_due'              => '2026-11-27',
				'review_target'            => '2026-11-30',
				'release_target'           => '2026-12-01',
				'design_target'            => '',
				'archived'                 => false,
				'commercial_class'         => 'chargeable',
				'capacity_override_reason' => 'secret reason',
				'override_reason'          => 'another secret',
				'record_version'           => 4,
			),
			$overrides
		);
	}

	// ---- Which cards -----------------------------------------------------

	/**
	 * The Agent doing the work, not archived: reached.
	 */
	public function test_a_card_the_agent_is_doing_is_reached(): void {
		$this->assertTrue( Runner::reaches( $this->card(), array( self::AGENT ) ) );
	}

	/**
	 * Somebody else doing the work: not reached, whatever other seat the Agent
	 * holds.
	 */
	public function test_a_card_somebody_else_is_doing_is_not_reached(): void {
		$this->assertFalse( Runner::reaches( $this->card( array( 'primary_user_id' => 'usr_person' ) ), array( self::AGENT ) ) );
		$this->assertFalse( Runner::reaches( $this->card( array( 'primary_user_id' => 'usr_person', 'reviewer_id' => self::AGENT ) ), array( self::AGENT ) ) );
		$this->assertFalse( Runner::reaches( $this->card( array( 'primary_user_id' => 'usr_person', 'designer_id' => self::AGENT ) ), array( self::AGENT ) ) );
	}

	/**
	 * An archived card is not reached, even the Agent's.
	 */
	public function test_an_archived_card_is_not_reached(): void {
		$this->assertFalse( Runner::reaches( $this->card( array( 'archived' => true ) ), array( self::AGENT ) ) );
	}

	/**
	 * Nobody in the seat, and no agent on the site: never reached.
	 */
	public function test_an_empty_seat_is_never_the_agent(): void {
		$this->assertFalse( Runner::reaches( $this->card( array( 'primary_user_id' => '' ) ), array( self::AGENT ) ) );
		$this->assertFalse( Runner::reaches( $this->card( array( 'primary_user_id' => '' ) ), array( '' ) ) );
		$this->assertFalse( Runner::reaches( $this->card(), array() ) );
	}

	// ---- Which stages and which moves ------------------------------------

	/**
	 * The list is asked for one of three stages, Up Next by default.
	 */
	public function test_the_list_reads_three_stages_only(): void {
		$this->assertSame( 'up-next', Runner::stage( '' ) );
		$this->assertSame( 'in-development', Runner::stage( 'in-development' ) );
		$this->assertSame( 'in-review', Runner::stage( 'in-review' ) );
		$this->assertNull( Runner::stage( 'released' ) );
		$this->assertNull( Runner::stage( 'future-idea' ) );
		$this->assertNull( Runner::stage( "up-next' OR 1=1" ) );
	}

	/**
	 * Two moves and only two.
	 */
	public function test_the_runner_makes_two_moves_and_no_others(): void {
		$this->assertSame(
			array(
				'claim'     => array( 'up-next', 'in-development' ),
				'hand-over' => array( 'in-development', 'in-review' ),
			),
			Runner::MOVES
		);

		$this->assertSame( 'in-development', Runner::next_move( 'up-next' ) );
		$this->assertSame( 'in-review', Runner::next_move( 'in-development' ) );
		$this->assertNull( Runner::next_move( 'in-review' ) );
		$this->assertNull( Runner::next_move( 'completed' ) );
		$this->assertNull( Runner::next_move( 'blocked' ) );
	}

	// ---- What it sees ----------------------------------------------------

	/**
	 * The list has the few fields the runner chooses work by, and nothing about
	 * money, people or anybody else's card.
	 */
	public function test_the_list_is_light(): void {
		$light = Runner::light( $this->card() );

		$this->assertSame(
			array( 'id', 'title', 'stage', 'level', 'work_type', 'priority', 'github_repo', 'design_url', 'planned_start', 'planned_due', 'review_target', 'release_target', 'hours_primary', 'has_acceptance_criteria', 'record_version' ),
			array_keys( $light )
		);
		$this->assertTrue( $light['has_acceptance_criteria'] );
		$this->assertFalse( Runner::light( $this->card( array( 'acceptance_criteria' => '<p></p>' ) ) )['has_acceptance_criteria'] );
	}

	/**
	 * The card in full is its own definition, and leaves out everything that
	 * names another card or a reason somebody gave.
	 */
	public function test_the_definition_is_the_cards_own(): void {
		$definition = Runner::definition( $this->card() );

		foreach ( array( 'problem', 'scope', 'non_goals', 'requirements', 'acceptance_criteria', 'references', 'design_url', 'test_description', 'test_steps', 'checklist', 'links', 'github_repo', 'primary_user_id', 'reviewer_id', 'deliverer_id', 'hours_primary', 'planned_due', 'stage', 'record_version' ) as $field ) {
			$this->assertArrayHasKey( $field, $definition, $field );
		}

		foreach ( array( 'parent_id', 'client_id', 'client_site_id', 'commercial_class', 'capacity_override_reason', 'override_reason', 'archived' ) as $field ) {
			$this->assertArrayNotHasKey( $field, $definition, $field );
		}
	}

	/**
	 * What a gate says is unmet, in Forge's own words, and nothing else: not
	 * who else is over-booked, not the client's support balance.
	 */
	public function test_readiness_is_only_forges_words(): void {
		$view = Runner::readiness_view(
			array(
				'unmet'  => array(
					array(
						'id'           => 'G-UP-NEXT-8',
						'label'        => 'Capacity check',
						'satisfied_by' => 'Nobody in a seat may be over-booked.',
						'by'           => 'system',
						'over'         => array( array( 'display_name' => 'Somebody' ) ),
					),
					array(
						'id'           => 'G-UP-NEXT-9',
						'label'        => 'Support-hours check',
						'satisfied_by' => 'The site has to be on a package.',
						'hours'        => array( 'balance' => 12 ),
					),
				),
				'checks' => array(
					array(
						'id'     => 'G-UP-NEXT-8',
						'label'  => 'Capacity check',
						'result' => 'fail',
						'note'   => '',
					),
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'id'           => 'G-UP-NEXT-8',
					'label'        => 'Capacity check',
					'satisfied_by' => 'Nobody in a seat may be over-booked.',
				),
				array(
					'id'           => 'G-UP-NEXT-9',
					'label'        => 'Support-hours check',
					'satisfied_by' => 'The site has to be on a package.',
				),
			),
			$view['unmet']
		);
		$this->assertSame(
			array(
				array(
					'id'     => 'G-UP-NEXT-8',
					'label'  => 'Capacity check',
					'result' => 'fail',
				),
			),
			$view['checks']
		);
		$this->assertFalse( $view['ready'] );
	}

	/**
	 * A refusal from the workflow keeps what it says and drops anything that
	 * could carry another record: the stale card, the client's balance.
	 */
	public function test_a_refusal_carries_nothing_extra(): void {
		$scrubbed = Runner::scrub(
			new WP_Error(
				'bwx_forge_hours_not_available',
				'That move needs more support hours than the site has left.',
				array(
					'status'          => 409,
					'balance'         => 3,
					'needed'          => 4,
					'current'         => $this->card(),
					'from'            => 'up-next',
					'attempted'       => 'in-development',
					'available'       => array( 'in-development' ),
					'current_version' => 5,
				)
			)
		);

		$this->assertSame( 'bwx_forge_hours_not_available', $scrubbed->get_error_code() );
		$this->assertSame( 'That move needs more support hours than the site has left.', $scrubbed->get_error_message() );
		$this->assertSame(
			array(
				'status'          => 409,
				'from'            => 'up-next',
				'attempted'       => 'in-development',
				'available'       => array( 'in-development' ),
				'current_version' => 5,
			),
			$scrubbed->get_error_data()
		);
	}

	// ---- What hand-over may write ----------------------------------------

	/**
	 * The four things hand-over takes, checked as Forge checks any edit.
	 */
	public function test_hand_over_takes_hours_and_how_to_test_it(): void {
		$checked = Runner::handover(
			array(
				'hours_primary'    => 2.5,
				'test_description' => '<p>Open it and look.</p>',
				'test_steps'       => array( 'Open it', array( 'text' => 'Look' ) ),
				'tick'             => array( 1 ),
				'record_version'   => 4,
			),
			$this->card()
		);

		$this->assertSame( array(), $checked['errors'] );
		$this->assertSame( 2.5, $checked['values']['hours_primary'] );
		$this->assertSame( '<p>Open it and look.</p>', $checked['values']['test_description'] );
		$this->assertSame(
			array(
				array(
					'text' => 'Open it',
					'done' => false,
				),
				array(
					'text' => 'Look',
					'done' => false,
				),
			),
			json_decode( $checked['values']['test_steps'], true )
		);
		$this->assertSame(
			array(
				array(
					'text' => 'First',
					'done' => false,
				),
				array(
					'text' => 'Second',
					'done' => true,
				),
			),
			json_decode( $checked['values']['checklist'], true ),
			'a tick sets that line done and keeps its words'
		);
		$this->assertSame( array( 'hours_primary', 'test_description', 'test_steps', 'checklist' ), array_keys( $checked['values'] ) );
	}

	/**
	 * Without test steps or ticks, nothing is written to either.
	 */
	public function test_steps_and_ticks_are_optional(): void {
		$checked = Runner::handover(
			array(
				'hours_primary'    => 4,
				'test_description' => 'Open it.',
			),
			$this->card()
		);

		$this->assertSame( array(), $checked['errors'] );
		$this->assertSame( array( 'hours_primary', 'test_description' ), array_keys( $checked['values'] ) );
	}

	/**
	 * The hours and how to test it have to be there.
	 */
	public function test_hours_and_how_to_test_it_are_required(): void {
		$checked = Runner::handover( array(), $this->card() );

		$this->assertArrayHasKey( 'hours_primary', $checked['errors'] );
		$this->assertArrayHasKey( 'test_description', $checked['errors'] );

		$blank = Runner::handover(
			array(
				'hours_primary'    => 'lots',
				'test_description' => '   ',
			),
			$this->card()
		);

		$this->assertArrayHasKey( 'hours_primary', $blank['errors'] );
		$this->assertArrayHasKey( 'test_description', $blank['errors'] );
		$this->assertArrayHasKey( 'hours_primary', Runner::handover( array( 'hours_primary' => -1, 'test_description' => 'x' ), $this->card() )['errors'] );
	}

	/**
	 * Anything else on the card is refused by name, never quietly dropped:
	 * the seats, the stage, the title, the dates, the checklist's own words.
	 */
	public function test_hand_over_refuses_every_other_field(): void {
		foreach ( array( 'title', 'stage', 'primary_user_id', 'reviewer_id', 'deliverer_id', 'hours_review', 'planned_due', 'priority', 'checklist', 'acceptance_criteria', 'commercial_class', 'capacity_reason', 'github_repo', 'parent_id', 'self_review_permitted' ) as $field ) {
			$checked = Runner::handover(
				array(
					'hours_primary'    => 1,
					'test_description' => 'x',
					$field             => 'anything',
				),
				$this->card()
			);

			$this->assertArrayHasKey( $field, $checked['errors'], $field );
		}
	}

	/**
	 * A tick names a line that is there.
	 */
	public function test_a_tick_names_a_real_line(): void {
		foreach ( array( array( 2 ), array( -1 ), array( 'first' ), 'all', array( 1.5 ) ) as $tick ) {
			$checked = Runner::handover(
				array(
					'hours_primary'    => 1,
					'test_description' => 'x',
					'tick'             => $tick,
				),
				$this->card()
			);

			$this->assertArrayHasKey( 'tick', $checked['errors'], (string) wp_json_encode( $tick ) );
		}
	}

	/**
	 * Test steps are held to the same rules as anybody's.
	 */
	public function test_steps_follow_the_checklist_rules(): void {
		$checked = Runner::handover(
			array(
				'hours_primary'    => 1,
				'test_description' => 'x',
				'test_steps'       => array_fill( 0, 11, 'A step' ),
			),
			$this->card()
		);

		$this->assertArrayHasKey( 'test_steps', $checked['errors'] );
	}

	/**
	 * The card as it would be after hand-over, for the gate to be asked
	 * before anything is written.
	 */
	public function test_the_card_as_it_would_be(): void {
		$checked = Runner::handover(
			array(
				'hours_primary'    => 2,
				'test_description' => 'Open it.',
				'tick'             => array( 0, 1 ),
			),
			$this->card()
		);

		$after = Runner::after( $this->card(), $checked['values'] );

		$this->assertSame( 2.0, $after['hours_primary'] );
		$this->assertSame( 'Open it.', $after['test_description'] );
		$this->assertTrue( $after['checklist'][0]['done'] );
		$this->assertTrue( $after['checklist'][1]['done'] );
		$this->assertSame( 'Build the thing', $after['title'] );
	}

	// ---- Comments --------------------------------------------------------

	/**
	 * A comment is words and nothing else.
	 */
	public function test_a_comment_is_words_only(): void {
		$this->assertSame( array(), Runner::comment( array( 'body' => 'Done the first half.' ) )['errors'] );
		$this->assertSame( 'Done the first half.', Runner::comment( array( 'body' => '  Done the first half.  ' ) )['body'] );
		$this->assertArrayHasKey( 'body', Runner::comment( array( 'body' => '   ' ) )['errors'] );
		$this->assertArrayHasKey( 'body', Runner::comment( array( 'body' => str_repeat( 'a', 5001 ) ) )['errors'] );

		foreach ( array( 'visibility', 'kind', 'url', 'answers', 'author', 'author_name' ) as $field ) {
			$this->assertArrayHasKey( $field, Runner::comment( array( 'body' => 'x', $field => 'client' ) )['errors'], $field );
		}
	}
}
