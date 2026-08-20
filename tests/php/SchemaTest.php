<?php
/**
 * The tables, and when they are (re)built.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Data\Schema;
use PHPUnit\Framework\TestCase;

/**
 * A plugin updated in place never re-runs activation, so "create the tables on
 * activation" is not enough on its own. These tests pin the decision that says
 * so, and the columns every later milestone will index against.
 */
final class SchemaTest extends TestCase {

	/**
	 * A site that has never seen the plugin needs the tables.
	 */
	public function test_a_site_with_no_schema_needs_an_upgrade(): void {
		$this->assertTrue( Schema::needs_upgrade( null ) );
	}

	/**
	 * A site behind the code needs them again — this is the in-place update case.
	 */
	public function test_a_site_behind_the_code_needs_an_upgrade(): void {
		$this->assertTrue( Schema::needs_upgrade( Schema::VERSION - 1 ) );
	}

	/**
	 * A site already at the current version does no work on every page load.
	 */
	public function test_a_current_site_needs_nothing(): void {
		$this->assertFalse( Schema::needs_upgrade( Schema::VERSION ) );
	}

	/**
	 * Every record table carries the columns everything later depends on: the
	 * id, the author and time stamps, and the record version that ARCH-5
	 * refuses stale writes against.
	 */
	public function test_both_tables_carry_the_common_columns(): void {
		$definitions = Schema::definitions();

		$this->assertCount( 10, $definitions );

		// The append-only tables are the exception, for the reason spelled out
		// in the next test: nothing ever updates a row in them.
		$append_only = array(
			Schema::work_events_table(),
			Schema::gate_records_table(),
			Schema::comments_table(),
			Schema::contacts_table(),
		);

		foreach ( $definitions as $table => $sql ) {
			$this->assertStringContainsString( 'id varchar(32) NOT NULL', $sql );
			$this->assertStringContainsString( 'PRIMARY KEY  (id)', $sql );

			if ( in_array( $table, $append_only, true ) ) {
				continue;
			}

			$this->assertStringContainsString( 'record_version', $sql );
			$this->assertStringContainsString( 'created_at', $sql );
			$this->assertStringContainsString( 'updated_at', $sql );
			$this->assertStringContainsString( 'created_by', $sql );
		}
	}

	/**
	 * The work event log is the exception, and deliberately so. It is
	 * append-only: nothing is ever updated, so there is no version to write
	 * against and no updated_at to move. A row carrying either would invite
	 * somebody to change history rather than append to it.
	 */
	public function test_the_event_log_is_append_only(): void {
		$events = Schema::definitions()[ Schema::work_events_table() ];

		$this->assertStringNotContainsString( 'record_version', $events );
		$this->assertStringNotContainsString( 'updated_at', $events );
		$this->assertStringContainsString( 'occurred_at', $events );
	}

	/**
	 * Work is scoped to a site (ARCH-3), and the column is not called `site_id`
	 * — core would write an integer into it. Same trap as the integration
	 * record's; the name is avoided as well as defended against.
	 */
	public function test_work_is_scoped_to_a_site(): void {
		$items = Schema::definitions()[ Schema::work_items_table() ];

		$this->assertStringContainsString( 'client_site_id varchar(32) NOT NULL', $items );
		$this->assertStringContainsString( 'KEY client_site_id (client_site_id)', $items );
		$this->assertStringNotContainsString( "\tsite_id ", $items );
	}

	/**
	 * A site row names its client, and that column is indexed: every scoped
	 * query in Milestone 2 and after reaches a site through its client.
	 */
	public function test_a_site_is_indexed_by_its_client(): void {
		$sites = Schema::definitions()[ Schema::sites_table() ];

		$this->assertStringContainsString( 'client_id varchar(32) NOT NULL', $sites );
		$this->assertStringContainsString( 'KEY client_id (client_id)', $sites );
	}

	/**
	 * One integration per site, enforced by the database rather than by callers
	 * remembering: two rows would mean two keys both able to sign as one site,
	 * and nothing to say which one the studio meant.
	 */
	public function test_a_site_can_only_have_one_integration(): void {
		$integrations = Schema::definitions()[ Schema::integrations_table() ];

		$this->assertStringContainsString( 'client_site_id varchar(32) NOT NULL', $integrations );
		$this->assertStringContainsString( 'UNIQUE KEY client_site_id (client_site_id)', $integrations );
	}

	/**
	 * The signing key is not among the integration's columns. ARCH-6 keeps it in
	 * the register, issued once and never read back, and #89 does not move it.
	 */
	public function test_the_integration_does_not_store_the_key(): void {
		$integrations = Schema::definitions()[ Schema::integrations_table() ];

		$this->assertStringNotContainsString( 'signing_key', $integrations );
		$this->assertStringNotContainsString( "\tkey ", $integrations );
	}

	/**
	 * One person is one row, whatever number of clients they work with
	 * (AUTH-6). The index is what makes that true: without it the second
	 * invitation to somebody who already has an account creates a second
	 * person, and capacity counts them twice for ever after.
	 */
	public function test_one_person_cannot_become_two(): void {
		$users = Schema::definitions()[ Schema::users_table() ];

		$this->assertStringContainsString( 'UNIQUE KEY email (email)', $users );
	}

	/**
	 * And holds one role in one place: one row per user, client and site, so
	 * #91 never has two answers to choose between.
	 */
	public function test_a_person_holds_one_role_in_one_place(): void {
		$memberships = Schema::definitions()[ Schema::memberships_table() ];

		$this->assertStringContainsString( 'UNIQUE KEY user_client_site (user_id,client_id,client_site_id)', $memberships );
		$this->assertStringContainsString( 'role varchar(32) NOT NULL', $memberships );
	}

	/**
	 * #93. The cross-client grant sits on the person rather than on a
	 * membership, because its whole meaning is that it is not held with one
	 * client. Putting it on a membership would mean holding it once per client,
	 * which is the opposite of what it says.
	 */
	public function test_the_cross_client_grant_lives_on_the_person(): void {
		$users = Schema::definitions()[ Schema::users_table() ];

		$this->assertStringContainsString( "grants varchar(191) NOT NULL DEFAULT ''", $users );
	}

	/**
	 * #95. A client's contact is a row with a start and an end, not a column
	 * that gets overwritten — the history is the requirement, and a column has
	 * none.
	 */
	public function test_a_clients_contact_is_a_row_rather_than_a_column(): void {
		$contacts = Schema::definitions()[ Schema::contacts_table() ];

		$this->assertStringContainsString( 'client_id varchar(32) NOT NULL', $contacts );
		$this->assertStringContainsString( 'user_id varchar(32) NOT NULL', $contacts );
		$this->assertStringContainsString( 'started_at bigint(20) unsigned NOT NULL', $contacts );
	}

	/**
	 * Nothing ends a contact. A change appends the next assignment, and the
	 * current contact is the latest row — so the table is append-only like the
	 * event log, and for the same reason: a column that gets closed is a column
	 * somebody can reopen.
	 */
	public function test_a_contact_assignment_is_never_updated(): void {
		$contacts = Schema::definitions()[ Schema::contacts_table() ];

		$this->assertStringNotContainsString( 'record_version', $contacts );
		$this->assertStringNotContainsString( 'updated_at', $contacts );
	}

	/**
	 * And the current one is found by an index rather than by reading them all:
	 * "who is the contact" is asked on every client screen.
	 */
	public function test_the_current_contact_is_indexed(): void {
		$contacts = Schema::definitions()[ Schema::contacts_table() ];

		$this->assertStringContainsString( 'KEY client_started (client_id,started_at)', $contacts );
	}

	/**
	 * Resets the option and table state a maybe_upgrade() test depends on, so
	 * one test's fixture cannot leak into the next.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['bwx_forge_test_options']         = array();
		$GLOBALS['bwx_forge_test_calls']           = array();
		$GLOBALS['bwx_forge_test_existing_tables'] = array();
	}

	/**
	 * A site that has never seen the plugin, and whose CREATE succeeds, ends up
	 * with both tables and the version recorded — this is activation, and it is
	 * also the in-place upgrade case once the stored option is behind the code.
	 */
	public function test_maybe_upgrade_builds_the_tables_and_records_the_version(): void {
		$GLOBALS['bwx_forge_test_existing_tables'] = array_keys( Schema::definitions() );

		Schema::maybe_upgrade();

		$calls = array_column( $GLOBALS['bwx_forge_test_calls'], 0 );
		$this->assertContains( 'dbDelta', $calls );
		$this->assertSame( Schema::VERSION, get_option( Schema::OPTION ) );
	}

	/**
	 * A site whose stored schema version is behind the code's runs dbDelta()
	 * again on the next request — this is the in-place update path, which
	 * activation alone would never trigger.
	 */
	public function test_maybe_upgrade_reruns_when_the_stored_version_is_behind(): void {
		update_option( Schema::OPTION, Schema::VERSION - 1 );
		$GLOBALS['bwx_forge_test_existing_tables'] = array_keys( Schema::definitions() );

		Schema::maybe_upgrade();

		$calls = array_column( $GLOBALS['bwx_forge_test_calls'], 0 );
		$this->assertContains( 'dbDelta', $calls );
		$this->assertSame( Schema::VERSION, get_option( Schema::OPTION ) );
	}

	/**
	 * A site already at the current version never calls dbDelta() at all — the
	 * ordinary case is one option read.
	 */
	public function test_maybe_upgrade_does_nothing_when_already_current(): void {
		update_option( Schema::OPTION, Schema::VERSION );

		Schema::maybe_upgrade();

		$this->assertSame( array(), $GLOBALS['bwx_forge_test_calls'] );
	}

	/**
	 * When the CREATE ran but a table did not actually land — dbDelta() failing
	 * silently, or one statement erroring — the version must not be stored, or
	 * the site is marked current and never retries.
	 */
	public function test_maybe_upgrade_leaves_the_version_unset_when_a_table_is_missing(): void {
		$GLOBALS['bwx_forge_test_existing_tables'] = array( Schema::clients_table() );

		Schema::maybe_upgrade();

		$this->assertFalse( get_option( Schema::OPTION, false ) );
	}
}
