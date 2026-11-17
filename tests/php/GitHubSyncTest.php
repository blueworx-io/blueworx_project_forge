<?php
/**
 * What Forge tells an issue about its task's stage and ending.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\GitHub\Sync;
use Blueworx\Forge\Work\Stages;
use PHPUnit\Framework\TestCase;

/**
 * #682. The label for a stage, and whether an item's issue is open or closed.
 */
final class GitHubSyncTest extends TestCase {

	public function test_the_label_names_the_stage(): void {
		$label = Sync::label_for( 'up-next' );

		$this->assertStringStartsWith( Sync::LABEL_PREFIX, $label );
		$this->assertSame( 'forge: ' . Stages::label( 'up-next' ), $label );
	}

	public function test_released_closes_as_completed(): void {
		$this->assertSame(
			array(
				'state'  => 'closed',
				'reason' => 'completed',
			),
			Sync::state_for(
				array(
					'stage'            => 'released',
					'terminal_outcome' => '',
				)
			)
		);
	}

	public function test_a_closing_outcome_closes_as_not_planned(): void {
		$this->assertSame(
			array(
				'state'  => 'closed',
				'reason' => 'not_planned',
			),
			Sync::state_for(
				array(
					'stage'            => 'in-development',
					'terminal_outcome' => 'cancelled',
				)
			)
		);
	}

	public function test_anything_else_is_open(): void {
		$this->assertSame(
			array(
				'state'  => 'open',
				'reason' => '',
			),
			Sync::state_for(
				array(
					'stage'            => 'in-development',
					'terminal_outcome' => '',
				)
			)
		);
	}
}
