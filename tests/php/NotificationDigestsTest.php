<?php
/**
 * Tests for the client's daily and weekly emails.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Notifications\Digests;
use Blueworx\Forge\Notifications\Events;
use Blueworx\Forge\Notifications\Templates;
use PHPUnit\Framework\TestCase;

/**
 * #673. A daily email on days that involve the client, and a weekly round-up.
 * What goes in each, when each is due, and how each reads.
 */
final class NotificationDigestsTest extends TestCase {

	/**
	 * A task, as Items::for_site() returns it.
	 *
	 * @param array<string, mixed> $also Columns to add or override.
	 * @return array<string, mixed>
	 */
	private function item( array $also = array() ): array {
		return array_merge(
			array(
				'id'                => 'wrk_1',
				'title'             => 'Rebuild the booking form',
				'level'             => 'sub-feature',
				'client_id'         => 'cli_1',
				'client_site_id'    => 'cst_1',
				'stage'             => 'in-development',
				'prior_stage'       => '',
				'terminal_outcome'  => '',
				'archived'          => false,
				'recurring_id'      => '',
				'primary_user_id'   => 'usr_ana',
				'designer_id'       => '',
				'reviewer_id'       => 'usr_sam',
				'deliverer_id'      => 'usr_ana',
				'hours_primary'     => 2.0,
				'hours_design'      => 0.0,
				'hours_review'      => 1.0,
				'hours_delivery'    => 0.5,
				'planned_start'     => '2026-11-09',
				'planned_due'       => '2026-11-11',
				'design_target'     => '',
				'review_target'     => '2026-11-12',
				'release_target'    => '2026-11-13',
				'blocked_on_client' => false,
			),
			$also
		);
	}

	// -----------------------------------------------------------------------
	// When each is due.
	// -----------------------------------------------------------------------

	public function test_the_daily_is_due_from_eight_on_a_weekday(): void {
		$zone = new DateTimeZone( 'Europe/London' );

		// Wednesday 11 November 2026.
		$this->assertArrayNotHasKey( Events::DAILY, Digests::due( new DateTimeImmutable( '2026-11-11 07:59', $zone ) ) );
		$this->assertSame( 20261111, Digests::due( new DateTimeImmutable( '2026-11-11 08:00', $zone ) )[ Events::DAILY ] );
		$this->assertSame( 20261111, Digests::due( new DateTimeImmutable( '2026-11-11 17:30', $zone ) )[ Events::DAILY ] );
	}

	public function test_nothing_is_due_at_the_weekend(): void {
		$zone = new DateTimeZone( 'Europe/London' );

		$this->assertSame( array(), Digests::due( new DateTimeImmutable( '2026-11-14 10:00', $zone ) ) );
		$this->assertSame( array(), Digests::due( new DateTimeImmutable( '2026-11-15 10:00', $zone ) ) );
	}

	/**
	 * The weekly belongs to the week, not to Monday: a site that was asleep on
	 * Monday still gets it on Tuesday, and never twice, because every day of
	 * the week names the same Monday.
	 */
	public function test_the_weekly_is_named_after_its_monday(): void {
		$zone = new DateTimeZone( 'Europe/London' );

		$this->assertSame( 20261109, Digests::due( new DateTimeImmutable( '2026-11-09 08:00', $zone ) )[ Events::WEEKLY ] );
		$this->assertSame( 20261109, Digests::due( new DateTimeImmutable( '2026-11-11 09:00', $zone ) )[ Events::WEEKLY ] );
		$this->assertArrayNotHasKey( Events::WEEKLY, Digests::due( new DateTimeImmutable( '2026-11-09 07:00', $zone ) ) );
	}

	public function test_a_day_is_read_back_from_its_number(): void {
		$this->assertSame( '2026-11-09', Digests::day_of( 20261109 ) );
		$this->assertSame( '', Digests::day_of( 1 ) );
	}

	// -----------------------------------------------------------------------
	// The daily.
	// -----------------------------------------------------------------------

	public function test_work_booked_for_today_is_being_worked_on(): void {
		$daily = Digests::daily( array( $this->item() ), array(), array(), '2026-11-11' );

		$this->assertSame( array( 'wrk_1' ), array_column( $daily['working'], 'id' ) );
		$this->assertSame( array(), $daily['waiting'] );
	}

	public function test_work_booked_for_another_day_is_not(): void {
		$daily = Digests::daily( array( $this->item() ), array(), array(), '2026-11-10' );

		$this->assertTrue( Digests::is_empty( $daily ) );
	}

	public function test_a_part_already_done_is_not_being_worked_on(): void {
		// In review: the owner's part, booked for the 11th, is done.
		$daily = Digests::daily( array( $this->item( array( 'stage' => 'in-review' ) ) ), array(), array(), '2026-11-11' );

		$this->assertSame( array(), $daily['working'] );
	}

	public function test_finished_ended_and_archived_work_is_left_out(): void {
		$items = array(
			$this->item( array( 'stage' => 'released' ) ),
			$this->item( array( 'terminal_outcome' => 'cancelled' ) ),
			$this->item( array( 'archived' => true ) ),
		);

		$this->assertTrue( Digests::is_empty( Digests::daily( $items, array( 'wrk_1' => 2 ), array(), '2026-11-11' ) ) );
	}

	public function test_what_waits_on_the_client(): void {
		$items = array(
			$this->item(
				array(
					'id'    => 'wrk_q',
					'title' => 'Asked',
					'stage' => 'up-next',
				)
			),
			$this->item(
				array(
					'id'          => 'wrk_r',
					'title'       => 'To review',
					'stage'       => 'in-review',
					'reviewer_id' => 'client',
				)
			),
			$this->item(
				array(
					'id'                => 'wrk_b',
					'title'             => 'Stuck',
					'stage'             => 'blocked',
					'prior_stage'       => 'in-development',
					'blocked_on_client' => true,
				)
			),
			$this->item(
				array(
					'id'          => 'wrk_ours',
					'title'       => 'Stuck on us',
					'stage'       => 'blocked',
					'prior_stage' => 'in-development',
				)
			),
		);

		$daily = Digests::daily( $items, array( 'wrk_q' => 2 ), array(), '2026-11-20' );

		$this->assertSame(
			array(
				array(
					'id'    => 'wrk_q',
					'title' => 'Asked',
					'why'   => '2 questions to answer',
				),
				array(
					'id'    => 'wrk_r',
					'title' => 'To review',
					'why'   => 'ready for your review',
				),
				array(
					'id'    => 'wrk_b',
					'title' => 'Stuck',
					'why'   => 'on hold until we hear from you',
				),
			),
			$daily['waiting']
		);
	}

	public function test_onboarding_steps_due_today_or_overdue_wait_on_the_client(): void {
		$step = array(
			'id'         => 'stp_1',
			'title'      => 'Send us the domain login',
			'owner_side' => 'client',
			'status'     => 'not-started',
			'due_on'     => '2026-11-11',
		);

		$steps = array(
			$step,
			array_merge(
				$step,
				array(
					'id'     => 'stp_late',
					'title'  => 'Late one',
					'due_on' => '2026-11-01',
				)
			),
			array_merge(
				$step,
				array(
					'id'     => 'stp_later',
					'due_on' => '2026-11-30',
				)
			),
			array_merge(
				$step,
				array(
					'id'     => 'stp_sent',
					'status' => 'submitted',
				)
			),
			array_merge(
				$step,
				array(
					'id'         => 'stp_ours',
					'owner_side' => 'internal',
				)
			),
			array_merge(
				$step,
				array(
					'id'     => 'stp_undated',
					'due_on' => '',
				)
			),
		);

		$daily = Digests::daily( array(), array(), $steps, '2026-11-11' );

		$this->assertSame(
			array(
				array(
					'id'    => '',
					'title' => 'Send us the domain login',
					'why'   => 'onboarding step due today',
				),
				array(
					'id'    => '',
					'title' => 'Late one',
					'why'   => 'onboarding step overdue',
				),
			),
			$daily['waiting']
		);
	}

	// -----------------------------------------------------------------------
	// The weekly.
	// -----------------------------------------------------------------------

	public function test_the_weekly_lists_what_finished_and_what_is_coming(): void {
		$items = array(
			$this->item(
				array(
					'id'    => 'wrk_done',
					'title' => 'Shipped',
					'stage' => 'released',
				)
			),
			$this->item(
				array(
					'id'    => 'wrk_old',
					'title' => 'Shipped long ago',
					'stage' => 'released',
				)
			),
			$this->item(
				array(
					'id'    => 'wrk_now',
					'title' => 'This week',
				)
			),
			$this->item(
				array(
					'id'             => 'wrk_far',
					'title'          => 'Next month',
					'planned_due'    => '2026-12-11',
					'review_target'  => '2026-12-12',
					'release_target' => '2026-12-13',
				)
			),
		);

		$weekly = Digests::weekly( $items, array( 'wrk_done' => 'released' ), '2026-11-09' );

		$this->assertSame(
			array(
				array(
					'id'    => 'wrk_done',
					'title' => 'Shipped',
					'why'   => 'now live',
				),
			),
			$weekly['finished']
		);
		$this->assertSame( array( 'wrk_now' ), array_column( $weekly['coming'], 'id' ) );
		$this->assertSame( 'due 11 Nov', $weekly['coming'][0]['why'] );
	}

	public function test_a_weekly_with_nothing_and_no_hours_is_empty(): void {
		$weekly = Digests::weekly( array(), array(), '2026-11-09' );

		$this->assertTrue( Digests::is_empty( $weekly ) );
		$this->assertFalse( Digests::is_empty( array_merge( $weekly, array( 'hours' => 4.5 ) ) ) );
	}

	// -----------------------------------------------------------------------
	// How they read.
	// -----------------------------------------------------------------------

	public function test_the_two_kinds_are_events(): void {
		$this->assertTrue( Events::exists( Events::DAILY ) );
		$this->assertTrue( Events::exists( Events::WEEKLY ) );
		$this->assertSame( Events::CLIENT_SITE, Events::subject_type( Events::DAILY ) );
		$this->assertSame( Events::CLIENT_SITE, Events::subject_type( Events::WEEKLY ) );
		$this->assertNotSame( Events::id_for( Events::DAILY, 'cst_1', 20261111 ), Events::id_for( Events::DAILY, 'cst_1', 20261112 ) );
	}

	public function test_the_daily_email_links_each_item(): void {
		$email = Templates::render(
			Events::DAILY,
			array(
				'day'      => '2026-11-11',
				'site_url' => 'https://client.example/',
				'digest'   => array(
					'working' => array(
						array(
							'id'    => 'wrk_1',
							'title' => "Rebuild the\r\nbooking form",
						),
					),
					'waiting' => array(
						array(
							'id'    => 'wrk_q',
							'title' => 'Asked',
							'why'   => '1 question to answer',
						),
						array(
							'id'    => '',
							'title' => 'Send us the domain login',
							'why'   => 'onboarding step overdue',
						),
					),
				),
			)
		);

		$this->assertSame( 'Your work today, Wednesday 11 November', $email['subject'] );
		$this->assertStringContainsString( 'Being worked on today', $email['body'] );
		$this->assertStringContainsString( "- Rebuild the booking form\n  https://client.example/?forge_item=wrk_1", $email['body'] );
		$this->assertStringContainsString( 'Waiting on you', $email['body'] );
		$this->assertStringContainsString( "- Asked (1 question to answer)\n  https://client.example/?forge_item=wrk_q", $email['body'] );
		$this->assertStringContainsString( '- Send us the domain login (onboarding step overdue)', $email['body'] );
	}

	public function test_a_daily_with_one_half_leaves_the_other_heading_out(): void {
		$email = Templates::render(
			Events::DAILY,
			array(
				'day'      => '2026-11-11',
				'site_url' => 'https://client.example',
				'digest'   => array(
					'working' => array(),
					'waiting' => array(
						array(
							'id'    => 'wrk_q',
							'title' => 'Asked',
							'why'   => '1 question to answer',
						),
					),
				),
			)
		);

		$this->assertStringNotContainsString( 'Being worked on today', $email['body'] );
		$this->assertSame( 'Waiting on you today, Wednesday 11 November', $email['subject'] );
	}

	public function test_the_weekly_email_says_the_hours_left(): void {
		$email = Templates::render(
			Events::WEEKLY,
			array(
				'day'      => '2026-11-09',
				'site_url' => 'https://client.example',
				'digest'   => array(
					'finished' => array(
						array(
							'id'    => 'wrk_done',
							'title' => 'Shipped',
							'why'   => 'now live',
						),
					),
					'coming'   => array(),
					'hours'    => 4.5,
				),
			)
		);

		$this->assertSame( 'Your week, from Monday 9 November', $email['subject'] );
		$this->assertStringContainsString( 'Last week', $email['body'] );
		$this->assertStringContainsString( '- Shipped (now live)', $email['body'] );
		$this->assertStringContainsString( 'Nothing is booked for this week yet.', $email['body'] );
		$this->assertStringContainsString( 'Support hours left: 4.5', $email['body'] );
	}

	public function test_an_empty_digest_writes_nothing(): void {
		$email = Templates::render(
			Events::DAILY,
			array(
				'day'      => '2026-11-11',
				'site_url' => 'https://client.example',
				'digest'   => array(
					'working' => array(),
					'waiting' => array(),
				),
			)
		);

		$this->assertSame( '', $email['subject'] );
	}
}
