<?php
/**
 * Tests for what a client site is allowed to see of its own submissions.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\ClientView;
use Blueworx\Forge\Work\Submissions;
use PHPUnit\Framework\TestCase;

/**
 * #130. A client sees what happened to what they asked for.
 *
 * The same allowlist discipline as the board projection, for the same reason: a
 * column added to the submissions table later must not reach a client's screen
 * because nobody remembered to strip it.
 *
 * The one thing here the board does not have is the converted work. A
 * submission carries the id of the item it became, and an id is not something a
 * client can read — so the projection resolves it to a title and a stage. That
 * resolution is where a tenancy mistake would hide, which is why it is tested
 * harder than anything else in this file.
 */
final class ClientSubmissionViewTest extends TestCase {

	/**
	 * The keys a client may see of a submission, and the whole of them.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED = array(
		'id',
		'type',
		'title',
		'description',
		'desired_outcome',
		'evidence',
		'submitted_by',
		'intake_state',
		'intake_label',
		'response',
		'converted',
		'created_at',
		'updated_at',
	);

	/**
	 * A submission row, in the shape Submissions::for_site() returns.
	 *
	 * @param array<string, mixed> $also Columns to add or override.
	 * @return array<string, mixed>
	 */
	private function row( array $also = array() ): array {
		return array_merge(
			array(
				'id'                => 'sub_1',
				'client_site_id'    => 'cst_1',
				'client_id'         => 'cli_1',
				'type'              => 'request',
				'title'             => 'A booking form that takes deposits',
				'description'       => 'People ring up to pay.',
				'desired_outcome'   => 'They pay on the site.',
				'evidence'          => 'Three calls last week.',
				'submitted_by'      => 'Priya Shah',
				'intake_state'      => 'received',
				'response'          => '',
				'converted_item_id' => '',
				'created_at'        => 1756000000,
				'updated_at'        => 1756000000,
				'created_by'        => 0,
				'record_version'    => 1,
			),
			$also
		);
	}

	/**
	 * Looks a work item up the way Items::get() does.
	 *
	 * Two items, belonging to two different client sites, because the thing
	 * worth proving is which of them a submission can name.
	 *
	 * @return callable
	 */
	private function work(): callable {
		return static function ( string $id ): ?array {
			$items = array(
				'wrk_ours'   => array(
					'id'             => 'wrk_ours',
					'client_site_id' => 'cst_1',
					'title'          => 'Booking form, phase one',
					'stage'          => 'in-development',
				),
				'wrk_theirs' => array(
					'id'             => 'wrk_theirs',
					'client_site_id' => 'cst_2',
					'title'          => 'Somebody else entirely',
					'stage'          => 'in-review',
				),
			);

			return $items[ $id ] ?? null;
		};
	}

	// -----------------------------------------------------------------------
	// The allowlist.
	// -----------------------------------------------------------------------

	/**
	 * The projection emits the named keys and no others.
	 */
	public function test_the_projection_emits_exactly_the_allowed_keys(): void {
		$view = ClientView::submission( $this->row(), $this->work() );

		$this->assertSame( self::ALLOWED, array_keys( $view ) );
	}

	/**
	 * Which client and which site this belongs to are ours. The client site
	 * already knows who it is, and publishing the ids invites a screen to start
	 * treating them as a parameter.
	 */
	public function test_the_tenancy_columns_do_not_survive_the_projection(): void {
		$view = ClientView::submission( $this->row(), $this->work() );

		$this->assertArrayNotHasKey( 'client_id', $view );
		$this->assertArrayNotHasKey( 'client_site_id', $view );
	}

	/**
	 * A column added later is refused by construction. The regression test for
	 * the whole design: it fails the moment somebody rewrites the projection as
	 * "the row, minus these".
	 */
	public function test_a_column_added_later_is_absent_by_default(): void {
		$view = ClientView::submission( $this->row( array( 'triage_note' => 'Low value, string along' ) ), $this->work() );

		$this->assertArrayNotHasKey( 'triage_note', $view );
	}

	/**
	 * The raw id of the converted item is not published. What goes out is the
	 * resolved work, or nothing.
	 */
	public function test_the_converted_item_id_is_not_published_raw(): void {
		$view = ClientView::submission( $this->row( array( 'converted_item_id' => 'wrk_ours' ) ), $this->work() );

		$this->assertArrayNotHasKey( 'converted_item_id', $view );
	}

	// -----------------------------------------------------------------------
	// The client's own words, unchanged.
	// -----------------------------------------------------------------------

	/**
	 * What was asked comes back as it was asked (REQ-1). This is the record's
	 * whole purpose, so it is worth a test that fails if anybody ever decides
	 * to tidy it.
	 */
	public function test_the_submitted_words_come_back_unchanged(): void {
		$view = ClientView::submission( $this->row(), $this->work() );

		$this->assertSame( 'A booking form that takes deposits', $view['title'] );
		$this->assertSame( 'People ring up to pay.', $view['description'] );
		$this->assertSame( 'They pay on the site.', $view['desired_outcome'] );
		$this->assertSame( 'Three calls last week.', $view['evidence'] );
		$this->assertSame( 'Priya Shah', $view['submitted_by'] );
	}

	// -----------------------------------------------------------------------
	// The status, named as the studio names it.
	// -----------------------------------------------------------------------

	/**
	 * The state arrives named as well as slugged, so no client screen holds its
	 * own copy of the intake vocabulary — the same rule the board's stages
	 * follow.
	 */
	public function test_the_state_carries_the_name_the_studio_gives_it(): void {
		$view = ClientView::submission( $this->row(), $this->work() );

		$this->assertSame( 'received', $view['intake_state'] );
		$this->assertSame( 'Received', $view['intake_label'] );
	}

	/**
	 * Every state a submission can be in has words. A state that fell through
	 * to its own slug would put 'in-review' on a client's screen.
	 */
	public function test_every_intake_state_has_words(): void {
		foreach ( Submissions::STATES as $state ) {
			$label = Submissions::label( $state );

			$this->assertNotSame( '', $label );
			$this->assertNotSame( $state, $label, sprintf( 'The %s state has no words of its own.', $state ) );
		}
	}

	/**
	 * A state nobody recognises is not guessed at. Showing the raw slug is
	 * better than inventing a status a client would act on.
	 */
	public function test_an_unknown_state_is_shown_as_it_is(): void {
		$this->assertSame( 'gubbins', Submissions::label( 'gubbins' ) );
	}

	// -----------------------------------------------------------------------
	// The studio's answer.
	// -----------------------------------------------------------------------

	/**
	 * A submission nobody has answered has no response, rather than a blank
	 * one. The screen needs to tell "we have not replied" from "we replied with
	 * nothing".
	 */
	public function test_an_unanswered_submission_has_an_empty_response(): void {
		$view = ClientView::submission( $this->row(), $this->work() );

		$this->assertSame( '', $view['response'] );
	}

	/**
	 * The studio's answer reaches the client verbatim.
	 */
	public function test_the_response_reaches_the_client(): void {
		$view = ClientView::submission(
			$this->row( array( 'response' => 'Yes, in the October release.' ) ),
			$this->work()
		);

		$this->assertSame( 'Yes, in the October release.', $view['response'] );
	}

	// -----------------------------------------------------------------------
	// The work it became.
	// -----------------------------------------------------------------------

	/**
	 * A submission that has not become work says so by having nothing, not by
	 * having a work-shaped blank.
	 */
	public function test_a_submission_that_is_not_yet_work_names_none(): void {
		$view = ClientView::submission( $this->row(), $this->work() );

		$this->assertSame( array(), $view['converted'] );
	}

	/**
	 * Converted work is named and staged, because an id is not something a
	 * client can read.
	 */
	public function test_converted_work_is_named_and_staged(): void {
		$view = ClientView::submission(
			$this->row(
				array(
					'intake_state'      => 'converted',
					'converted_item_id' => 'wrk_ours',
				)
			),
			$this->work()
		);

		$this->assertSame(
			array(
				'id'          => 'wrk_ours',
				'title'       => 'Booking form, phase one',
				'stage'       => 'in-development',
				'stage_label' => 'In development',
			),
			$view['converted']
		);
	}

	/**
	 * The one that matters. A submission pointing at another client site's work
	 * names nothing — a mis-typed conversion must not become a hole through
	 * which one client reads another's item title.
	 */
	public function test_work_belonging_to_another_client_site_is_never_named(): void {
		$view = ClientView::submission(
			$this->row(
				array(
					'intake_state'      => 'converted',
					'converted_item_id' => 'wrk_theirs',
				)
			),
			$this->work()
		);

		$this->assertSame( array(), $view['converted'] );
	}

	/**
	 * Work that has since been deleted leaves the submission readable rather
	 * than fatal.
	 */
	public function test_work_that_no_longer_exists_names_nothing(): void {
		$view = ClientView::submission(
			$this->row( array( 'converted_item_id' => 'wrk_gone' ) ),
			$this->work()
		);

		$this->assertSame( array(), $view['converted'] );
	}

	// -----------------------------------------------------------------------
	// Lists.
	// -----------------------------------------------------------------------

	/**
	 * A list of rows projects to a list, in the order given — which is the
	 * order Submissions::for_site() chose, newest first.
	 */
	public function test_a_list_of_rows_projects_in_order(): void {
		$views = ClientView::submissions(
			array(
				$this->row( array( 'id' => 'sub_2' ) ),
				$this->row( array( 'id' => 'sub_1' ) ),
			),
			$this->work()
		);

		$this->assertSame( array( 'sub_2', 'sub_1' ), array_column( $views, 'id' ) );
	}
}
