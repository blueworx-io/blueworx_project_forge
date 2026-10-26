<?php
/**
 * A synced meeting, as the rest of Forge reads it.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Google\Meetings;
use PHPUnit\Framework\TestCase;

/**
 * #657. A client Forge filled in by itself — remembered from the series, or
 * studio time for an all-staff meeting — is not somebody dealing with it.
 */
final class GoogleMeetingsHydrateTest extends TestCase {

	/**
	 * A stored row.
	 *
	 * @param array<string, mixed> $overrides Columns to change.
	 * @return array<string, mixed>
	 */
	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'             => 'gmt_1',
				'share_key'      => 'k',
				'series_key'     => 's',
				'prefill'        => '',
				'title'          => 'Sprint',
				'attendees'      => '[]',
				'on_date'        => '2026-10-27',
				'ends_on'        => '',
				'at_time'        => '10:00',
				'starts_at'      => 0,
				'ends_at'        => 0,
				'all_day'        => 0,
				'private'        => 0,
				'planned_hours'  => '1.00',
				'client_site_id' => 'cst_w',
				'client_id'      => 'cli_w',
				'off_support'    => 0,
				'status'         => 'scheduled',
				'ledger_state'   => 'forecast',
				'held_marked_by' => 0,
				'held_at'        => 0,
				'record_version' => 1,
			),
			$overrides
		);
	}

	public function test_a_prefilled_meeting_is_not_settled(): void {
		$this->assertFalse( Meetings::hydrate( $this->row( array( 'prefill' => 'series' ) ) )['settled'] );
		$this->assertTrue( Meetings::hydrate( $this->row() )['settled'] );
		$this->assertTrue( Meetings::hydrate( $this->row( array( 'prefill' => 'studio', 'status' => 'held' ) ) )['settled'] );
	}

	public function test_it_carries_its_series_and_whether_forge_filled_it_in(): void {
		$meeting = Meetings::hydrate( $this->row( array( 'prefill' => 'studio' ) ) );

		$this->assertSame( 's', $meeting['series_key'] );
		$this->assertSame( 'studio', $meeting['prefill'] );
	}

	public function test_the_tables_keep_the_series_and_its_choice(): void {
		$definitions = Schema::definitions();

		$this->assertStringContainsString( "series_key varchar(32) NOT NULL DEFAULT ''", $definitions[ Schema::google_meetings_table() ] );
		$this->assertStringContainsString( "prefill varchar(10) NOT NULL DEFAULT ''", $definitions[ Schema::google_meetings_table() ] );
		$this->assertArrayHasKey( Schema::google_series_table(), $definitions );
	}
}
