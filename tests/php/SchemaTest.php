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

		$this->assertCount( 26, $definitions );

		// The append-only tables are the exception, for the reason spelled out
		// in the next test: nothing ever updates a row in them. The dependency
		// table is the other kind of exception — a row is created and removed
		// and never edited, so there is no version for a write to quote. The
		// two capacity tables are both: an hours correction appends another
		// statement rather than editing the one that was wrong, and a leave
		// record is added or taken away and never amended.
		//
		// A site's onboarding assignment is the same kind of thing: it says
		// which checklist a client was given on the day they were given it, and
		// that never becomes untrue (#160). A step's history is append-only for
		// the same reason every other history here is.
		$append_only = array(
			Schema::work_events_table(),
			Schema::gate_records_table(),
			Schema::comments_table(),
			Schema::contacts_table(),
			Schema::dependencies_table(),
			Schema::availability_patterns_table(),
			Schema::unavailability_table(),
			Schema::site_onboarding_table(),
			Schema::onboarding_step_events_table(),
			// Evidence is the same (#168): replacing an attachment writes
			// another row and leaves the first, so the submission history still
			// shows what was sent the first time.
			Schema::onboarding_evidence_table(),

			/*
			 * A notification event (#172) is written once and settled once, and
			 * carries no version on purpose. ARCH-5's version exists to refuse a
			 * write made against a stale copy, and there is no such write here:
			 * the only race is over creating the row at all, and the primary key
			 * settles that one — which is the whole mechanism. A version column
			 * would suggest an edit somebody could lose, and there is none.
			 */
			Schema::notification_events_table(),

			/*
			 * A package version (#145) is the strongest case of the lot. It is
			 * the terms a client was sold, frozen at the moment they were sold
			 * them, and COMM-1 is the rule the whole commercial record rests
			 * on: editing a package appends the next version and leaves this
			 * row exactly as it is. A record_version would say a write might
			 * come, and none may.
			 */
			Schema::package_versions_table(),

			/*
			 * The hour ledger (#148) is the strictest of the lot. A balance is
			 * the sum of its entries and nothing else, so a correction is
			 * another entry with a reason on it — there is no row anybody may
			 * write twice, and no stored total to drift away from what the
			 * entries add up to.
			 */
			Schema::hour_ledger_table(),
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
	 * A package version is frozen the moment it exists (#145, COMM-1).
	 *
	 * The catalogue row next to it is freely editable and carries everything an
	 * edit needs; this one carries no version and no updated_at, because a
	 * column suggesting it could be written twice is an invitation to rewrite
	 * what a client was sold. The pair is the feature.
	 */
	public function test_a_package_version_cannot_be_edited(): void {
		$version = Schema::definitions()[ Schema::package_versions_table() ];

		$this->assertStringNotContainsString( 'record_version', $version );
		$this->assertStringNotContainsString( 'updated_at', $version );

		// One row per package per number, enforced by the index rather than by
		// whoever calls: two rows claiming to be version 3 would leave "which
		// terms is this client on" with two answers.
		$this->assertStringContainsString( 'UNIQUE KEY package_version (package_id, version)', $version );

		$catalogue = Schema::definitions()[ Schema::packages_table() ];

		$this->assertStringContainsString( 'record_version', $catalogue );
		$this->assertStringContainsString( 'updated_at', $catalogue );
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
	/**
	 * The onboarding tables exist, and the template is the one that is not
	 * scoped to anybody (#159, #160, #161).
	 */
	public function test_onboarding_has_its_tables_and_the_template_belongs_to_nobody(): void {
		$definitions = Schema::definitions();

		foreach (
			array(
				Schema::onboarding_templates_table(),
				Schema::onboarding_template_steps_table(),
				Schema::site_onboarding_table(),
				Schema::onboarding_steps_table(),
				Schema::onboarding_step_events_table(),
			) as $table
		) {
			$this->assertArrayHasKey( $table, $definitions );
		}

		$templates = $definitions[ Schema::onboarding_templates_table() ];

		// A template is the studio's. A client or site column here would make
		// it somebody's, and the assignment in #160 pointless.
		$this->assertStringNotContainsString( 'client_id', $templates );
		$this->assertStringNotContainsString( 'client_site_id', $templates );
	}

	/**
	 * ONB-3, written as a test rather than as a comment. Forge stores which
	 * provider, which account and whether access was verified — and has
	 * nowhere at all to put the secret itself. A rule in a controller can be
	 * forgotten by the next caller; a column that does not exist cannot.
	 */
	public function test_an_onboarding_step_has_nowhere_to_put_a_credential(): void {
		$steps = Schema::definitions()[ Schema::onboarding_steps_table() ];

		foreach ( array( 'password', 'secret', 'token', 'credential', 'api_key', 'private_key' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $steps );
		}

		// What it does store instead.
		$this->assertStringContainsString( 'provider', $steps );
		$this->assertStringContainsString( 'invitation_status', $steps );
		$this->assertStringContainsString( 'verification_outcome', $steps );
	}

	/**
	 * #168. Evidence carries its own site, so that isolating one client's files
	 * from another's is a WHERE clause rather than a check somebody has to
	 * remember to write in each new caller.
	 */
	public function test_evidence_carries_the_site_it_belongs_to(): void {
		$evidence = Schema::definitions()[ Schema::onboarding_evidence_table() ];

		$this->assertStringContainsString( 'client_site_id varchar(32) NOT NULL', $evidence );
		$this->assertStringContainsString( 'KEY step_site (step_id, client_site_id)', $evidence );

		// The name on disk and the name a person recognises are two different
		// columns, because only one of them is ever a path.
		$this->assertStringContainsString( 'stored_name', $evidence );
		$this->assertStringContainsString( 'original_name', $evidence );
	}

	/**
	 * Overdue is worked out, never written down (#161). A column here would
	 * need a nightly sweep to stay true, and would be wrong in between.
	 */
	public function test_a_step_does_not_store_whether_it_is_late(): void {
		$this->assertStringNotContainsString( 'overdue', Schema::definitions()[ Schema::onboarding_steps_table() ] );
	}

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
