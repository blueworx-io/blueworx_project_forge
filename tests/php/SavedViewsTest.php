<?php
/**
 * Tests for the views a person saves for themselves.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\SavedViews;
use PHPUnit\Framework\TestCase;

/**
 * #123's second half. A saved view is a person's own shortcut to a way of
 * looking at the work, and the rule that makes it safe is that it can only
 * change what is shown.
 *
 * Stored against the person rather than against a site, because that is what it
 * is: "how I like to look at things" travels with somebody across every client
 * they work with.
 */
final class SavedViewsTest extends TestCase {

	/**
	 * Clears the stored meta between tests.
	 */
	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_user_meta'] = array();
	}

	// -----------------------------------------------------------------------
	// Saving one.
	// -----------------------------------------------------------------------

	/**
	 * A saved view comes back with an id of its own, so it can be opened and
	 * removed without being matched by name.
	 */
	public function test_a_saved_view_gets_an_id(): void {
		$saved = SavedViews::save( 7, array( 'name' => 'Mine, this week' ) );

		$this->assertNotNull( $saved );
		$this->assertStringStartsWith( SavedViews::PREFIX . '_', (string) $saved['id'] );
	}

	/**
	 * And is there when the person comes back.
	 */
	public function test_a_saved_view_is_there_afterwards(): void {
		SavedViews::save( 7, array( 'name' => 'Mine, this week' ) );

		$views = SavedViews::for_user( 7 );

		$this->assertCount( 1, $views );
		$this->assertSame( 'Mine, this week', $views[0]['name'] );
	}

	/**
	 * One person's views are not another's. They are held against the account,
	 * so this is a fact about where they are stored rather than a check
	 * somebody has to remember.
	 */
	public function test_one_persons_views_are_not_anothers(): void {
		SavedViews::save( 7, array( 'name' => 'Mine' ) );

		$this->assertSame( array(), SavedViews::for_user( 8 ) );
	}

	/**
	 * A view with no name is refused rather than saved as "Untitled": a list of
	 * three untitled views is a list nobody can use.
	 */
	public function test_a_view_with_no_name_is_refused(): void {
		$this->assertNull( SavedViews::save( 7, array( 'filters' => array( 'stage' => 'triage' ) ) ) );
	}

	/**
	 * Nobody signed in saves nothing. A view held against user zero would be
	 * everybody's.
	 */
	public function test_nobody_signed_in_saves_nothing(): void {
		$this->assertNull( SavedViews::save( 0, array( 'name' => 'Whose?' ) ) );
	}

	// -----------------------------------------------------------------------
	// The rule: what is shown, never what is allowed.
	// -----------------------------------------------------------------------

	/**
	 * The rule #123 asks for, at the point it is stored. Anything that is not a
	 * name, a filter set or a grouping does not survive being saved — so no
	 * saved view can carry a capability, a role or somebody else's client.
	 */
	public function test_a_saved_view_holds_nothing_that_could_change_what_is_allowed(): void {
		$saved = SavedViews::save(
			7,
			array(
				'name'       => 'Sneaky',
				'capability' => 'override',
				'role'       => 'primary_admin',
				'client_id'  => 'cli_someone_elses',
				'user_id'    => 9,
			)
		);

		$this->assertSame( array( 'id', 'name', 'filters', 'grouping' ), array_keys( (array) $saved ) );
	}

	/**
	 * And its filters are sanitised on the way in, so a hand-edited payload
	 * saves no more than one built on the screen.
	 */
	public function test_a_saved_views_filters_are_sanitised(): void {
		$saved = SavedViews::save(
			7,
			array(
				'name'    => 'Hand edited',
				'filters' => array(
					'stage'      => 'triage',
					'created_by' => '1',
				),
			)
		);

		$this->assertSame( array( 'stage' => array( 'triage' ) ), $saved['filters'] );
	}

	// -----------------------------------------------------------------------
	// Removing one.
	// -----------------------------------------------------------------------

	/**
	 * A person can remove their own.
	 */
	public function test_a_person_can_remove_their_own_view(): void {
		$saved = SavedViews::save( 7, array( 'name' => 'Temporary' ) );

		$this->assertTrue( SavedViews::remove( 7, (string) $saved['id'] ) );
		$this->assertSame( array(), SavedViews::for_user( 7 ) );
	}

	/**
	 * And nobody else's, which again follows from where they are stored rather
	 * than from a check: user 8's list does not contain user 7's id.
	 */
	public function test_a_person_cannot_remove_somebody_elses(): void {
		$saved = SavedViews::save( 7, array( 'name' => 'Mine' ) );

		$this->assertFalse( SavedViews::remove( 8, (string) $saved['id'] ) );
		$this->assertCount( 1, SavedViews::for_user( 7 ) );
	}

	/**
	 * Removing one that is not there is not an error worth reporting as a
	 * failure of anything, but it is not a success either.
	 */
	public function test_removing_a_view_that_is_not_there_says_so(): void {
		$this->assertFalse( SavedViews::remove( 7, 'svw_nosuchthing' ) );
	}

	// -----------------------------------------------------------------------
	// How many.
	// -----------------------------------------------------------------------

	/**
	 * There is a limit, because this lands in one user meta row and an
	 * unbounded list is an unbounded row. The limit is generous enough that
	 * nobody meets it by working normally.
	 */
	public function test_there_is_a_limit_on_how_many_can_be_saved(): void {
		for ( $n = 0; $n < SavedViews::MOST; $n++ ) {
			$this->assertNotNull( SavedViews::save( 7, array( 'name' => 'View ' . $n ) ) );
		}

		$this->assertNull( SavedViews::save( 7, array( 'name' => 'One too many' ) ) );
	}
}
