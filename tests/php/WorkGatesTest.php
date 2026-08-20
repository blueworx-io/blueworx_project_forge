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
				'remaining_estimate'  => 0.0,
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
		$named = array( Transitions::CREATE_GATE, 'G-RELEASED', 'G-BLOCKED-ENTRY', 'G-BLOCKED-EXIT' );

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
				$this->assertContains( $requirement['by'], array( Gates::BY_FIELD, Gates::BY_RECORD, Gates::BY_SYSTEM ) );
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
				'scope'    => 'Fix it.',
				// non_goals, requirements, acceptance_criteria and references
				// are all empty, and six records are missing.
			)
		);

		$result = Gates::evaluate( 'G-DOCUMENTATION', $item, array() );
		$ids    = array_column( $result['unmet'], 'id' );

		$this->assertContains( 'G-DOCUMENTATION-3', $ids );
		$this->assertContains( 'G-DOCUMENTATION-4', $ids );
		$this->assertContains( 'G-DOCUMENTATION-5', $ids );
		$this->assertContains( 'G-DOCUMENTATION-8', $ids );
		$this->assertNotContains( 'G-DOCUMENTATION-1', $ids, 'The problem statement was filled in' );
		$this->assertNotContains( 'G-DOCUMENTATION-2', $ids, 'The scope was filled in' );
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

	/**
	 * A recorded completion satisfies a record requirement, and nothing else
	 * does.
	 */
	public function test_a_recorded_completion_satisfies_a_record_requirement(): void {
		$records = array( 'G-UP-NEXT-1' => array( 'actor' => 3 ) );
		$result  = Gates::evaluate( 'G-UP-NEXT', $this->item(), $records );

		$this->assertNotContains( 'G-UP-NEXT-1', array_column( $result['unmet'], 'id' ) );
		$this->assertContains( 'G-UP-NEXT-2', array_column( $result['unmet'], 'id' ) );
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
		$without = Gates::evaluate( 'G-DESIGN', $this->item( array( 'stage' => 'design-process' ) ), array() );
		$with    = Gates::evaluate(
			'G-DESIGN',
			$this->item( array( 'stage' => 'design-process' ) ),
			array( Gates::DESIGN_NOT_APPLICABLE => array( 'actor' => 3 ) )
		);

		$this->assertNotSame( array(), $without['unmet'] );
		$this->assertSame( array(), $with['unmet'] );
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
	 * The evidence requirements from the specification are the ones marked so
	 * here. A requirement that wants proof and does not say so is satisfied by
	 * a tick.
	 */
	public function test_the_requirements_that_need_evidence_say_so(): void {
		$expected = array(
			'G-BUG-TRACKING-5',
			'G-DESIGN-1',
			'G-IN-DEVELOPMENT-2',
			'G-IN-DEVELOPMENT-3',
			'G-RELEASED-3',
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
}
