<?php
/**
 * Tests for the one filter model every view sits behind.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\Filters;
use PHPUnit\Framework\TestCase;

/**
 * #123. One filter model behind every view, and #124's guarantee falls out of
 * it: two views cannot disagree about what a filter set means, because there is
 * only one thing that decides.
 *
 * The alternative — each view filtering the list it was handed — is how a board
 * and a list end up showing different counts for the same filters, and how the
 * discrepancy survives review, because both look correct on their own.
 */
final class WorkFiltersTest extends TestCase {

	/**
	 * An item, with whatever the test is about.
	 *
	 * @param array<string, mixed> $values The fields under test.
	 * @return array<string, mixed>
	 */
	private function item( array $values = array() ): array {
		return array_merge(
			array(
				'id'               => 'wrk_one',
				'title'            => 'Something to do',
				'problem'          => 'It is not done.',
				'stage'            => 'triage',
				'level'            => 'sub-feature',
				'work_type'        => 'feature',
				'priority'         => 'normal',
				'commercial_class' => 'chargeable',
				'terminal_outcome' => '',
				'archived'         => false,
				'primary_user_id'  => '',
				'reviewer_id'      => '',
				'deliverer_id'     => '',
				'planned_start'    => '',
				'planned_due'      => '',
			),
			$values
		);
	}

	// -----------------------------------------------------------------------
	// Reading a filter set.
	// -----------------------------------------------------------------------

	/**
	 * A filter nobody defined is dropped rather than applied. A view that could
	 * name its own filters could name a column, and a filter is a query.
	 */
	public function test_a_filter_nobody_defined_is_dropped(): void {
		$this->assertSame( array(), Filters::sanitise( array( 'created_by' => '1' ) ) );
	}

	/**
	 * A value nobody defined is dropped too. "Stage: whatever" would otherwise
	 * match nothing and read as an empty result rather than a bad filter.
	 */
	public function test_a_value_nobody_defined_is_dropped(): void {
		$this->assertSame( array(), Filters::sanitise( array( 'stage' => 'nonsense' ) ) );
	}

	/**
	 * A real filter survives.
	 */
	public function test_a_real_filter_survives(): void {
		$this->assertSame( array( 'stage' => array( 'triage' ) ), Filters::sanitise( array( 'stage' => 'triage' ) ) );
	}

	/**
	 * Filters take several values at once, because "show me everything not yet
	 * started" is three stages rather than three separate views.
	 */
	public function test_a_filter_takes_several_values(): void {
		$this->assertSame(
			array( 'stage' => array( 'triage', 'up-next' ) ),
			Filters::sanitise( array( 'stage' => array( 'triage', 'up-next' ) ) )
		);
	}

	/**
	 * And drops the bad ones from a mixed set rather than the whole filter: a
	 * stale saved view naming a stage that has been renamed should still show
	 * the rest of what it asked for.
	 */
	public function test_a_mixed_set_keeps_the_values_that_are_real(): void {
		$this->assertSame(
			array( 'stage' => array( 'triage' ) ),
			Filters::sanitise( array( 'stage' => array( 'triage', 'nonsense' ) ) )
		);
	}

	// -----------------------------------------------------------------------
	// Applying one.
	// -----------------------------------------------------------------------

	/**
	 * No filters shows everything. The empty set is not "show nothing".
	 */
	public function test_no_filters_shows_everything(): void {
		$items = array( $this->item(), $this->item( array( 'id' => 'wrk_two' ) ) );

		$this->assertCount( 2, Filters::apply( $items, array() ) );
	}

	/**
	 * A filter with several values matches any of them, not all.
	 */
	public function test_several_values_in_one_filter_match_any_of_them(): void {
		$items = array(
			$this->item( array( 'stage' => 'triage' ) ),
			$this->item( array( 'stage' => 'up-next' ) ),
			$this->item( array( 'stage' => 'in-review' ) ),
		);

		$kept = Filters::apply( $items, array( 'stage' => array( 'triage', 'up-next' ) ) );

		$this->assertCount( 2, $kept );
	}

	/**
	 * Two different filters both have to match. "Bugs in review" is not "bugs
	 * or things in review".
	 */
	public function test_two_filters_both_have_to_match(): void {
		$items = array(
			$this->item(
				array(
					'work_type' => 'bug',
					'stage'     => 'in-review',
				)
			),
			$this->item(
				array(
					'id'        => 'wrk_two',
					'work_type' => 'bug',
					'stage'     => 'triage',
				)
			),
		);

		$kept = Filters::apply(
			$items,
			array(
				'work_type' => array( 'bug' ),
				'stage'     => array( 'in-review' ),
			)
		);

		$this->assertCount( 1, $kept );
	}

	/**
	 * The seat filter matches whichever of the three seats somebody holds, so
	 * "my work" is one filter rather than three views a person has to check.
	 */
	public function test_the_person_filter_matches_any_seat_they_hold(): void {
		$items = array(
			$this->item( array( 'primary_user_id' => 'usr_ana' ) ),
			$this->item(
				array(
					'id'          => 'wrk_two',
					'reviewer_id' => 'usr_ana',
				)
			),
			$this->item(
				array(
					'id'           => 'wrk_three',
					'deliverer_id' => 'usr_bo',
				)
			),
		);

		$this->assertCount( 2, Filters::apply( $items, array( 'person' => array( 'usr_ana' ) ) ) );
	}

	/**
	 * Searching looks at what somebody would have typed: the title and the
	 * problem. Not every field — a search that matches an id somebody pasted
	 * into a note is a search nobody can predict.
	 */
	public function test_searching_looks_at_the_title_and_the_problem(): void {
		$items = array(
			$this->item( array( 'title' => 'Checkout is slow' ) ),
			$this->item(
				array(
					'id'      => 'wrk_two',
					'title'   => 'Something else',
					'problem' => 'The checkout takes nine seconds.',
				)
			),
			$this->item(
				array(
					'id'      => 'wrk_three',
					'title'   => 'Unrelated',
					'problem' => 'Nothing to do with it.',
				)
			),
		);

		$this->assertCount( 2, Filters::apply( $items, array( 'search' => 'checkout' ) ) );
	}

	/**
	 * And ignores case, because nobody types the way the record was written.
	 */
	public function test_searching_ignores_case(): void {
		$items = array( $this->item( array( 'title' => 'Checkout is slow' ) ) );

		$this->assertCount( 1, Filters::apply( $items, array( 'search' => 'CHECKOUT' ) ) );
	}

	/**
	 * A date range is about the plan, and includes both ends: somebody asking
	 * for September means the whole of September.
	 */
	public function test_a_date_range_includes_both_ends(): void {
		$items = array(
			$this->item( array( 'planned_due' => '2026-09-01' ) ),
			$this->item(
				array(
					'id'          => 'wrk_two',
					'planned_due' => '2026-09-30',
				)
			),
			$this->item(
				array(
					'id'          => 'wrk_three',
					'planned_due' => '2026-10-01',
				)
			),
		);

		$kept = Filters::apply(
			$items,
			array(
				'due_from' => '2026-09-01',
				'due_to'   => '2026-09-30',
			)
		);

		$this->assertCount( 2, $kept );
	}

	/**
	 * Work with no date is not in any date range. It is not "before everything"
	 * — it is unplanned, which is a different filter.
	 */
	public function test_undated_work_is_not_in_a_date_range(): void {
		$items = array( $this->item( array( 'planned_due' => '' ) ) );

		$this->assertCount( 0, Filters::apply( $items, array( 'due_from' => '2026-09-01' ) ) );
	}

	// -----------------------------------------------------------------------
	// Grouping.
	// -----------------------------------------------------------------------

	/**
	 * Grouping is part of a view and changes nothing about what is in it. The
	 * same items, arranged differently.
	 */
	public function test_grouping_rearranges_without_removing(): void {
		$items = array(
			$this->item( array( 'stage' => 'triage' ) ),
			$this->item(
				array(
					'id'    => 'wrk_two',
					'stage' => 'up-next',
				)
			),
		);

		$grouped = Filters::group( $items, 'stage' );

		$this->assertSame( 2, array_sum( array_map( 'count', $grouped ) ) );
		$this->assertArrayHasKey( 'triage', $grouped );
	}

	/**
	 * A grouping nobody defined groups by nothing, rather than by a column
	 * somebody named in a URL.
	 */
	public function test_a_grouping_nobody_defined_is_refused(): void {
		$this->assertSame( '', Filters::grouping( 'created_by' ) );
		$this->assertSame( 'stage', Filters::grouping( 'stage' ) );
	}

	// -----------------------------------------------------------------------
	// #123's rule: a saved view changes what is shown, never what is allowed.
	// -----------------------------------------------------------------------

	/**
	 * A saved view holds filters and grouping. Nothing else survives being
	 * stored, so a saved view cannot carry a permission, a capability, a site
	 * it was not opened on, or a workflow rule.
	 */
	public function test_a_saved_view_holds_filters_and_grouping_and_nothing_else(): void {
		$stored = Filters::view_for_storage(
			array(
				'name'       => 'Mine, this week',
				'filters'    => array( 'stage' => 'triage' ),
				'grouping'   => 'stage',
				'capability' => 'move_forward',
				'role'       => 'primary_admin',
				'client_id'  => 'cli_someone_elses',
			)
		);

		$this->assertSame( array( 'name', 'filters', 'grouping' ), array_keys( $stored ) );
	}

	/**
	 * Named individually because it is the one that would matter: a saved view
	 * that could carry a capability would be a way of granting oneself one by
	 * saving a view and opening it.
	 */
	public function test_a_saved_view_cannot_carry_a_capability(): void {
		$stored = Filters::view_for_storage(
			array(
				'name'       => 'Sneaky',
				'capability' => 'override',
			)
		);

		$this->assertArrayNotHasKey( 'capability', $stored );
	}

	/**
	 * And its filters go through the same sanitising as anybody else's, so a
	 * view saved with a hand-edited payload is no more powerful than one built
	 * on the screen.
	 */
	public function test_a_saved_views_filters_are_sanitised_like_any_other(): void {
		$stored = Filters::view_for_storage(
			array(
				'name'    => 'Hand edited',
				'filters' => array(
					'stage'      => 'triage',
					'created_by' => '1',
				),
			)
		);

		$this->assertSame( array( 'stage' => array( 'triage' ) ), $stored['filters'] );
	}

	/**
	 * A view needs a name somebody can pick out of a list.
	 */
	public function test_a_view_without_a_name_is_refused(): void {
		$this->assertNull( Filters::view_for_storage( array( 'filters' => array() ) ) );
	}
}
