<?php
/**
 * Tests for the record of every material change.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\Changelog;
use Blueworx\Forge\Work\Events;
use PHPUnit\Framework\TestCase;

/**
 * #99. Every material change recorded in a way nobody can edit or delete.
 *
 * Two halves, and both are here. What an entry has to say — who, what, from
 * what to what, when, from where, and why — and the guarantee that once said it
 * stays said.
 */
final class WorkChangelogTest extends TestCase {

	/**
	 * An item, in the shape Items::get() returns, at whatever values the test
	 * cares about.
	 *
	 * @param array<string, mixed> $values The fields under test.
	 * @return array<string, mixed>
	 */
	private function item( array $values = array() ): array {
		return array_merge(
			array(
				'id'             => 'wit_one',
				'client_site_id' => 'csite_one',
				'title'          => 'Original title',
				'priority'       => 'normal',
				'planned_due'    => '',
				'cycle'          => 1,
				'review_attempt' => 1,
			),
			$values
		);
	}

	// -----------------------------------------------------------------------
	// What counts as a change.
	// -----------------------------------------------------------------------

	/**
	 * An edit that changes nothing records nothing. A changelog full of
	 * "changed title from X to X" is one nobody reads.
	 */
	public function test_an_edit_that_changes_nothing_records_nothing(): void {
		$entries = Changelog::for_edit( $this->item(), array( 'title' => 'Original title' ), array() );

		$this->assertSame( array(), $entries );
	}

	/**
	 * One entry per field, not one per edit. "Somebody changed four things" is
	 * not an answer to "when did the due date move".
	 */
	public function test_each_changed_field_gets_its_own_entry(): void {
		$entries = Changelog::for_edit(
			$this->item(),
			array(
				'title'    => 'A better title',
				'priority' => 'high',
			),
			array()
		);

		$this->assertCount( 2, $entries );
		$this->assertSame( array( 'title', 'priority' ), array_column( $entries, 'field' ) );
	}

	/**
	 * Both sides of the change, so the entry answers "what was it before"
	 * without anybody having to replay the whole history to find out.
	 */
	public function test_an_entry_carries_what_it_was_and_what_it_became(): void {
		$entry = Changelog::for_edit( $this->item(), array( 'priority' => 'urgent' ), array() )[0];

		$this->assertSame( 'normal', $entry['previous_value'] );
		$this->assertSame( 'urgent', $entry['new_value'] );
	}

	/**
	 * Filling in a field that was empty is a change, and reads as one. An entry
	 * that hid the empty side would make "it was always this" and "somebody set
	 * it on Tuesday" look the same.
	 */
	public function test_filling_in_an_empty_field_is_a_change(): void {
		$entry = Changelog::for_edit( $this->item(), array( 'planned_due' => '2026-09-30' ), array() )[0];

		$this->assertSame( '', $entry['previous_value'] );
		$this->assertSame( '2026-09-30', $entry['new_value'] );
	}

	/**
	 * A field the edit did not name is not a change, even though the item has a
	 * value for it.
	 */
	public function test_a_field_the_edit_did_not_touch_is_not_a_change(): void {
		$entries = Changelog::for_edit( $this->item(), array( 'title' => 'A better title' ), array() );

		$this->assertSame( array( 'title' ), array_column( $entries, 'field' ) );
	}

	// -----------------------------------------------------------------------
	// What an entry says.
	// -----------------------------------------------------------------------

	/**
	 * The entry is about the item it happened to, and carries the site so the
	 * tenant boundary can be applied to history as well as to records.
	 */
	public function test_an_entry_belongs_to_its_item_and_its_site(): void {
		$entry = Changelog::for_edit( $this->item(), array( 'title' => 'New' ), array() )[0];

		$this->assertSame( 'wit_one', $entry['item_id'] );
		$this->assertSame( 'csite_one', $entry['client_site_id'] );
	}

	/**
	 * Which interface it came from. The same edit made by us and made by the
	 * client are different facts, and only the entry can say which it was.
	 */
	public function test_an_entry_says_which_interface_it_came_from(): void {
		$entry = Changelog::for_edit(
			$this->item(),
			array( 'title' => 'New' ),
			array( 'source_interface' => 'client' )
		)[0];

		$this->assertSame( 'client', $entry['source_interface'] );
	}

	/**
	 * The reason, where the change carried one.
	 */
	public function test_an_entry_carries_the_reason_it_was_given(): void {
		$entry = Changelog::for_edit(
			$this->item(),
			array( 'planned_due' => '2026-10-15' ),
			array( 'reason' => 'Client moved the launch.' )
		)[0];

		$this->assertSame( 'Client moved the launch.', $entry['reason'] );
	}

	/**
	 * And the cycle and attempt it happened in, so a reopened item's history
	 * reads as two passes rather than one confusing one.
	 */
	public function test_an_entry_knows_which_pass_it_happened_in(): void {
		$entry = Changelog::for_edit(
			$this->item(
				array(
					'cycle'          => 2,
					'review_attempt' => 3,
				)
			),
			array( 'title' => 'New' ),
			array()
		)[0];

		$this->assertSame( 2, $entry['cycle'] );
		$this->assertSame( 3, $entry['attempt'] );
	}

	/**
	 * A value nobody would want to read in full is cut down rather than stored
	 * whole: the changelog is a record of what changed, not a second copy of
	 * every draft of a requirements document.
	 */
	public function test_a_very_long_value_is_summarised_rather_than_stored_whole(): void {
		$entry = Changelog::for_edit(
			$this->item( array( 'title' => '' ) ),
			array( 'title' => str_repeat( 'x', 500 ) ),
			array()
		)[0];

		$this->assertLessThanOrEqual( Changelog::MAX_VALUE, mb_strlen( $entry['new_value'] ) );
	}

	// -----------------------------------------------------------------------
	// Nobody can edit or delete one.
	// -----------------------------------------------------------------------

	/**
	 * The guarantee, stated where it can be checked: the class that owns the
	 * log has no way to change an entry. Not "does not currently", but has no
	 * method that could.
	 */
	public function test_the_log_has_no_way_to_change_an_entry(): void {
		$methods = array();

		foreach ( ( new ReflectionClass( Events::class ) )->getMethods() as $method ) {
			$methods[] = strtolower( $method->getName() );
		}

		foreach ( array( 'update', 'delete', 'remove', 'edit', 'amend', 'clear' ) as $forbidden ) {
			$this->assertNotContains( $forbidden, $methods, sprintf( 'Events::%s() exists', $forbidden ) );
		}
	}

	/**
	 * A correction is a further entry rather than a rewrite, so the log has to
	 * offer one. Without it, the only way to fix a mistake is the one that is
	 * not allowed.
	 */
	public function test_a_mistake_is_corrected_by_appending(): void {
		$this->assertTrue( method_exists( Events::class, 'append' ) );
		$this->assertContains( Events::CORRECTED, Events::ACTIONS );
	}
}
