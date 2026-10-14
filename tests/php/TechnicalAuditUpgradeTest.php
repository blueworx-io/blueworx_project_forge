<?php
/**
 * The one-off move out of Technical Audit.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\Events;
use Blueworx\Forge\Work\Stages;
use Blueworx\Forge\Work\TechnicalAuditUpgrade;
use PHPUnit\Framework\TestCase;

/**
 * #546. Technical Audit is gone, and work that was sitting in it is put in Up
 * Next once, with a history line that says why.
 */
final class TechnicalAuditUpgradeTest extends TestCase {

	public function test_the_stage_is_gone_and_the_work_goes_to_up_next(): void {
		$this->assertFalse( Stages::exists( TechnicalAuditUpgrade::GONE ) );
		$this->assertSame( Stages::UP_NEXT, TechnicalAuditUpgrade::TO );
	}

	public function test_the_history_line_says_where_it_came_from_and_why(): void {
		$entry = TechnicalAuditUpgrade::entry(
			array(
				'id'             => 'wrk_one',
				'client_site_id' => 'cst_one',
				'cycle'          => 2,
				'review_attempt' => 1,
			)
		);

		$this->assertSame( 'wrk_one', $entry['item_id'] );
		$this->assertSame( 'cst_one', $entry['client_site_id'] );
		$this->assertSame( Events::MOVED, $entry['action'] );
		$this->assertSame( 'technical-audit', $entry['from_stage'] );
		$this->assertSame( 'up-next', $entry['to_stage'] );
		$this->assertSame( 2, $entry['cycle'] );
		$this->assertSame( 0, $entry['actor'], 'the update did it, not a person' );
		$this->assertStringContainsString( 'Technical Audit stage was removed', $entry['reason'] );
	}
}
