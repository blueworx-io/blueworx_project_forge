<?php
/**
 * The one-time import of the open ClickUp work (#346).
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\ClickUpImport;
use Blueworx\Forge\Work\Levels;
use Blueworx\Forge\Work\Stages;
use Blueworx\Forge\Work\Types;
use PHPUnit\Framework\TestCase;

final class ClickUpImportTest extends TestCase {

	private function people(): array {
		return array(
			'luke.mcfarland@babyblue.info' => 'usr_luke',
			'ross.nelson@blueworx.io'      => 'usr_ross',
		);
	}

	private function row(): array {
		return array(
			'clickup_id'     => '869f1p4ar',
			'clickup_status' => 'triage',
			'clickup_list'   => 'BlueWorx: Labs > Forge Management',
			'stage'          => 'triage',
			'title'          => 'Forge: Connect to Slack',
			'work_type'      => 'feature',
			'problem'        => 'Post the morning summary.',
			'references'     => 'https://app.clickup.com/t/869f1p4ar',
			'priority'       => 'high',
			'primary'        => 'luke.mcfarland@babyblue.info',
			'reviewer'       => 'ross.nelson@blueworx.io',
			'planned_start'  => '2026-09-14',
			'planned_due'    => '2026-09-25',
			'hours_primary'  => 6,
		);
	}

	public function test_it_runs_once_on_the_studio_site_only(): void {
		self::assertTrue( ClickUpImport::should_run( 'blueworx.io', false, 'cst_1' ) );
		self::assertFalse( ClickUpImport::should_run( 'blueworx.io', '2026-09-15 10:00', 'cst_1' ), 'already run' );
		self::assertFalse( ClickUpImport::should_run( '127.0.0.1', false, 'cst_1' ), 'a test site' );
		self::assertFalse( ClickUpImport::should_run( 'www.blueworx.io', false, 'cst_1' ), 'another host' );
		self::assertFalse( ClickUpImport::should_run( 'blueworx.io', false, '' ), 'no studio site yet' );
	}

	public function test_a_row_becomes_work_with_its_people_resolved_by_email(): void {
		$values = ClickUpImport::values( $this->row(), $this->people() );

		self::assertSame( 'Forge: Connect to Slack', $values['title'] );
		self::assertSame( Levels::FEATURE, $values['level'] );
		self::assertSame( Types::FEATURE, $values['work_type'] );
		self::assertSame( 'Post the morning summary.', $values['problem'] );
		self::assertSame( 'https://app.clickup.com/t/869f1p4ar', $values['references'] );
		self::assertSame( 'high', $values['priority'] );
		self::assertSame( 'usr_luke', $values['primary_user_id'] );
		self::assertSame( 'usr_ross', $values['reviewer_id'] );
		self::assertSame( '2026-09-14', $values['planned_start'] );
		self::assertSame( '2026-09-25', $values['planned_due'] );
		self::assertSame( 6.0, $values['hours_primary'] );
		self::assertArrayNotHasKey( 'stage', $values, 'the stage is placed, not written' );
	}

	public function test_a_person_forge_does_not_know_leaves_the_seat_empty(): void {
		$values = ClickUpImport::values( array_merge( $this->row(), array( 'reviewer' => 'nobody@example.com' ) ), $this->people() );

		self::assertSame( 'usr_luke', $values['primary_user_id'] );
		self::assertSame( '', $values['reviewer_id'] );
	}

	public function test_the_history_says_where_each_item_came_from(): void {
		self::assertSame(
			'Imported from ClickUp (was "triage" in BlueWorx: Labs > Forge Management).',
			ClickUpImport::why( $this->row() )
		);
	}

	public function test_the_shipped_file_holds_the_open_work_in_stages_forge_knows(): void {
		$rows = ClickUpImport::rows();

		self::assertCount( 31, $rows );

		foreach ( $rows as $row ) {
			self::assertTrue( Stages::exists( $row['stage'] ), $row['title'] );
			self::assertTrue( Stages::may_hold( $row['stage'], $row['work_type'] ), $row['title'] );
			self::assertNotSame( '', $row['primary'], $row['title'] );
			self::assertNotSame( $row['primary'], $row['reviewer'], $row['title'] );
		}

		$ids = array_column( $rows, 'clickup_id' );
		self::assertSame( $ids, array_unique( $ids ), 'no ClickUp task twice' );
	}
}
