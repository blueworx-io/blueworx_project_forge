<?php
/**
 * Tests for the one-off Designer upgrade.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\DesignerUpgrade;
use PHPUnit\Framework\TestCase;

/**
 * #409. Existing tasks keep Design as it was: the person doing the work
 * becomes the Designer on open work not yet past Design.
 */
final class DesignerUpgradeTest extends TestCase {

	/**
	 * A task row.
	 *
	 * @param string               $id   Id.
	 * @param array<string, mixed> $over Anything to set.
	 * @return array<string, mixed>
	 */
	private function row( string $id, array $over = array() ): array {
		return array_merge(
			array(
				'id'               => $id,
				'stage'            => 'technical-audit',
				'prior_stage'      => '',
				'primary_user_id'  => 'usr_p',
				'designer_id'      => '',
				'cycle'            => 1,
				'archived'         => 0,
				'terminal_outcome' => '',
			),
			$over
		);
	}

	public function test_open_work_up_to_design_gets_its_doer_as_designer(): void {
		$picks = DesignerUpgrade::picks(
			array(
				$this->row( 'wrk_idea', array( 'stage' => 'future-idea' ) ),
				$this->row( 'wrk_design', array( 'stage' => 'design-process' ) ),
				$this->row( 'wrk_blocked', array( 'stage' => 'blocked', 'prior_stage' => 'triage' ) ),
			),
			array()
		);

		$this->assertSame(
			array(
				'wrk_idea'    => 'usr_p',
				'wrk_design'  => 'usr_p',
				'wrk_blocked' => 'usr_p',
			),
			$picks
		);
	}

	public function test_work_past_design_ended_or_already_named_is_left_alone(): void {
		$picks = DesignerUpgrade::picks(
			array(
				$this->row( 'wrk_past', array( 'stage' => 'up-next' ) ),
				$this->row( 'wrk_blocked_past', array( 'stage' => 'blocked', 'prior_stage' => 'in-development' ) ),
				$this->row( 'wrk_ended', array( 'terminal_outcome' => 'rejected' ) ),
				$this->row( 'wrk_archived', array( 'archived' => 1 ) ),
				$this->row( 'wrk_named', array( 'designer_id' => 'usr_d' ) ),
				$this->row( 'wrk_nobody', array( 'primary_user_id' => '' ) ),
			),
			array()
		);

		$this->assertSame( array(), $picks );
	}

	public function test_a_design_not_applicable_this_cycle_keeps_it_skipped(): void {
		$picks = DesignerUpgrade::picks(
			array(
				$this->row( 'wrk_na' ),
				$this->row( 'wrk_old_na', array( 'cycle' => 2 ) ),
			),
			array(
				array(
					'item_id' => 'wrk_na',
					'cycle'   => 1,
				),
				array(
					'item_id' => 'wrk_old_na',
					'cycle'   => 1,
				),
			)
		);

		$this->assertSame( array( 'wrk_old_na' => 'usr_p' ), $picks );
	}
}
