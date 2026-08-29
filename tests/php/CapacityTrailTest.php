<?php
/**
 * What a change to somebody's time leaves behind.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Capacity\Trail;
use PHPUnit\Framework\TestCase;

/**
 * #144, CAP-E5. Nothing is recalculated, because nothing is stored — every
 * capacity figure is read from the work items and the availability records when
 * it is asked for, so none of them can be stale.
 *
 * What was missing is the record. Somebody looking at a week that has turned
 * red needs to find out why without guessing, and nothing in an item's history
 * said that a person's hours moved underneath it.
 */
final class CapacityTrailTest extends TestCase {

	/**
	 * An allocation as Commitments hands it over.
	 *
	 * @param string $item_id Which work.
	 * @param string $site_id Which site.
	 * @param string $user_id Whose time.
	 * @return array<string, mixed>
	 */
	private function allocation( string $item_id, string $site_id, string $user_id ): array {
		return array(
			'item_id'        => $item_id,
			'client_site_id' => $site_id,
			'user_id'        => $user_id,
			'from'           => '2026-09-07',
			'to'             => '2026-09-11',
		);
	}

	public function test_the_work_a_change_touches_is_that_persons_live_work(): void {
		$touched = Trail::work_touching(
			array(
				$this->allocation( 'wrk_one', 'sit_one', 'usr_a' ),
				$this->allocation( 'wrk_two', 'sit_two', 'usr_b' ),
			),
			'usr_a'
		);

		$this->assertSame( array( 'wrk_one' => 'sit_one' ), $touched );
	}

	public function test_two_seats_on_one_item_is_one_piece_of_work(): void {
		// Its picture changed once. Two entries would read as two events.
		$touched = Trail::work_touching(
			array(
				$this->allocation( 'wrk_one', 'sit_one', 'usr_a' ),
				$this->allocation( 'wrk_one', 'sit_one', 'usr_a' ),
			),
			'usr_a'
		);

		$this->assertCount( 1, $touched );
	}

	public function test_a_change_for_somebody_with_no_live_work_touches_nothing(): void {
		$this->assertSame( array(), Trail::work_touching( array(), 'usr_a' ) );
		$this->assertSame(
			array(),
			Trail::work_touching( array( $this->allocation( 'wrk_one', 'sit_one', 'usr_b' ) ), 'usr_a' )
		);
	}

	public function test_the_window_a_leave_record_disturbs_is_its_own_dates(): void {
		$this->assertSame( array( '2026-09-07', '2026-09-11' ), Trail::window( '2026-09-07', '2026-09-11' ) );
	}

	public function test_an_hours_change_disturbs_everything_from_its_date_onwards(): void {
		/*
		 * An hours change has no end date — it stands until the next one. A
		 * year is far enough ahead to cover anything anybody has actually
		 * planned, and stops the query reading the whole future.
		 */
		$this->assertSame( array( '2026-09-07', '2027-09-07' ), Trail::window( '2026-09-07', '' ) );
	}
}
