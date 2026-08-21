<?php
/**
 * Tests that every gate has a field to check.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\Fields;
use Blueworx\Forge\Work\Gates;
use PHPUnit\Framework\TestCase;

/**
 * #97's acceptance criterion, as a test rather than an inspection: every gate
 * requirement in the state machine resolves to a field that exists.
 *
 * The failure this catches is quiet and expensive. A requirement naming a field
 * nothing stores does not error — it reads an absent value, finds it empty, and
 * either blocks a move forever or, worse, is written to pass and then passes
 * always. Either way the gate looks enforced and is not.
 */
final class WorkFieldCoverageTest extends TestCase {

	/**
	 * The names a gate may check that are not fields somebody writes: they come
	 * from the record itself or from the workflow.
	 *
	 * Listed rather than pattern-matched, so adding one is a decision.
	 */
	private const SYSTEM_FIELDS = array(
		'client_site_id',
		'level',
		'parent_id',
		'stage',
		'terminal_outcome',
		'duplicate_of',
	);

	/**
	 * Every field named by every gate exists.
	 */
	public function test_every_gate_requirement_names_a_field_that_exists(): void {
		$known = array_merge( Fields::writable(), self::SYSTEM_FIELDS );

		foreach ( Gates::all() as $gate => $requirements ) {
			foreach ( $requirements as $requirement ) {
				foreach ( (array) $requirement['fields'] as $field ) {
					$this->assertContains(
						$field,
						$known,
						sprintf( '%s in %s names "%s", which nothing stores', $requirement['id'], $gate, $field )
					);
				}
			}
		}
	}

	/**
	 * A requirement satisfied by a field names at least one, or there is nothing
	 * for it to check and it passes for everybody.
	 */
	public function test_a_field_backed_requirement_names_a_field(): void {
		foreach ( Gates::all() as $gate => $requirements ) {
			foreach ( $requirements as $requirement ) {
				if ( 'field' !== $requirement['by'] ) {
					continue;
				}

				$this->assertNotEmpty(
					$requirement['fields'],
					sprintf( '%s in %s is satisfied by a field and names none', $requirement['id'], $gate )
				);
			}
		}
	}

	/**
	 * And a requirement that is not field-backed names none, so a screen cannot
	 * offer a form field for something a person has to go and do.
	 */
	public function test_a_requirement_that_is_not_field_backed_names_no_field(): void {
		foreach ( Gates::all() as $gate => $requirements ) {
			foreach ( $requirements as $requirement ) {
				if ( 'field' === $requirement['by'] ) {
					continue;
				}

				$this->assertSame(
					array(),
					(array) $requirement['fields'],
					sprintf( '%s in %s names a field it does not check', $requirement['id'], $gate )
				);
			}
		}
	}

	// -----------------------------------------------------------------------
	// #98. Planned hours by role.
	// -----------------------------------------------------------------------

	/**
	 * The three seats each carry planned hours, because "who is reviewing this"
	 * and "how long we said the review would take" are the same conversation —
	 * and M7's capacity planning has nothing to work from without the second.
	 */
	public function test_each_seat_carries_planned_hours(): void {
		foreach ( array( 'hours_primary', 'hours_review', 'hours_delivery' ) as $field ) {
			$this->assertContains( $field, Fields::HOURS, sprintf( '%s is not a planned-hours field', $field ) );
			$this->assertContains( $field, Fields::writable(), sprintf( '%s cannot be written', $field ) );
		}
	}

	/**
	 * They belong with the seats rather than with the plan: who does the work
	 * and how long their part takes are set together, and by the same people.
	 */
	public function test_planned_hours_are_set_by_whoever_sets_the_seats(): void {
		$this->assertSame( array(), array_diff( Fields::HOURS, Fields::ACCOUNTABILITY ) );
	}

	// -----------------------------------------------------------------------
	// Nothing writable is a stage.
	// -----------------------------------------------------------------------

	/**
	 * The stage is written only by the transition service, so no edit can move
	 * work sideways past a gate. Stated here because it is the one field whose
	 * absence from the writable list is load-bearing.
	 */
	public function test_the_stage_is_not_a_field_anybody_writes(): void {
		$this->assertNotContains( 'stage', Fields::writable() );
		$this->assertNotContains( 'prior_stage', Fields::writable() );
		$this->assertNotContains( 'cycle', Fields::writable() );
	}
}
