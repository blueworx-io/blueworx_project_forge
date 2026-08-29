<?php
/**
 * Whether a move would over-book anybody.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Capacity\Allocations;
use PHPUnit\Framework\TestCase;

/**
 * CAP-E1 and CAP-E2 (#141, #142). The engine answers questions; this is the
 * thing that turns an answer into a refusal, and it is the only one.
 */
final class CapacityImpactTest extends TestCase {

	/**
	 * An item as the database hands it over.
	 *
	 * @param array<string, mixed> $overrides Anything to change.
	 * @return array<string, mixed>
	 */
	private function item( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                      => 'wrk_one',
				'title'                   => 'A job',
				'client_id'               => 'cli_one',
				'client_site_id'          => 'sit_one',
				'stage'                   => 'documentation',
				'prior_stage'             => '',
				'archived'                => 0,
				'terminal_outcome'        => '',
				'planned_start'           => '2026-09-07',
				'planned_due'             => '2026-09-11',
				'primary_user_id'         => 'usr_a',
				'reviewer_id'             => 'usr_b',
				'deliverer_id'            => '',
				'reviewer_substitute_id'  => '',
				'deliverer_substitute_id' => '',
				'hours_primary'           => 10.0,
				'hours_review'            => 2.0,
				'hours_delivery'          => 0.0,
			),
			$overrides
		);
	}

	public function test_an_item_not_yet_committing_still_proposes_its_allocations(): void {
		// The whole reason proposed() exists: from_item() reads the stage and
		// returns nothing, which would let every move through.
		$this->assertSame( array(), Allocations::from_item( $this->item() ) );

		$proposed = Allocations::proposed( $this->item() );

		$this->assertCount( 2, $proposed );
		$this->assertSame( 'usr_a', $proposed[0]['user_id'] );
		$this->assertSame( 10.0, $proposed[0]['hours'] );
		$this->assertSame( 'usr_b', $proposed[1]['user_id'] );
	}

	public function test_archived_or_finished_work_proposes_nothing(): void {
		$this->assertSame( array(), Allocations::proposed( $this->item( array( 'archived' => 1 ) ) ) );
		$this->assertSame( array(), Allocations::proposed( $this->item( array( 'terminal_outcome' => 'cancelled' ) ) ) );
	}
}
