<?php
/**
 * A site's work, narrowed in the query itself (#609, #616).
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\Events;
use Blueworx\Forge\Work\Items;
use PHPUnit\Framework\TestCase;

final class ItemsNarrowingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['bwx_forge_test_calls'] = array();
	}

	/** The one query that went to the database. */
	private function asked(): string {
		$reads = array_values( array_filter( $GLOBALS['bwx_forge_test_calls'], static fn( array $call ): bool => 'get_results' === $call[0] ) );

		self::assertCount( 1, $reads );

		return (string) $reads[0][1];
	}

	public function test_several_levels_are_asked_for_together(): void {
		Items::for_site( 'cs_1', array( 'level' => array( 'project', 'milestone' ) ) );

		self::assertStringContainsString( "level IN ('project', 'milestone')", $this->asked() );
	}

	public function test_one_level_is_asked_for_as_before(): void {
		Items::for_site( 'cs_1', array( 'level' => 'project' ) );

		self::assertStringContainsString( "level = 'project'", $this->asked() );
	}

	public function test_an_empty_list_narrows_nothing(): void {
		Items::for_site( 'cs_1', array( 'level' => array( '' ) ) );

		self::assertStringNotContainsString( 'level', $this->asked() );
	}

	public function test_when_work_was_released_is_read_for_a_whole_site_at_once(): void {
		self::assertSame( array(), Events::entered_on_site( 'cs_1', 'released' ) );

		$query = $this->asked();
		self::assertStringContainsString( "client_site_id = 'cs_1' AND to_stage = 'released' GROUP BY item_id", $query );
	}
}
