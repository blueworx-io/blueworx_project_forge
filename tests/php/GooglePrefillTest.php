<?php
/**
 * What Forge fills in on a synced meeting by itself.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Google\Meetings;
use Blueworx\Forge\Google\SeriesChoice;
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

	public function test_a_series_choice_only_fills_dates_from_where_it_was_made(): void {
		$choice = array_merge( $this->choice(), array( 'from_on' => '2026-11-01' ) );

		$this->assertSame( array(), Meetings::prefill_changes( $this->row( array( 'on_date' => '2026-10-25' ) ), $choice, false, 'cst_s', 'cli_s' ) );
		$this->assertSame( 'series', Meetings::prefill_changes( $this->row( array( 'on_date' => '2026-11-01' ) ), $choice, false, 'cst_s', 'cli_s' )['prefill'] );
	}

	public function test_an_earlier_date_already_filled_from_the_series_keeps_its_client(): void {
		$choice = array_merge( $this->choice(), array( 'from_on' => '2026-11-01' ) );
		$row    = $this->row( array( 'on_date' => '2026-10-25', 'client_site_id' => 'cst_a', 'client_id' => 'cli_a', 'prefill' => 'series' ) );

		$this->assertSame( array(), Meetings::prefill_changes( $row, $choice, false, 'cst_s', 'cli_s' ) );
	}

	public function test_saving_what_forge_filled_in_is_not_a_pick(): void {
		$studio = array( 'client_site_id' => 'cst_s', 'prefill' => 'studio' );

		$this->assertFalse( SeriesChoice::is_a_pick( $studio, 'cst_s' ) );
		$this->assertTrue( SeriesChoice::is_a_pick( $studio, 'cst_w' ) );
		$this->assertTrue( SeriesChoice::is_a_pick( array( 'client_site_id' => 'cst_s', 'prefill' => '' ), 'cst_s' ) );
		$this->assertTrue( SeriesChoice::is_a_pick( array( 'client_site_id' => '', 'prefill' => '' ), 'cst_s' ) );
	}
}
