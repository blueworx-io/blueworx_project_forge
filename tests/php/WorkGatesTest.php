<?php
/**
 * The exit gates, as structured records.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\Gates;
use Blueworx\Forge\Work\Stages;
use Blueworx\Forge\Work\Transitions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #105 and #107, pinned against docs/architecture/workflow-state-machine.md.
 *
 * The test that matters most is the last one: a refusal names every unmet
 * requirement. A gate that reports one thing at a time is a gate somebody
 * fights their way through one round trip per requirement.
 */
final class WorkGatesTest extends TestCase {

	/**
	 * An item with nothing filled in.
	 *
	 * @param array<string, mixed> $over Anything to set on it.
	 * @return array<string, mixed>
	 */
	private function item( array $over = array() ): array {
		return array_merge(
			array(
				'id'                  => 'wrk_1',
				'stage'               => 'up-next',
				'work_type'           => 'feature',
				'title'               => '',
				'problem'             => '',
				'scope'               => '',
				'non_goals'           => '',
				'requirements'        => '',
				'acceptance_criteria' => '',
				'references'          => '',
				'client_site_id'      => 'cst_1',
				'commercial_class'    => 'unclassified',
				'priority'            => '',
				'planned_start'       => '',
				'planned_due'         => '',
				'test_description'    => '',
				'design_url'          => '',
				'release_method'      => '',
				'release_destination' => '',
				'created_by'          => 7,
				'cycle'               => 1,
				'review_attempt'      => 1,
			),
			$over
		);
	}

	/**
	 * Every gate the transition table names.
	 *
	 * @return array<string, array{string}>
	 */
	public static function gate_names(): array {
		$named = array( Transitions::CREATE_GATE, 'G-BLOCKED-ENTRY', 'G-BLOCKED-EXIT' );

		foreach ( Stages::ALL as $from ) {
			foreach ( Stages::ALL as $to ) {
				$gate = Transitions::gate_for( $from, $to );

				if ( '' !== $gate ) {
					$named[] = $gate;
				}
			}
		}

		$cases = array();

		foreach ( array_unique( $named ) as $gate ) {
			$cases[ $gate ] = array( $gate );
		}

		return $cases;
	}

	/**
	 * Every gate a move names is actually defined. This is the check that stops
	 * a transition being added with a gate nobody wrote.
	 *
	 * @param string $gate Gate name.
	 */
	#[DataProvider( 'gate_names' )]
	public function test_every_named_gate_is_defined( string $gate ): void {
		$this->assertTrue( Gates::exists( $gate ), "{$gate} is named by a move but has no definition" );
		$this->assertNotSame( array(), Gates::requirements( $gate ), "{$gate} has no requirements" );
	}

	/**
	 * Every requirement is a structured record, not a sentence. Each names how
	 * it is satisfied, who may satisfy it, and what to do about it.
	 */
	public function test_every_requirement_is_structured(): void {
		foreach ( Gates::all() as $gate => $requirements ) {
			foreach ( $requirements as $requirement ) {
				foreach ( array( 'id', 'label', 'type', 'evidence', 'who', 'satisfied_by', 'by' ) as $key ) {
					$this->assertArrayHasKey( $key, $requirement, "{$gate} requirement missing {$key}" );
				}

				$this->assertNotSame( '', $requirement['label'] );
				$this->assertNotSame( '', $requirement['satisfied_by'] );
				$this->assertContains( $requirement['by'], array( Gates::BY_FIELD, Gates::BY_RECORD, Gates::BY_SYSTEM, Gates::BY_AUTO ) );
				$this->assertStringStartsWith( $gate, (string) $requirement['id'] );

				if ( Gates::BY_FIELD === $requirement['by'] ) {
					$this->assertNotSame( array(), $requirement['fields'] );
				}
			}
		}
	}

	/**
	 * Requirement ids are unique across every gate. They are what a failure
	 * response and the UI key on, so two of one id is two things claiming to be
	 * the same requirement.
	 */
	public function test_requirement_ids_are_unique(): void {
		$seen = array();

		foreach ( Gates::all() as $requirements ) {
			foreach ( $requirements as $requirement ) {
				$this->assertNotContains( $requirement['id'], $seen );
				$seen[] = $requirement['id'];
			}
		}
	}

	/**
	 * #107's acceptance, precisely. An item with three unmet requirements is
	 * told about all three.
	 */
	public function test_a_refusal_names_every_unmet_requirement(): void {
		$item = $this->item(
			array(
				'stage'    => 'documentation-period',
				'problem'  => 'Something is wrong.',
				// non_goals, acceptance_criteria and references are all empty,
				// and the records are missing.
			)
		);

		$result = Gates::evaluate( 'G-DOCUMENTATION', $item, array() );
		$ids    = array_column( $result['unmet'], 'id' );

		$this->assertContains( 'G-DOCUMENTATION-9', $ids );
		// Not covered and Completed when are no longer asked (#493, #494).
		$this->assertNull( Gates::requirement( 'G-DOCUMENTATION-3' ) );
		$this->assertNull( Gates::requirement( 'G-DOCUMENTATION-5' ) );
		// Reference material and dependencies are optional (2026-09-19).
		$this->assertNull( Gates::requirement( 'G-DOCUMENTATION-8' ) );
		$this->assertNull( Gates::requirement( 'G-DOCUMENTATION-6' ) );
		// Affected sites and data is not asked either (#545): the description and the approval are the gate.
		$this->assertNull( Gates::requirement( 'G-DOCUMENTATION-7' ) );
		$this->assertSame( array( 'G-DOCUMENTATION-1', 'G-DOCUMENTATION-9' ), array_column( Gates::requirements( 'G-DOCUMENTATION' ), 'id' ) );
		$this->assertNotContains( 'G-DOCUMENTATION-1', $ids, 'The problem statement was filled in' );

		// The Scope and Requirements checks went with their boxes (2026-09-18).
		$this->assertNotContains( 'G-DOCUMENTATION-2', $ids );
		$this->assertNotContains( 'G-DOCUMENTATION-4', $ids );
		$this->assertNull( Gates::requirement( 'G-TRIAGE-5' ) );
	}

	/**
	 * Every requirement comes back, met or not, so a screen can draw the whole
	 * gate rather than only what is left of it.
	 */
	public function test_the_evaluation_lists_every_requirement_with_whether_it_is_met(): void {
		$result = Gates::evaluate( 'G-UP-NEXT', $this->item( array( 'priority' => 'high' ) ), array() );
		$all    = array_column( $result['all'], null, 'id' );

		$this->assertTrue( $all['G-UP-NEXT-6']['met'] );
		$this->assertFalse( $all['G-UP-NEXT-5']['met'] );
		$this->assertArrayNotHasKey( 'G-UP-NEXT-8', $all, 'System checks are reported as checks, not rows' );
	}

	/**
	 * Leaving In Development asks for no work evidence (2026-09-24): comments
	 * are for comments, not proof.
	 */
	public function test_in_development_asks_for_no_work_evidence(): void {
		$item    = $this->item( array( 'stage' => 'in-development', 'test_description' => 'Open it.' ) );
		$without = Gates::evaluate( 'G-IN-DEVELOPMENT', $item, array() );

		$this->assertSame( array(), $without['unmet'], 'How to test is written, the checklist is empty: nothing left' );
	}

	/**
	 * #550. How to test it has to be written before review. An empty
	 * paragraph is empty, and the row is the doer's.
	 */
	public function test_in_development_needs_how_to_test_it(): void {
		$empty = $this->item( array( 'stage' => 'in-development', 'test_description' => '<p></p>' ) );
		$ids   = array_column( Gates::evaluate( 'G-IN-DEVELOPMENT', $empty, array() )['unmet'], 'id' );

		$this->assertSame( array( 'G-IN-DEVELOPMENT-2' ), $ids );
		$this->assertSame( Gates::PU, Gates::requirement( 'G-IN-DEVELOPMENT-2' )['who'] );
		$this->assertSame( array( 'test_description' ), Gates::requirement( 'G-IN-DEVELOPMENT-2' )['fields'] );
	}

	/**
	 * #547. Leaving Up Next wants every person's date: Deliver by, Review by,
	 * Release by, and Design by only when somebody designs.
	 */
	public function test_up_next_needs_every_date_and_design_by_only_with_a_designer(): void {
		$dated = array(
			'planned_due'    => '2026-10-20',
			'review_target'  => '2026-10-21',
			'release_target' => '2026-10-22',
		);

		$undated = array_column( Gates::evaluate( 'G-UP-NEXT', $this->item( array( 'planned_due' => '2026-10-20' ) ), array() )['unmet'], 'id' );
		$this->assertContains( 'G-UP-NEXT-10', $undated );
		$this->assertContains( 'G-UP-NEXT-11', $undated );
		$this->assertNotContains( 'G-UP-NEXT-12', $undated, 'No designer, no Design by' );

		$plain = array_column( Gates::evaluate( 'G-UP-NEXT', $this->item( $dated ), array() )['all'], 'id' );
		$this->assertNotContains( 'G-UP-NEXT-12', $plain, 'The row is not even shown without a designer' );

		$designed = array_column( Gates::evaluate( 'G-UP-NEXT', $this->item( $dated + array( 'designer_id' => 'usr_d' ) ), array() )['unmet'], 'id' );
		$this->assertContains( 'G-UP-NEXT-12', $designed );
		$this->assertNotContains( 'G-UP-NEXT-10', $designed );
		$this->assertNotContains( 'G-UP-NEXT-11', $designed );

		$complete = array_column( Gates::evaluate( 'G-UP-NEXT', $this->item( $dated + array( 'designer_id' => 'usr_d', 'design_target' => '2026-10-19' ) ), array() )['unmet'], 'id' );
		$this->assertNotContains( 'G-UP-NEXT-12', $complete );
	}

	/**
	 * #562. A project takes its dates from its releases, so leaving Up Next
	 * asks it for none. A feature with no dates is still held.
	 */
	public function test_a_project_leaves_up_next_without_dates(): void {
		$project = Gates::evaluate( 'G-UP-NEXT', $this->item( array( 'level' => 'project', 'designer_id' => 'usr_d' ) ), array() );
		$ids     = array_column( $project['all'], 'id' );

		foreach ( array( 'G-UP-NEXT-5', 'G-UP-NEXT-10', 'G-UP-NEXT-11', 'G-UP-NEXT-12' ) as $id ) {
			$this->assertNotContains( $id, $ids, "{$id} should not be asked of a project" );
		}

		$this->assertContains( 'G-UP-NEXT-1', $ids, 'A project still needs its seats' );

		$feature = array_column( Gates::evaluate( 'G-UP-NEXT', $this->item( array( 'level' => 'feature' ) ), array() )['unmet'], 'id' );

		foreach ( array( 'G-UP-NEXT-5', 'G-UP-NEXT-10', 'G-UP-NEXT-11' ) as $id ) {
			$this->assertContains( $id, $feature );
		}
	}

	/**
	 * Leaving In Development (2026-09-19, 2026-09-24): the test steps are
	 * optional, hours still to do is gone, and the completion checklist is the
	 * checklist itself.
	 */
	public function test_in_development_asks_nothing_about_test_steps_or_hours(): void {
		$ids = array_column( Gates::requirements( 'G-IN-DEVELOPMENT' ), 'id' );

		$this->assertNotContains( 'G-IN-DEVELOPMENT-3', $ids );
		$this->assertNotContains( 'G-IN-DEVELOPMENT-4', $ids );
		$this->assertNotContains( 'G-IN-DEVELOPMENT-5', $ids );
	}

	/**
	 * The approvals belong to the reviewer (2026-09-19).
	 */
	public function test_the_approvals_are_the_reviewers(): void {
		foreach ( array( 'G-DOCUMENTATION-9', 'G-DESIGN-5' ) as $id ) {
			$this->assertSame( Gates::REV, Gates::requirement( $id )['who'], $id );
		}
	}

	/**
	 * Captured to Triage wants the two people (2026-09-19); the write-ins at
	 * Technical Audit (the whole stage, #546), the parent question and the accessibility tick are gone.
	 */
	public function test_what_went_and_what_arrived(): void {
		$idea = array_column( Gates::requirements( 'G-FUTURE-IDEA' ), 'id' );

		$this->assertContains( 'G-FUTURE-IDEA-5', $idea );
		$this->assertContains( 'G-FUTURE-IDEA-6', $idea );
		$this->assertSame( array( 'primary_user_id' ), Gates::requirement( 'G-FUTURE-IDEA-5' )['fields'] );
		$this->assertSame( array( 'reviewer_id' ), Gates::requirement( 'G-FUTURE-IDEA-6' )['fields'] );

		foreach ( array( 'G-TRIAGE-3', 'G-TECHNICAL-AUDIT-8', 'G-TECHNICAL-AUDIT-1', 'G-TECHNICAL-AUDIT-2', 'G-TECHNICAL-AUDIT-3', 'G-TECHNICAL-AUDIT-4', 'G-TECHNICAL-AUDIT-5', 'G-TECHNICAL-AUDIT-6', 'G-DESIGN-4', 'G-UP-NEXT-7' ) as $gone ) {
			$this->assertNull( Gates::requirement( $gone ), $gone );
		}

		// Not every item has a design, so the link is optional (2026-09-24).
		$this->assertNull( Gates::requirement( 'G-DESIGN-1' ) );
	}

	/**
	 * A new idea's client is confirmed before triage (#390): an unconfirmed
	 * item, whose stamp is still the column's 0, does not meet it.
	 */
	public function test_triage_waits_for_the_client_to_be_confirmed(): void {
		$rule = Gates::requirement( 'G-FUTURE-IDEA-7' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'G-FUTURE-IDEA', Gates::gate_of( 'G-FUTURE-IDEA-7' ) );
		$this->assertSame( array( 'client_confirmed_at' ), $rule['fields'] );
		$this->assertFalse( Gates::satisfied( $rule, array( 'client_confirmed_at' => 0 ), array() ) );
		$this->assertFalse( Gates::satisfied( $rule, array(), array() ) );
		$this->assertTrue( Gates::satisfied( $rule, array( 'client_confirmed_at' => 1790000000 ), array() ) );
	}

	/**
	 * The hours item is met by the three seats' hours, and by nothing else.
	 */
	public function test_planned_hours_are_met_by_the_three_seat_hours(): void {
		$two   = Gates::evaluate( 'G-UP-NEXT', $this->item( array( 'hours_primary' => 2.0, 'hours_review' => 1.0 ) ), array() );
		$three = Gates::evaluate( 'G-UP-NEXT', $this->item( array( 'hours_primary' => 2.0, 'hours_review' => 1.0, 'hours_delivery' => 0.5 ) ), array() );

		$this->assertContains( 'G-UP-NEXT-4', array_column( $two['unmet'], 'id' ) );
		$this->assertNotContains( 'G-UP-NEXT-4', array_column( $three['unmet'], 'id' ) );
	}

	/**
	 * #421. A designer's hours are asked for only when there is a designer: with
	 * no design required, there is no design time to plan.
	 */
	public function test_designer_hours_are_wanted_only_with_a_designer(): void {
		$hours    = array(
			'hours_primary'  => 2.0,
			'hours_review'   => 1.0,
			'hours_delivery' => 0.5,
		);
		$none     = Gates::evaluate( 'G-UP-NEXT', $this->item( $hours ), array() );
		$unplaned = Gates::evaluate( 'G-UP-NEXT', $this->item( $hours + array( 'designer_id' => 'usr_d' ) ), array() );
		$planned  = Gates::evaluate( 'G-UP-NEXT', $this->item( $hours + array( 'designer_id' => 'usr_d', 'hours_designer' => 1.0 ) ), array() );

		$this->assertNotContains( 'G-UP-NEXT-4', array_column( $none['unmet'], 'id' ) );
		$this->assertContains( 'G-UP-NEXT-4', array_column( $unplaned['unmet'], 'id' ) );
		$this->assertNotContains( 'G-UP-NEXT-4', array_column( $planned['unmet'], 'id' ) );
	}

	/**
	 * A checklist all ticked confirms the requirements implemented without a
	 * record; a checklist with a line open does not.
	 */
	public function test_a_ticked_checklist_confirms_the_requirements(): void {
		$open = $this->item( array( 'checklist' => array( array( 'text' => 'A', 'done' => true ), array( 'text' => 'B', 'done' => false ) ) ) );
		$done = $this->item( array( 'checklist' => array( array( 'text' => 'A', 'done' => true ), array( 'text' => 'B', 'done' => true ) ) ) );

		$this->assertContains( 'G-IN-DEVELOPMENT-1', array_column( Gates::evaluate( 'G-IN-DEVELOPMENT', $open, array() )['unmet'], 'id' ) );
		$this->assertNotContains( 'G-IN-DEVELOPMENT-1', array_column( Gates::evaluate( 'G-IN-DEVELOPMENT', $done, array() )['unmet'], 'id' ) );
	}

	/**
	 * Dependencies are ready when every one of them is Completed or later —
	 * and trivially when there are none.
	 */
	public function test_dependencies_are_ready_when_all_are_completed(): void {
		$item = $this->item( array( 'stage' => 'completed' ) );

		$open  = Gates::evaluate( 'G-COMPLETED', $item, array(), array( 'dependencies' => array( 'in-review', 'released' ) ) );
		$done  = Gates::evaluate( 'G-COMPLETED', $item, array(), array( 'dependencies' => array( 'completed', 'released' ) ) );
		$alone = Gates::evaluate( 'G-COMPLETED', $item, array(), array( 'dependencies' => array() ) );

		$this->assertContains( 'G-COMPLETED-6', array_column( $open['unmet'], 'id' ) );
		$this->assertNotContains( 'G-COMPLETED-6', array_column( $done['unmet'], 'id' ) );
		$this->assertNotContains( 'G-COMPLETED-6', array_column( $alone['unmet'], 'id' ) );
	}

	/**
	 * A pick says what it offers, and a stage box says what kind of box it is,
	 * so a screen never has to guess from the type.
	 */
	public function test_picks_carry_their_options_and_boxes_their_input(): void {
		$source = Gates::requirement( 'G-FUTURE-IDEA-3' );
		$steps  = Gates::requirement( 'G-BUG-TRACKING-3' );
		$owner  = Gates::requirement( 'G-BLOCKED-ENTRY-2' );

		$this->assertSame( 'pick', $source['control'] );
		$this->assertSame( array( 'client-request', 'internal', 'bug-report', 'meeting' ), array_column( $source['options'], 'value' ) );
		$this->assertSame( 'box', $steps['control'] );
		$this->assertSame( 'text', $steps['input'] );
		$this->assertSame( 'people', $owner['source'] );
	}

	/**
	 * Each unmet requirement carries what the UI renders against the card: what
	 * it is, and what would satisfy it.
	 */
	public function test_an_unmet_requirement_says_what_would_satisfy_it(): void {
		$result = Gates::evaluate( 'G-UP-NEXT', $this->item(), array() );
		$unmet  = array_column( $result['unmet'], null, 'id' );

		$this->assertArrayHasKey( 'G-UP-NEXT-4', $unmet );
		$this->assertSame( 'Planned hours per role', $unmet['G-UP-NEXT-4']['label'] );
		$this->assertSame(
			'Enter planned hours for Primary User, Reviewer and Deliverer.',
			$unmet['G-UP-NEXT-4']['satisfied_by']
		);
	}

	/**
	 * A satisfied field requirement drops out of the list.
	 */
	public function test_a_filled_field_satisfies_its_requirement(): void {
		$before = Gates::evaluate( 'G-UP-NEXT', $this->item(), array() );
		$after  = Gates::evaluate(
			'G-UP-NEXT',
			$this->item(
				array(
					'planned_start' => '2026-09-01',
					'planned_due'   => '2026-09-30',
					'priority'      => 'normal',
				)
			),
			array()
		);

		$this->assertContains( 'G-UP-NEXT-5', array_column( $before['unmet'], 'id' ) );
		$this->assertNotContains( 'G-UP-NEXT-5', array_column( $after['unmet'], 'id' ) );
		$this->assertNotContains( 'G-UP-NEXT-6', array_column( $after['unmet'], 'id' ) );
	}

	/**
	 * Half a two-field requirement is not a satisfied requirement.
	 */
	public function test_one_of_two_dates_does_not_satisfy_the_requirement(): void {
		$result = Gates::evaluate( 'G-UP-NEXT', $this->item( array( 'planned_start' => '2026-09-01' ) ), array() );

		$this->assertContains( 'G-UP-NEXT-5', array_column( $result['unmet'], 'id' ) );
	}

	/**
	 * 'unclassified' is the column's default, so it is nobody having answered
	 * rather than an answer.
	 */
	public function test_the_default_commercial_class_does_not_satisfy_triage(): void {
		$unclassified = Gates::evaluate( 'G-TRIAGE', $this->item( array( 'stage' => 'triage' ) ), array() );
		$classified   = Gates::evaluate( 'G-TRIAGE', $this->item( array( 'commercial_class' => 'chargeable' ) ), array() );

		$this->assertContains( 'G-TRIAGE-8', array_column( $unclassified['unmet'], 'id' ) );
		$this->assertNotContains( 'G-TRIAGE-8', array_column( $classified['unmet'], 'id' ) );
	}

	/*
	 * #511. Design comes after Up Next, so starting work is the move out of
	 * Design. Whether the client can still spend is asked again there, with or
	 * without a designer: a package can lapse while the work is in Design.
	 */
	public function test_starting_work_asks_again_whether_the_client_can_spend(): void {
		$item    = $this->item( array( 'stage' => 'design-process' ) );
		$lapsed  = array( 'support_hours' => array( 'sufficient' => false ) );
		$payable = array( 'support_hours' => array( 'sufficient' => true ) );

		$refused = Gates::evaluate( 'G-DESIGN', $item, array(), $lapsed );
		$this->assertSame( array( 'G-DESIGN-9' ), array_column( $refused['unmet'], 'id' ) );

		$this->assertSame( array(), Gates::evaluate( 'G-DESIGN', $item, array(), $payable )['unmet'] );

		// With a designer, the design checks are still asked as well.
		$designed = Gates::evaluate( 'G-DESIGN', $this->item( array( 'stage' => 'design-process', 'designer_id' => 'usr_d' ) ), array(), $lapsed );
		$this->assertContains( 'G-DESIGN-9', array_column( $designed['unmet'], 'id' ) );
		$this->assertContains( 'G-DESIGN-5', array_column( $designed['unmet'], 'id' ) );
	}

	/**
	 * A recorded completion satisfies a record requirement, and nothing else
	 * does.
	 */
	public function test_a_recorded_completion_satisfies_a_record_requirement(): void {
		$records = array( 'G-DESIGN-2' => array( 'actor' => 3 ) );
		$result  = Gates::evaluate( 'G-DESIGN', $this->item( array( 'stage' => 'design-process', 'designer_id' => 'usr_d' ) ), $records );

		$this->assertNotContains( 'G-DESIGN-2', array_column( $result['unmet'], 'id' ) );
		$this->assertContains( 'G-DESIGN-3', array_column( $result['unmet'], 'id' ) );


		$result = Gates::evaluate( 'G-UP-NEXT', $this->item(), array() );
		$this->assertContains( 'G-UP-NEXT-4', array_column( $result['unmet'], 'id' ) );

		// And a record does not satisfy a requirement backed by a field. The
		// three seats are fields since #112, because a completion record saying
		// "reviewer assigned" does not say who — and the authority rules have
		// to read it back.
		$seat = Gates::evaluate( 'G-UP-NEXT', $this->item(), array( 'G-UP-NEXT-2' => array( 'actor' => 3 ) ) );

		$this->assertContains( 'G-UP-NEXT-2', array_column( $seat['unmet'], 'id' ) );
	}

	/**
	 * The capacity and support-hours results are reported whichever way they
	 * went, which is what the specification asks for — and neither refuses a
	 * move while the thing it checks does not exist.
	 */
	public function test_both_system_results_are_always_reported(): void {
		$result = Gates::evaluate( 'G-UP-NEXT', $this->item(), array() );
		$checks = array_column( $result['checks'], null, 'id' );

		$this->assertArrayHasKey( 'G-UP-NEXT-8', $checks );
		$this->assertArrayHasKey( 'G-UP-NEXT-9', $checks );
		$this->assertNotContains( 'G-UP-NEXT-8', array_column( $result['unmet'], 'id' ) );
	}

	/**
	 * An approved Not Applicable decision satisfies G-DESIGN whole, for non-UI
	 * work — and it is a recorded approval, not a skipped stage.
	 */
	public function test_a_not_applicable_decision_satisfies_the_design_gate(): void {
		$designed = array(
			'stage'       => 'design-process',
			'designer_id' => 'usr_d',
		);
		$without  = Gates::evaluate( 'G-DESIGN', $this->item( $designed ), array() );
		$with     = Gates::evaluate(
			'G-DESIGN',
			$this->item( $designed ),
			array( Gates::DESIGN_NOT_APPLICABLE => array( 'actor' => 3 ) )
		);

		$this->assertNotSame( array(), $without['unmet'] );
		$this->assertSame( array(), $with['unmet'] );
	}

	/**
	 * #409. A task with a Designer goes through every Design check.
	 */
	public function test_a_designer_means_the_design_checks_apply(): void {
		$result = Gates::evaluate( 'G-DESIGN', $this->item( array( 'stage' => 'design-process', 'designer_id' => 'usr_d' ) ), array() );

		$this->assertContains( 'G-DESIGN-5', array_column( $result['unmet'], 'id' ) );
		$this->assertNotSame( array(), $result['all'] );
	}

	/**
	 * #409. No Designer is the same as an approved Not Applicable: nothing to
	 * check. Read from the task each time, so clearing it counts at once.
	 */
	public function test_no_designer_means_nothing_to_check_at_design(): void {
		foreach ( array( array(), array( 'designer_id' => '' ) ) as $over ) {
			$result = Gates::evaluate( 'G-DESIGN', $this->item( array( 'stage' => 'design-process' ) + $over ), array() );

			// Nothing for a person to do; only the start-of-work hours check (#511).
			$this->assertSame( array(), $result['unmet'] );
			$this->assertSame( array( 'G-DESIGN-9' ), array_column( $result['checks'], 'id' ) );
			$this->assertSame( array(), array_values( array_diff( array_column( $result['all'], 'id' ), array( 'G-DESIGN-9' ) ) ) );
		}
	}

	/**
	 * Completed will not release while a child is still open (WORK-2).
	 */
	public function test_completed_waits_for_its_children(): void {
		$item = $this->item(
			array(
				'stage'               => 'completed',
				'release_method'      => 'software',
				'release_destination' => 'production',
			)
		);

		$open = Gates::evaluate( 'G-COMPLETED', $item, array(), array( 'children' => array( array( 'stage' => 'in-review' ) ) ) );
		$done = Gates::evaluate( 'G-COMPLETED', $item, array(), array( 'children' => array( array( 'stage' => 'completed' ) ) ) );

		$this->assertContains( 'G-COMPLETED-8', array_column( $open['unmet'], 'id' ) );
		$this->assertNotContains( 'G-COMPLETED-8', array_column( $done['unmet'], 'id' ) );
	}

	/**
	 * #564. A release goes into review once every feature in it is Completed.
	 * Bugs, tasks and feedback under it don't hold it.
	 */
	public function test_a_release_goes_to_review_only_once_its_features_are_completed(): void {
		$release = $this->item(
			array(
				'id'               => 'wrk_r',
				'level'            => 'milestone',
				'stage'            => 'in-development',
				'test_description' => 'Try it.',
			)
		);

		$feature = array(
			'id'        => 'wrk_f',
			'level'     => 'feature',
			'work_type' => 'feature',
			'review_of' => '',
		);
		$bug     = array(
			'id'        => 'wrk_b',
			'level'     => 'sub-feature',
			'work_type' => 'bug',
			'stage'     => 'triage',
			'review_of' => '',
		);

		$open = Gates::evaluate( 'G-IN-DEVELOPMENT', $release, array(), array( 'children' => array( $feature + array( 'stage' => 'in-development' ), $bug ) ) );
		$done = Gates::evaluate( 'G-IN-DEVELOPMENT', $release, array(), array( 'children' => array( $feature + array( 'stage' => 'completed' ), $bug ) ) );

		$this->assertContains( 'G-IN-DEVELOPMENT-8', array_column( $open['unmet'], 'id' ) );
		$this->assertNotContains( 'G-IN-DEVELOPMENT-8', array_column( $done['unmet'], 'id' ), 'a bug under the release does not hold it' );
		$this->assertContains( 'Every feature Completed', array_column( $done['checks'], 'label' ) );
	}

	/**
	 * #564. A release's review can't pass while a fix raised in it is open.
	 */
	public function test_a_release_review_waits_for_its_fixes(): void {
		$release = $this->item(
			array(
				'id'    => 'wrk_r',
				'level' => 'milestone',
				'stage' => 'in-review',
			)
		);

		$fix = array(
			'id'        => 'wrk_x',
			'level'     => 'sub-feature',
			'work_type' => 'bug',
			'review_of' => 'wrk_r',
		);

		$open = Gates::evaluate( 'G-IN-REVIEW', $release, array(), array( 'children' => array( $fix + array( 'stage' => 'in-development' ) ) ) );
		$done = Gates::evaluate( 'G-IN-REVIEW', $release, array(), array( 'children' => array( $fix + array( 'stage' => 'completed' ) ) ) );
		$none = Gates::evaluate( 'G-IN-REVIEW', $release, array(), array( 'children' => array() ) );

		$this->assertContains( 'G-IN-REVIEW-6', array_column( $open['unmet'], 'id' ) );
		$this->assertContains( 'Review fixes completed', array_column( $open['unmet'], 'label' ) );
		$this->assertNotContains( 'G-IN-REVIEW-6', array_column( $done['unmet'], 'id' ) );
		$this->assertNotContains( 'G-IN-REVIEW-6', array_column( $none['unmet'], 'id' ), 'no fixes is nothing to wait for' );
	}

	/**
	 * #564. A fix that was cancelled, archived or moved to another release
	 * stops holding the review open.
	 */
	public function test_a_cancelled_or_moved_fix_does_not_hold_the_review(): void {
		$release = $this->item(
			array(
				'id'    => 'wrk_r',
				'level' => 'milestone',
				'stage' => 'in-review',
			)
		);

		$fix = array(
			'id'        => 'wrk_x',
			'level'     => 'sub-feature',
			'work_type' => 'bug',
			'stage'     => 'in-development',
			'review_of' => 'wrk_r',
		);

		$cancelled = Gates::evaluate( 'G-IN-REVIEW', $release, array(), array( 'children' => array( array_merge( $fix, array( 'terminal_outcome' => 'cancelled' ) ) ) ) );
		$archived  = Gates::evaluate( 'G-IN-REVIEW', $release, array(), array( 'children' => array( array_merge( $fix, array( 'archived' => true ) ) ) ) );
		$moved     = Gates::evaluate( 'G-IN-REVIEW', $release, array(), array( 'children' => array( array_merge( $fix, array( 'review_of' => 'wrk_other' ) ) ) ) );

		$this->assertNotContains( 'G-IN-REVIEW-6', array_column( $cancelled['unmet'], 'id' ) );
		$this->assertNotContains( 'G-IN-REVIEW-6', array_column( $archived['unmet'], 'id' ) );
		$this->assertNotContains( 'G-IN-REVIEW-6', array_column( $moved['unmet'], 'id' ) );
	}

	/**
	 * #564. Only a release is asked either release review row.
	 */
	public function test_review_rows_apply_to_releases_only(): void {
		$feature  = $this->item( array( 'level' => 'feature' ) );
		$children = array(
			'children' => array(
				array(
					'id'        => 'wrk_c',
					'level'     => 'sub-feature',
					'work_type' => 'feature',
					'stage'     => 'in-development',
					'review_of' => 'wrk_1',
				),
			),
		);

		$development = Gates::evaluate( 'G-IN-DEVELOPMENT', $feature, array(), $children );
		$review      = Gates::evaluate( 'G-IN-REVIEW', $feature, array(), $children );

		$this->assertNotContains( 'G-IN-DEVELOPMENT-8', array_column( $development['checks'], 'id' ) );
		$this->assertNotContains( 'G-IN-REVIEW-6', array_column( $review['checks'], 'id' ) );
	}

	/**
	 * Leaving Completed asks nothing about where, when or in what window it
	 * goes, and entering Released asks nothing at all (#418).
	 */
	public function test_releasing_asks_for_no_release_details(): void {
		$gates = Gates::all();
		$ids   = array_column( $gates['G-COMPLETED'], 'id' );

		$this->assertNotContains( 'G-COMPLETED-2', $ids, 'nor how it is released' );
		$this->assertNotContains( 'G-COMPLETED-3', $ids );
		$this->assertNotContains( 'G-COMPLETED-4', $ids );
		$this->assertNotContains( 'G-COMPLETED-7', $ids, 'nor for release notes' );
		$this->assertArrayNotHasKey( 'G-RELEASED', $gates );
	}

	/**
	 * The evidence requirements from the specification are the ones marked so
	 * here. A requirement that wants proof and does not say so is satisfied by
	 * a tick.
	 */
	public function test_the_requirements_that_need_evidence_say_so(): void {
		$expected = array(
			'G-BUG-TRACKING-5',
		);

		$found = array();

		foreach ( Gates::all() as $requirements ) {
			foreach ( $requirements as $requirement ) {
				if ( $requirement['evidence'] ) {
					$found[] = $requirement['id'];
				}
			}
		}

		sort( $found );
		$this->assertSame( $expected, $found );
	}

	/**
	 * #604. Review no longer asks for a post-review hours adjustment.
	 */
	public function test_review_does_not_ask_for_a_post_review_hours_adjustment(): void {
		$this->assertNotContains( 'G-IN-REVIEW-5', array_column( Gates::requirements( 'G-IN-REVIEW' ), 'id' ) );
	}
}
