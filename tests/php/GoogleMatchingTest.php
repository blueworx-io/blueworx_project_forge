<?php
/**
 * Google meetings that are really standing meetings, and what the rest take
 * out of people's weeks.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Capacity\Allocations;
use Blueworx\Forge\Google\Hours;
use Blueworx\Forge\Google\Load;
use Blueworx\Forge\Google\Matching;
use Blueworx\Forge\Google\Meetings;
use PHPUnit\Framework\TestCase;

/**
 * #606. A Google event on the same day as a standing meeting, overlapping
 * its time, with the connected person as its host or an attendee, is the
 * standing meeting — so it is skipped and the meeting counts and charges once.
 */
final class GoogleMatchingTest extends TestCase {

	private const TEN = 1790000000;

	/**
	 * A synced meeting, hydrated.
	 *
	 * @param array<string, mixed>             $overrides Fields to change.
	 * @param array<int, array<string, mixed>> $people    Its copies.
	 * @return array<string, mixed>
	 */
	private function meeting( array $overrides = array(), ?array $people = null ): array {
		$people = $people ?? array(
			array(
				'meeting_id' => 'gmt_1',
				'user_id'    => 'usr_a',
				'response'   => 'accepted',
				'counts'     => 1,
			),
		);

		return Meetings::hydrate(
			array_merge(
				array(
					'id'             => 'gmt_1',
					'share_key'      => str_repeat( 'a', 32 ),
					'title'          => 'Catch-up',
					'description'    => '',
					'attendees'      => '[]',
					'on_date'        => '2026-10-26',
					'ends_on'        => '',
					'at_time'        => '10:00',
					'starts_at'      => self::TEN,
					'ends_at'        => self::TEN + 3600,
					'all_day'        => 0,
					'private'        => 0,
					'planned_hours'  => 1,
					'client_site_id' => '',
					'client_id'      => '',
					'off_support'    => 0,
					'status'         => 'scheduled',
					'ledger_state'   => 'forecast',
					'held_marked_by' => 0,
					'held_at'        => 0,
					'record_version' => 1,
				),
				$overrides
			),
			$people
		);
	}

	/**
	 * A standing meeting's slot.
	 *
	 * @param array<int, string> $people Host and attendees.
	 * @param int                $start  When it starts.
	 * @param string             $on     Its day.
	 * @return array<string, mixed>
	 */
	private function slot( array $people, int $start = self::TEN, string $on = '2026-10-26' ): array {
		return array(
			'people'    => $people,
			'on'        => $on,
			'starts_at' => $start,
			'ends_at'   => $start + 3600,
		);
	}

	public function test_an_overlapping_standing_meeting_the_person_hosts_skips_it(): void {
		$this->assertTrue( Matching::skipped( $this->meeting(), array( $this->slot( array( 'usr_a' ) ) ) ) );
	}

	public function test_an_overlapping_standing_meeting_the_person_attends_skips_it(): void {
		$this->assertTrue( Matching::skipped( $this->meeting(), array( $this->slot( array( 'usr_host', 'usr_a' ) ) ) ) );
	}

	public function test_a_standing_meeting_for_somebody_else_does_not(): void {
		$this->assertFalse( Matching::skipped( $this->meeting(), array( $this->slot( array( 'usr_b' ) ) ) ) );
	}

	public function test_a_standing_meeting_that_does_not_overlap_does_not(): void {
		$this->assertFalse( Matching::skipped( $this->meeting(), array( $this->slot( array( 'usr_a' ), self::TEN + 3600 ) ) ) );
		$this->assertFalse( Matching::skipped( $this->meeting(), array( $this->slot( array( 'usr_a' ), self::TEN, '2026-10-27' ) ) ) );
	}

	public function test_a_meeting_somebody_has_settled_is_never_skipped(): void {
		$settled = $this->meeting( array( 'client_site_id' => 'cs_a' ) );

		$this->assertFalse( Matching::skipped( $settled, array( $this->slot( array( 'usr_a' ) ) ) ) );
	}

	public function test_each_counting_person_carries_the_meetings_hours(): void {
		$meeting = $this->meeting(
			array(),
			array(
				array(
					'meeting_id' => 'gmt_1',
					'user_id'    => 'usr_a',
					'response'   => 'accepted',
					'counts'     => 1,
				),
				array(
					'meeting_id' => 'gmt_1',
					'user_id'    => 'usr_b',
					'response'   => 'accepted',
					'counts'     => 1,
				),
				array(
					'meeting_id' => 'gmt_1',
					'user_id'    => 'usr_c',
					'response'   => 'declined',
					'counts'     => 0,
				),
			)
		);

		$made = Load::allocations( array( $meeting ), array(), 'cl_studio', 'cs_studio' );

		$this->assertSame( array( 'usr_a', 'usr_b' ), array_column( $made, 'user_id' ) );
		$this->assertSame( 1.0, $made[0]['hours'] );
		$this->assertSame( '2026-10-26', $made[0]['from'] );
		$this->assertSame( '2026-10-26', $made[0]['to'] );
		$this->assertSame( 'meeting', $made[0]['role'] );
		$this->assertSame( 'gmt_1', $made[0]['item_id'] );
	}

	public function test_an_unsettled_meeting_counts_as_the_studios_own_time(): void {
		$made = Load::allocations( array( $this->meeting() ), array(), 'cl_studio', 'cs_studio' );

		$this->assertSame( 'cl_studio', $made[0]['client_id'] );
		$this->assertSame( 'cs_studio', $made[0]['client_site_id'] );

		$settled = Load::allocations( array( $this->meeting( array( 'client_site_id' => 'cs_a', 'client_id' => 'cl_a' ) ) ), array(), 'cl_studio', 'cs_studio' );
		$this->assertSame( 'cs_a', $settled[0]['client_site_id'] );
	}

	public function test_all_day_cancelled_and_skipped_meetings_take_no_time(): void {
		$this->assertSame( array(), Load::allocations( array( $this->meeting( array( 'all_day' => 1 ) ) ), array(), 'cl', 'cs' ) );
		$this->assertSame( array(), Load::allocations( array( $this->meeting( array( 'status' => 'cancelled' ) ) ), array(), 'cl', 'cs' ) );
		$this->assertSame( array(), Load::allocations( array( $this->meeting( array( 'status' => 'no-show' ) ) ), array(), 'cl', 'cs' ) );
		$this->assertSame( array(), Load::allocations( array( $this->meeting() ), array( $this->slot( array( 'usr_a' ) ) ), 'cl', 'cs' ) );
	}

	public function test_a_held_meeting_counts_as_done(): void {
		$made = Load::allocations( array( $this->meeting( array( 'status' => 'held', 'client_site_id' => 'cs_a' ) ) ), array(), 'cl', 'cs' );

		$this->assertSame( Allocations::DONE, $made[0]['status'] );
	}

	public function test_a_hidden_prefilled_copy_holds_no_hours(): void {
		$copy = $this->meeting( array( 'client_site_id' => 'cst_x', 'client_id' => 'cli_x', 'prefill' => 'series' ) );

		$this->assertSame( '', Hours::as_held( $copy, array( $this->slot( array( 'usr_a' ) ) ) )['client_site_id'] );
		$this->assertSame( 'cst_x', Hours::as_held( $copy, array() )['client_site_id'] );
	}
}
