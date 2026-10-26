<?php
/**
 * What Forge fills in on a synced meeting by itself.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Google\Meetings;
use PHPUnit\Framework\TestCase;

/**
 * #657. A recurring meeting takes the client last picked for its series, and
 * an all-staff one is studio time — but never over a person's pick, and never
 * once somebody has said what became of it.
 */
final class GooglePrefillTest extends TestCase {

	/**
	 * A stored row.
	 *
	 * @param array<string, mixed> $overrides Columns to change.
	 * @return array<string, mixed>
	 */
	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'client_site_id' => '',
				'client_id'      => '',
				'off_support'    => 0,
				'prefill'        => '',
				'status'         => 'scheduled',
			),
			$overrides
		);
	}

	/**
	 * A series' remembered choice.
	 *
	 * @return array<string, mixed>
	 */
	private function choice(): array {
		return array(
			'client_site_id' => 'cst_w',
			'client_id'      => 'cli_w',
			'off_support'    => true,
		);
	}

	public function test_a_series_choice_fills_an_empty_meeting(): void {
		$this->assertSame(
			array(
				'client_site_id' => 'cst_w',
				'client_id'      => 'cli_w',
				'off_support'    => 1,
				'prefill'        => 'series',
			),
			Meetings::prefill_changes( $this->row(), $this->choice(), false, 'cst_s', 'cli_s' )
		);
	}

	public function test_a_persons_pick_is_never_overwritten(): void {
		$this->assertSame( array(), Meetings::prefill_changes( $this->row( array( 'client_site_id' => 'cst_x', 'client_id' => 'cli_x' ) ), $this->choice(), true, 'cst_s', 'cli_s' ) );
	}

	public function test_a_settled_outcome_is_never_overwritten(): void {
		$this->assertSame( array(), Meetings::prefill_changes( $this->row( array( 'status' => 'held', 'prefill' => 'series', 'client_site_id' => 'cst_old', 'client_id' => 'cli_old' ) ), $this->choice(), false, 'cst_s', 'cli_s' ) );
	}

	public function test_the_series_choice_beats_studio_time(): void {
		$this->assertSame( 'series', Meetings::prefill_changes( $this->row(), $this->choice(), true, 'cst_s', 'cli_s' )['prefill'] );
	}

	public function test_studio_time_goes_when_it_stops_being_internal(): void {
		$this->assertSame(
			array(
				'client_site_id' => '',
				'client_id'      => '',
				'prefill'        => '',
			),
			Meetings::prefill_changes( $this->row( array( 'client_site_id' => 'cst_s', 'client_id' => 'cli_s', 'prefill' => 'studio' ) ), null, false, 'cst_s', 'cli_s' )
		);
	}

	public function test_an_internal_meeting_fills_in_as_studio_time(): void {
		$this->assertSame(
			array(
				'client_site_id' => 'cst_s',
				'client_id'      => 'cli_s',
				'prefill'        => 'studio',
			),
			Meetings::prefill_changes( $this->row(), null, true, 'cst_s', 'cli_s' )
		);
	}

	public function test_nothing_to_fill_in_changes_nothing(): void {
		$this->assertSame( array(), Meetings::prefill_changes( $this->row(), null, false, 'cst_s', 'cli_s' ) );
	}
}
