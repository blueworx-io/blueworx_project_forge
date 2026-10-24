<?php
/**
 * What a meeting from Google costs a client, and when.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Commerce\Entries;
use Blueworx\Forge\Google\Hours;
use Blueworx\Forge\Meetings\MeetingHours;
use PHPUnit\Framework\TestCase;

/**
 * #606. The standing meetings' rules, unchanged ({@see MeetingHours}), with
 * two things only a synced meeting has: the client is picked by a person, so
 * the site can change, and its time only comes off support when they say so.
 */
final class GoogleHoursTest extends TestCase {

	private const TODAY = '2026-03-02';

	/**
	 * A settled meeting a week away.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 * @return array<string, mixed>
	 */
	private function meeting( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'             => 'gmt_1',
				'on'             => '2026-03-09',
				'planned_hours'  => 1.5,
				'status'         => 'scheduled',
				'client_site_id' => 'cs_a',
				'off_support'    => true,
			),
			$overrides
		);
	}

	/**
	 * A ledger line against the meeting.
	 *
	 * @param string $site  The site.
	 * @param string $type  Event type.
	 * @param float  $hours Signed hours, as stored.
	 * @return array<string, mixed>
	 */
	private function line( string $site, string $type, float $hours ): array {
		return array(
			'client_site_id' => $site,
			'event_type'     => $type,
			'hours'          => $hours,
		);
	}

	public function test_time_off_support_holds_the_picked_sites_hours_ahead(): void {
		$plan = Hours::plan_for( $this->meeting(), array(), self::TODAY, '' );

		$this->assertSame(
			array(
				array(
					'client_site_id' => 'cs_a',
					'event_type'     => Entries::MEETING_RESERVATION,
					'hours'          => 1.5,
				),
			),
			$plan
		);
	}

	public function test_time_not_off_support_holds_nothing(): void {
		$this->assertSame( array(), Hours::plan_for( $this->meeting( array( 'off_support' => false ) ), array(), self::TODAY, '' ) );
	}

	public function test_no_client_yet_holds_nothing(): void {
		$this->assertSame( array(), Hours::plan_for( $this->meeting( array( 'client_site_id' => '' ) ), array(), self::TODAY, '' ) );
	}

	public function test_turning_support_off_gives_the_held_hours_back(): void {
		$plan = Hours::plan_for(
			$this->meeting( array( 'off_support' => false ) ),
			array( $this->line( 'cs_a', Entries::MEETING_RESERVATION, -1.5 ) ),
			self::TODAY,
			''
		);

		$this->assertSame( Entries::MEETING_RELEASE, $plan[0]['event_type'] );
		$this->assertSame( 1.5, $plan[0]['hours'] );
		$this->assertSame( 'cs_a', $plan[0]['client_site_id'] );
		$this->assertCount( 1, $plan );
	}

	public function test_moving_to_another_client_gives_the_old_sites_hours_back_first(): void {
		$plan = Hours::plan_for(
			$this->meeting( array( 'client_site_id' => 'cs_b' ) ),
			array( $this->line( 'cs_a', Entries::MEETING_RESERVATION, -1.5 ) ),
			self::TODAY,
			''
		);

		$this->assertSame( array( 'cs_a', Entries::MEETING_RELEASE, 1.5 ), array( $plan[0]['client_site_id'], $plan[0]['event_type'], $plan[0]['hours'] ) );
		$this->assertSame( array( 'cs_b', Entries::MEETING_RESERVATION, 1.5 ), array( $plan[1]['client_site_id'], $plan[1]['event_type'], $plan[1]['hours'] ) );
	}

	public function test_held_with_time_off_support_charges_the_client_once(): void {
		$plan = Hours::plan_for(
			$this->meeting(
				array(
					'status' => 'held',
					'on'     => self::TODAY,
				)
			),
			array( $this->line( 'cs_a', Entries::MEETING_RESERVATION, -1.5 ) ),
			self::TODAY,
			''
		);

		$types = array_column( $plan, 'event_type' );
		$this->assertSame( array( Entries::MEETING_RELEASE, Entries::MEETING_USAGE ), $types );
		$this->assertSame( 1.5, $plan[1]['hours'] );

		$again = Hours::plan_for(
			$this->meeting(
				array(
					'status' => 'held',
					'on'     => self::TODAY,
				)
			),
			array(
				$this->line( 'cs_a', Entries::MEETING_RESERVATION, -1.5 ),
				$this->line( 'cs_a', Entries::MEETING_RELEASE, 1.5 ),
				$this->line( 'cs_a', Entries::MEETING_USAGE, -1.5 ),
			),
			self::TODAY,
			''
		);
		$this->assertSame( array(), $again );
	}

	public function test_held_without_time_off_support_charges_nothing(): void {
		$plan = Hours::plan_for(
			$this->meeting(
				array(
					'status'      => 'held',
					'off_support' => false,
				)
			),
			array(),
			self::TODAY,
			''
		);

		$this->assertSame( array(), $plan );
	}

	public function test_a_meeting_beyond_the_term_is_only_forecast(): void {
		$this->assertSame( array(), Hours::plan_for( $this->meeting(), array(), self::TODAY, '2026-03-05' ) );
		$this->assertSame( MeetingHours::FORECAST, Hours::state_for( $this->meeting(), self::TODAY, '2026-03-05' ) );
	}

	public function test_a_meeting_that_has_used_hours_cannot_change_its_client(): void {
		$charged = array( $this->line( 'cs_a', Entries::MEETING_USAGE, -1.5 ) );

		$this->assertFalse( Hours::may_change( $this->meeting(), $charged, 'cs_b', true ) );
		$this->assertFalse( Hours::may_change( $this->meeting(), $charged, 'cs_a', false ) );
		$this->assertTrue( Hours::may_change( $this->meeting(), $charged, 'cs_a', true ) );
		$this->assertTrue( Hours::may_change( $this->meeting(), array(), 'cs_b', false ) );
	}
}
