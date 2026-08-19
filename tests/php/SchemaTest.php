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
}
