<?php
/**
 * One person, every client, counted once.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Capacity\ClientAnswer;
use Blueworx\Forge\Capacity\Commitments;
use Blueworx\Forge\Capacity\Position;
use PHPUnit\Framework\TestCase;

/**
 * The cross-client figure (#138).
 *
 * The failure this exists to stop is a person looking free on one client while
 * committed on another, so the case that matters is the one where the same
 * person appears on two clients' work at once.
 */
final class CapacityCommitmentsTest extends TestCase {

	/**
	 * One allocation, as Allocations::from_item produces them.
	 *
	 * @param string $user_id Person.
	 * @param float  $hours   Hours.
	 * @param string $client  Client id.
	 * @return array<string, mixed>
	 */
	private function allocation( string $user_id, float $hours, string $client ): array {
		return array(
			'item_id'        => 'itm_' . $client,
			'title'          => 'Work for ' . $client,
			'client_id'      => $client,
			'client_site_id' => 'cst_' . $client,
			'role'           => 'primary',
			'user_id'        => $user_id,
			'covering'       => '',
			'hours'          => $hours,
			'from'           => '2026-09-07',
			'to'             => '2026-09-08',
		);
	}

	/**
	 * Two working days, as Availability reports them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function days(): array {
		return array(
			array(
				'date'       => '2026-09-07',
				'hours'      => 8.0,
				'base_hours' => 8.0,
				'reason'     => '',
			),
			array(
				'date'       => '2026-09-08',
				'hours'      => 8.0,
				'base_hours' => 8.0,
				'reason'     => '',
			),
		);
	}

	public function test_two_clients_make_one_combined_commitment(): void {
		$gathered = Commitments::gather(
			array(
				$this->allocation( 'usr_a', 6.0, 'cli_1' ),
				$this->allocation( 'usr_a', 4.0, 'cli_2' ),
			),
			array( 'usr_a' => $this->days() )
		);

		$this->assertSame( 10.0, $gathered['usr_a']['hours'], 'one person, one total' );
		$this->assertCount( 2, $gathered['usr_a']['allocations'], 'and both pieces of work behind it' );
		$this->assertSame( 5.0, $gathered['usr_a']['by_day']['2026-09-07'] );
	}

	public function test_people_are_kept_apart(): void {
		$gathered = Commitments::gather(
			array(
				$this->allocation( 'usr_a', 6.0, 'cli_1' ),
				$this->allocation( 'usr_b', 4.0, 'cli_1' ),
			),
			array(
				'usr_a' => $this->days(),
				'usr_b' => $this->days(),
			)
		);

		$this->assertSame( 6.0, $gathered['usr_a']['hours'] );
		$this->assertSame( 4.0, $gathered['usr_b']['hours'] );
	}

	public function test_two_seats_on_one_item_are_two_commitments(): void {
		$primary        = $this->allocation( 'usr_a', 6.0, 'cli_1' );
		$review         = $this->allocation( 'usr_a', 2.0, 'cli_1' );
		$review['role'] = 'review';

		$gathered = Commitments::gather( array( $primary, $review ), array( 'usr_a' => $this->days() ) );

		$this->assertSame( 8.0, $gathered['usr_a']['hours'], 'doing it and reviewing it are both real time' );
	}

	public function test_somebody_with_nothing_on_is_still_answered(): void {
		$gathered = Commitments::gather( array(), array( 'usr_a' => $this->days() ) );

		$this->assertSame( 0.0, $gathered['usr_a']['hours'] );
		$this->assertSame( array(), $gathered['usr_a']['allocations'] );
	}

	public function test_finished_work_keeps_its_day_apart_from_what_is_still_to_do(): void {
		$done           = $this->allocation( 'usr_a', 4.0, 'cli_2' );
		$done['status'] = 'completed';

		$gathered = Commitments::gather(
			array( $this->allocation( 'usr_a', 6.0, 'cli_1' ), $done ),
			array( 'usr_a' => $this->days() )
		);

		$this->assertSame( 6.0, $gathered['usr_a']['hours'], 'still to do' );
		$this->assertSame( 4.0, $gathered['usr_a']['completed'], 'done' );
		$this->assertSame( 3.0, $gathered['usr_a']['by_day']['2026-09-07'] );
		$this->assertSame( 2.0, $gathered['usr_a']['completed_by_day']['2026-09-07'] );
		$this->assertCount( 2, $gathered['usr_a']['allocations'] );
	}

	/*
	 * Finished work used the days that have been, not the days still to
	 * come. A task planned for today and tomorrow, finished today, leaves
	 * tomorrow free — for the screen, the gate and the client's answer alike.
	 */
	public function test_finished_work_only_counts_up_to_today(): void {
		$done           = $this->allocation( 'usr_a', 4.0, 'cli_1' );
		$done['status'] = 'completed';

		$gathered = Commitments::gather( array( $done ), array( 'usr_a' => $this->days() ), '2026-09-07' );

		$this->assertSame( array( '2026-09-07' => 2.0 ), $gathered['usr_a']['completed_by_day'], 'today\'s share only' );
		$this->assertSame( 2.0, $gathered['usr_a']['completed'] );
		$this->assertSame( array( '2026-09-07' => 2.0 ), $gathered['usr_a']['allocations'][0]['by_day'] );

		$tomorrow = Position::over( $this->days(), $gathered['usr_a']['by_day'], '2026-09-08', '2026-09-08', $gathered['usr_a']['completed_by_day'] );

		$this->assertSame( 0.0, $tomorrow['completed'], 'tomorrow shows no finished hours' );
		$this->assertSame( Position::CLEAR, $tomorrow['band'] );
		$this->assertSame( ClientAnswer::ROOM, ClientAnswer::band( array( 'usr_a' => $tomorrow ) ), 'and the client is told there is room' );
	}

	public function test_somebody_nobody_asked_about_is_left_out(): void {
		$gathered = Commitments::gather(
			array( $this->allocation( 'usr_z', 6.0, 'cli_1' ) ),
			array( 'usr_a' => $this->days() )
		);

		$this->assertArrayNotHasKey( 'usr_z', $gathered );
		$this->assertSame( 0.0, $gathered['usr_a']['hours'] );
	}

	/**
	 * Three working days, 7 to 9 September.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function three_days(): array {
		$days = $this->days();

		$days[] = array(
			'date'       => '2026-09-09',
			'hours'      => 8.0,
			'base_hours' => 8.0,
			'reason'     => '',
		);

		return $days;
	}

	/*
	 * #487. Work still to do on days that have gone is still to do, so its
	 * hours move onto today rather than staying on the past.
	 */
	public function test_unfinished_hours_on_past_days_move_to_today(): void {
		$open           = $this->allocation( 'usr_a', 6.0, 'cli_1' );
		$open['to']     = '2026-09-09';
		$open['status'] = 'committed';

		$gathered = Commitments::gather( array( $open ), array( 'usr_a' => $this->three_days() ), '2026-09-08' );

		$this->assertSame( array( '2026-09-08' => 4.0, '2026-09-09' => 2.0 ), $gathered['usr_a']['by_day'] );
		$this->assertSame( 6.0, $gathered['usr_a']['hours'], 'nothing is lost' );
	}

	public function test_overdue_work_lands_wholly_on_today(): void {
		$open           = $this->allocation( 'usr_a', 6.0, 'cli_1' );
		$open['status'] = 'committed';

		$gathered = Commitments::gather( array( $open ), array( 'usr_a' => $this->three_days() ), '2026-09-09' );

		$this->assertSame( array( '2026-09-09' => 6.0 ), $gathered['usr_a']['by_day'] );
		$this->assertSame( array( '2026-09-09' => 6.0 ), $gathered['usr_a']['allocations'][0]['by_day'] );
	}

	public function test_work_due_before_the_window_still_lands_on_today(): void {
		$open           = $this->allocation( 'usr_a', 6.0, 'cli_1' );
		$open['from']   = '2026-09-01';
		$open['to']     = '2026-09-02';
		$open['status'] = 'committed';

		$gathered = Commitments::gather( array( $open ), array( 'usr_a' => $this->three_days() ), '2026-09-08' );

		$this->assertSame( array( '2026-09-08' => 6.0 ), $gathered['usr_a']['by_day'] );
	}

	public function test_a_window_already_past_loses_the_unfinished_hours_to_today(): void {
		$open           = $this->allocation( 'usr_a', 6.0, 'cli_1' );
		$open['status'] = 'committed';

		$gathered = Commitments::gather( array( $open ), array( 'usr_a' => $this->days() ), '2026-09-20' );

		$this->assertSame( array(), $gathered['usr_a']['by_day'], 'they sit on today, outside this window' );
		$this->assertSame( 0.0, $gathered['usr_a']['hours'] );
	}

	/*
	 * #521. Only the days the window shows count. A seat's hours sit on its
	 * own day, which can be outside the window the item was read for.
	 */
	public function test_work_dated_after_the_window_counts_for_none_of_it(): void {
		$ahead           = $this->allocation( 'usr_a', 2.0, 'cli_1' );
		$ahead['from']   = '2026-09-09';
		$ahead['to']     = '2026-09-09';
		$ahead['status'] = 'committed';

		$gathered = Commitments::gather( array( $ahead ), array( 'usr_a' => $this->days() ), '2026-09-07' );

		$this->assertSame( array(), $gathered['usr_a']['by_day'] );
		$this->assertSame( 0.0, $gathered['usr_a']['hours'] );
		$this->assertSame( array(), $gathered['usr_a']['allocations'], 'the panel lists nothing for it either' );
	}

	public function test_finished_work_before_the_window_counts_for_none_of_it(): void {
		$done           = $this->allocation( 'usr_a', 2.0, 'cli_1' );
		$done['from']   = '2026-09-01';
		$done['to']     = '2026-09-01';
		$done['status'] = 'completed';

		$gathered = Commitments::gather( array( $done ), array( 'usr_a' => $this->days() ), '2026-09-08' );

		$this->assertSame( array(), $gathered['usr_a']['completed_by_day'] );
		$this->assertSame( 0.0, $gathered['usr_a']['completed'] );
	}

	public function test_a_past_meeting_stays_on_its_day(): void {
		$meeting = $this->allocation( 'usr_a', 2.0, 'cli_1' );
		$meeting['to'] = '2026-09-07';

		$gathered = Commitments::gather( array( $meeting ), array( 'usr_a' => $this->three_days() ), '2026-09-09' );

		$this->assertSame( array( '2026-09-07' => 2.0 ), $gathered['usr_a']['by_day'] );
	}

	/*
	 * #497. Work with the client keeps the days that have been as used, like
	 * finished work, and leaves the days ahead free.
	 */
	public function test_work_with_the_client_is_time_used_up_to_today(): void {
		$waiting           = $this->allocation( 'usr_a', 6.0, 'cli_1' );
		$waiting['to']     = '2026-09-09';
		$waiting['status'] = 'with-client';

		$gathered = Commitments::gather( array( $waiting ), array( 'usr_a' => $this->three_days() ), '2026-09-08' );

		$this->assertSame( array(), $gathered['usr_a']['by_day'], 'nothing still to do' );
		$this->assertSame( array( '2026-09-07' => 2.0, '2026-09-08' => 2.0 ), $gathered['usr_a']['completed_by_day'] );
		$this->assertSame( 'with-client', $gathered['usr_a']['allocations'][0]['status'] );
	}
}
