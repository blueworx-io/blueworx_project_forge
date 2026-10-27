<?php
/**
 * The one-off repair of projects and releases stored as a leaf type.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Work\ProjectTypeUpgrade;
use PHPUnit\Framework\TestCase;

/**
 * #665. Projects and releases saved as a task, bug or feedback item could hold
 * nothing. The update makes them features, once, and touches nothing lower.
 */
final class ProjectTypeUpgradeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['bwx_forge_test_options']         = array();
		$GLOBALS['bwx_forge_test_calls']           = array();
		$GLOBALS['bwx_forge_test_existing_tables'] = array_keys( Schema::definitions() );
	}

	/**
	 * The writes that touch a work item's type.
	 *
	 * @return array<int, string>
	 */
	private function retypes(): array {
		$writes = array_filter( $GLOBALS['bwx_forge_test_calls'], static fn( array $call ): bool => 'query' === $call[0] && false !== strpos( (string) $call[1], 'SET work_type' ) );

		return array_values( array_column( $writes, 1 ) );
	}

	public function test_it_makes_every_project_and_release_a_feature_and_nothing_lower(): void {
		ProjectTypeUpgrade::run();

		$this->assertSame(
			array( "UPDATE wp_bwx_forge_work_items SET work_type = 'feature' WHERE level IN ('project', 'milestone') AND work_type <> 'feature'" ),
			$this->retypes()
		);
	}

	public function test_it_runs_on_a_site_coming_from_before_it(): void {
		update_option( Schema::OPTION, 51 );

		Schema::maybe_upgrade();

		$this->assertCount( 1, $this->retypes() );
	}

	public function test_it_does_not_run_on_a_fresh_site_or_one_already_past_it(): void {
		Schema::maybe_upgrade();
		update_option( Schema::OPTION, Schema::VERSION );
		Schema::maybe_upgrade();

		$this->assertSame( array(), $this->retypes() );
	}
}
