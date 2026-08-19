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
	 * Both tables are defined, and each carries the columns everything later
	 * depends on: the id, the author and time stamps, and the record version
	 * that ARCH-5 refuses stale writes against.
	 */
	public function test_both_tables_carry_the_common_columns(): void {
		$definitions = Schema::definitions();

		$this->assertCount( 2, $definitions );

		foreach ( $definitions as $sql ) {
			$this->assertStringContainsString( 'id varchar(32) NOT NULL', $sql );
			$this->assertStringContainsString( 'record_version', $sql );
			$this->assertStringContainsString( 'created_at', $sql );
			$this->assertStringContainsString( 'updated_at', $sql );
			$this->assertStringContainsString( 'created_by', $sql );
			$this->assertStringContainsString( 'PRIMARY KEY  (id)', $sql );
		}
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
		$GLOBALS['bwx_forge_test_existing_tables'] = array( Schema::clients_table(), Schema::sites_table() );

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
		$GLOBALS['bwx_forge_test_existing_tables'] = array( Schema::clients_table(), Schema::sites_table() );

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
