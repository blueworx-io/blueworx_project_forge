<?php
/**
 * Whether a move would over-book anybody.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Capacity\Allocations;
use Blueworx\Forge\Capacity\Impact;
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

	/**
	 * A working week of eight-hour days, Monday to Friday.
	 *
	 * @param string $from  YYYY-MM-DD Monday.
	 * @param int    $days  How many days to produce.
	 * @param float  $hours Hours on each working day.
	 * @return array<int, array{date: string, hours: float, base_hours: float, reason: string}>
	 */
	private function days( string $from, int $days, float $hours = 8.0 ): array {
		$out  = array();
		$time = (int) strtotime( $from . ' 00:00:00 UTC' );

		for ( $i = 0; $i < $days; $i++ ) {
			$at      = $time + ( $i * DAY_IN_SECONDS );
			$weekend = (int) gmdate( 'N', $at ) > 5;

			$out[] = array(
				'date'       => gmdate( 'Y-m-d', $at ),
				'hours'      => $weekend ? 0.0 : $hours,
				'base_hours' => $weekend ? 0.0 : $hours,
				'reason'     => $weekend ? 'non-working-day' : '',
			);
		}

		return $out;
	}

	public function test_a_job_that_fits_over_books_nobody(): void {
		$impact = Impact::assess(
			Allocations::proposed( $this->item() ),
			array(),
			array(
				'usr_a' => $this->days( '2026-09-07', 5 ),
				'usr_b' => $this->days( '2026-09-07', 5 ),
			),
			'2026-09-07',
			'2026-09-11'
		);

		$this->assertSame( array(), $impact['over'] );
		$this->assertTrue( Impact::clear( $impact ) );
	}

	public function test_a_job_bigger_than_the_week_over_books_the_person_doing_it(): void {
		$impact = Impact::assess(
			Allocations::proposed( $this->item( array( 'hours_primary' => 50.0 ) ) ),
			array(),
			array(
				'usr_a' => $this->days( '2026-09-07', 5 ),
				'usr_b' => $this->days( '2026-09-07', 5 ),
			),
			'2026-09-07',
			'2026-09-11'
		);

		$this->assertFalse( Impact::clear( $impact ) );
		$this->assertCount( 1, $impact['over'] );
		$this->assertSame( 'usr_a', $impact['over'][0]['user_id'] );
		$this->assertSame( '2026-09-07', $impact['over'][0]['week_from'] );
		$this->assertSame( 10.0, $impact['over'][0]['excess'] );
	}

	public function test_comfortable_overall_and_impossible_in_one_week_is_over_booked(): void {
		/*
		 * CAP-E2, and the case the whole decision turns on. Two working weeks,
		 * 80 hours available. A fortnight's job of 40 hours spreads evenly, 20
		 * a week. Something else already takes 30 hours, all of it in the first
		 * week.
		 *
		 * Across the job: 70 committed against 80 available, comfortable, let
		 * it through. Week by week: the first week is 50 against 40, and
		 * somebody has a fortnight of work and no time to start it in.
		 */
		$mine = $this->item(
			array(
				'planned_due'   => '2026-09-18',
				'hours_primary' => 40.0,
				'hours_review'  => 0.0,
			)
		);

		$already = $this->item(
			array(
				'id'            => 'wrk_two',
				'stage'         => 'up-next',
				'planned_start' => '2026-09-07',
				'planned_due'   => '2026-09-11',
				'hours_primary' => 30.0,
				'hours_review'  => 0.0,
			)
		);

		$impact = Impact::assess(
			Allocations::proposed( $mine ),
			Allocations::proposed( $already ),
			array( 'usr_a' => $this->days( '2026-09-07', 12 ) ),
			'2026-09-07',
			'2026-09-18'
		);

		$this->assertFalse( Impact::clear( $impact ) );
		$this->assertCount( 1, $impact['over'], 'Only the first week is over.' );
		$this->assertSame( '2026-09-07', $impact['over'][0]['week_from'] );
		$this->assertSame( 10.0, $impact['over'][0]['excess'] );
	}

	public function test_a_person_nobody_has_set_up_is_not_over_booked(): void {
		// The gate must not refuse a move because an admin screen is unfilled.
		$blank = array_map(
			static function ( array $day ): array {
				return array_merge( $day, array( 'hours' => 0.0, 'base_hours' => 0.0, 'reason' => 'no-pattern' ) );
			},
			$this->days( '2026-09-07', 5 )
		);

		$impact = Impact::assess(
			Allocations::proposed( $this->item() ),
			array(),
			array(
				'usr_a' => $blank,
				'usr_b' => $blank,
			),
			'2026-09-07',
			'2026-09-11'
		);

		$this->assertTrue( Impact::clear( $impact ) );
	}

	public function test_the_item_is_not_counted_twice_when_it_is_already_committing(): void {
		/*
		 * CAP-E1. Entering In Development, the item is already at Up Next and
		 * therefore already in the live set. Counted twice, a job that exactly
		 * fills a week would refuse itself.
		 */
		$item = $this->item(
			array(
				'stage'         => 'up-next',
				'hours_primary' => 40.0,
				'hours_review'  => 0.0,
			)
		);

		$impact = Impact::assess(
			Allocations::proposed( $item ),
			Allocations::proposed( $item ),
			array( 'usr_a' => $this->days( '2026-09-07', 5 ) ),
			'2026-09-07',
			'2026-09-11'
		);

		$this->assertTrue( Impact::clear( $impact ) );
	}

	public function test_somebody_elses_work_still_counts_against_the_same_person(): void {
		$mine   = $this->item(
			array(
				'hours_primary' => 20.0,
				'hours_review'  => 0.0,
			)
		);
		$theirs = $this->item(
			array(
				'id'            => 'wrk_two',
				'stage'         => 'up-next',
				'client_id'     => 'cli_two',
				'hours_primary' => 30.0,
				'hours_review'  => 0.0,
			)
		);

		$impact = Impact::assess(
			Allocations::proposed( $mine ),
			Allocations::proposed( $theirs ),
			array( 'usr_a' => $this->days( '2026-09-07', 5 ) ),
			'2026-09-07',
			'2026-09-11'
		);

		$this->assertFalse( Impact::clear( $impact ) );
		$this->assertSame( 10.0, $impact['over'][0]['excess'] );
	}

	public function test_a_substitute_carries_the_commitment_rather_than_the_seat(): void {
		$impact = Impact::assess(
			Allocations::proposed(
				$this->item(
					array(
						'hours_primary'          => 0.0,
						'hours_review'           => 50.0,
						'reviewer_substitute_id' => 'usr_c',
					)
				)
			),
			array(),
			array(
				'usr_b' => $this->days( '2026-09-07', 5 ),
				'usr_c' => $this->days( '2026-09-07', 5 ),
			),
			'2026-09-07',
			'2026-09-11'
		);

		$this->assertSame( 'usr_c', $impact['over'][0]['user_id'] );
	}

	public function test_somebody_in_two_seats_is_reported_once_for_a_week(): void {
		// Two rows for one week reads as two problems. It is one.
		$impact = Impact::assess(
			Allocations::proposed(
				$this->item(
					array(
						'reviewer_id'   => 'usr_a',
						'hours_primary' => 30.0,
						'hours_review'  => 30.0,
					)
				)
			),
			array(),
			array( 'usr_a' => $this->days( '2026-09-07', 5 ) ),
			'2026-09-07',
			'2026-09-11'
		);

		$this->assertCount( 1, $impact['over'] );
		$this->assertSame( 20.0, $impact['over'][0]['excess'] );
	}

	public function test_an_item_with_no_dates_reaches_no_conclusion(): void {
		/*
		 * The missing dates are their own unmet requirement at Up Next. A
		 * capacity verdict guessed from nothing would refuse for the wrong
		 * reason and send somebody looking in the wrong place.
		 */
		$impact = Impact::assess(
			Allocations::proposed(
				$this->item(
					array(
						'planned_start' => '',
						'planned_due'   => '',
					)
				)
			),
			array(),
			array(),
			'',
			''
		);

		$this->assertTrue( Impact::clear( $impact ) );
	}
}
