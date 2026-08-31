<?php
/**
 * When a client site stops being quiet and starts being a problem.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\Health;
use Blueworx\Forge\Tenancy\Sync;
use PHPUnit\Framework\TestCase;

/**
 * #177. A broken client site is noticed by us, not by the client.
 *
 * The line this is really about is the one between *quiet* and *gone*. Almost
 * every client site is quiet almost all the time — that is what a small
 * business's website is — so a check that treats silence as a fault produces a
 * queue of forty sites every morning, and a queue of forty is a queue of none.
 * A check that never does produces nothing at all, and the client finds out
 * before we do. Most of what follows is that line, tested from both sides.
 */
final class SyncTest extends TestCase {

	private const NOW = 1788000000;

	protected function setUp(): void {
		parent::setUp();

		// Health decides idle from the clock rather than from an argument, so the
		// clock has to agree with the times these tests hand it — otherwise every
		// site reads as idle for reasons that have nothing to do with the test.
		$GLOBALS['bwx_forge_test_now'] = self::NOW;
	}

	/**
	 * A connected site's record.
	 *
	 * @param array<string, mixed> $overrides Anything else.
	 * @return array<string, mixed>
	 */
	private function site( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'              => 'int_one',
				'client_site_id'  => 'cst_one',
				'client_id'       => 'cli_one',
				'key_state'       => 'active',
				'last_seen_at'    => self::NOW - 60,
				'last_report_at'  => self::NOW - 60,
				'last_error_at'   => 0,
				'last_error_code' => '',
				'plugin_version'  => '2.47.0',
			),
			$overrides
		);
	}

	/* ------------------------------------------------------------ quiet is fine */

	public function test_a_site_that_called_a_minute_ago_is_nobodys_problem(): void {
		$row = Sync::row( $this->site(), array(), self::NOW );

		$this->assertFalse( $row['needs_attention'] );
		$this->assertSame( array(), $row['reasons'] );
	}

	public function test_a_site_quiet_for_a_day_is_still_nobodys_problem(): void {
		// Idle, which is a real state and not a fault. A site nobody visited
		// yesterday has nothing wrong with it.
		$row = Sync::row( $this->site( array( 'last_seen_at' => self::NOW - 90000 ) ), array(), self::NOW );

		$this->assertSame( Health::IDLE, $row['state'] );
		$this->assertFalse( $row['needs_attention'] );
	}

	public function test_a_site_nobody_has_connected_is_nobodys_problem(): void {
		$row = Sync::row( $this->site( array( 'key_state' => 'unissued' ) ), array(), self::NOW );

		$this->assertFalse( $row['needs_attention'] );
	}

	public function test_a_site_we_cut_off_is_nobodys_problem(): void {
		// It is doing exactly what was asked of it. A queue of faults containing
		// things that are fine is a queue people stop opening.
		$row = Sync::row( $this->site( array( 'key_state' => 'revoked' ) ), array(), self::NOW );

		$this->assertFalse( $row['needs_attention'] );
	}

	/* ------------------------------------------------------------- gone is not */

	public function test_a_site_silent_for_days_is_stalled(): void {
		$row = Sync::row(
			$this->site( array( 'last_seen_at' => self::NOW - ( Sync::STALLED_SECONDS + 1 ) ) ),
			array(),
			self::NOW
		);

		$this->assertTrue( $row['needs_attention'] );
		$this->assertContains( Sync::STALLED, $row['reasons'] );
	}

	public function test_the_stall_window_is_several_missed_reports_not_one(): void {
		/*
		 * Asserted as a relationship rather than as a number of seconds, because
		 * the relationship is the reason. The client plugin reports daily, so a
		 * window equal to the idle window would call every site stalled the
		 * first night nobody visited it.
		 */
		$this->assertGreaterThan( Health::WINDOW_SECONDS, Sync::STALLED_SECONDS );
	}

	public function test_a_site_that_never_called_is_not_reported_as_stalled(): void {
		// It is a setup that was never finished, which is a different job with a
		// different fix, and it is already named as one.
		$row = Sync::row( $this->site( array( 'last_seen_at' => 0 ) ), array(), self::NOW );

		$this->assertSame( array( Sync::NEVER_USED ), $row['reasons'] );
		$this->assertSame( 0, $row['silent_for'] );
	}

	public function test_a_failing_site_is_broken(): void {
		$row = Sync::row(
			$this->site(
				array(
					'last_error_at'   => self::NOW - 30,
					'last_error_code' => 'bad_signature',
				)
			),
			array(),
			self::NOW
		);

		$this->assertContains( Sync::BROKEN, $row['reasons'] );
		$this->assertSame( 'bad_signature', $row['last_error_code'] );
	}

	/* -------------------------------------------------------- and behind is not */

	public function test_a_site_with_an_email_it_collected_late_is_delayed(): void {
		$row = Sync::row(
			$this->site(),
			array(
				'count'  => 2,
				'oldest' => self::NOW - ( Sync::DELAYED_SECONDS + 1 ),
			),
			self::NOW
		);

		$this->assertContains( Sync::DELAYED, $row['reasons'] );
		$this->assertSame( 2, $row['waiting'] );
	}

	public function test_an_email_raised_a_moment_ago_is_not_late(): void {
		// Every email is uncollected for the first few minutes of its life. A
		// check that counted those would put every busy site in the queue.
		$row = Sync::row(
			$this->site(),
			array(
				'count'  => 5,
				'oldest' => self::NOW - 30,
			),
			self::NOW
		);

		$this->assertFalse( $row['needs_attention'] );
		$this->assertSame( 5, $row['waiting'] );
	}

	public function test_a_backlog_on_a_site_we_cut_off_is_still_nobodys_problem(): void {
		/*
		 * Deliberate. Cutting a site off is how somebody stops it being sent
		 * anything, and the emails behind it are the consequence they chose, not
		 * a fault to chase.
		 */
		$row = Sync::row(
			$this->site( array( 'key_state' => 'revoked' ) ),
			array(
				'count'  => 9,
				'oldest' => self::NOW - 900000,
			),
			self::NOW
		);

		$this->assertSame( array(), $row['reasons'] );
	}

	/* --------------------------------------------------------------- the queue */

	public function test_everything_wrong_with_a_site_is_named_at_once(): void {
		// One conversation, not three. Naming only the first thing found sends
		// somebody to fix the smaller half of the problem.
		$row = Sync::row(
			$this->site(
				array(
					'last_seen_at'  => self::NOW - ( Sync::STALLED_SECONDS + 1 ),
					'last_error_at' => self::NOW - 10,
				)
			),
			array(
				'count'  => 3,
				'oldest' => self::NOW - 100000,
			),
			self::NOW
		);

		$this->assertSame( array( Sync::BROKEN, Sync::STALLED, Sync::DELAYED ), $row['reasons'] );
	}

	public function test_the_queue_holds_only_the_sites_in_trouble(): void {
		$rows = array(
			Sync::row( $this->site( array( 'client_site_id' => 'cst_ok' ) ), array(), self::NOW ),
			Sync::row(
				$this->site(
					array(
						'client_site_id' => 'cst_bad',
						'last_error_at'  => self::NOW - 10,
					)
				),
				array(),
				self::NOW
			),
		);

		$queue = Sync::queue( $rows );

		$this->assertCount( 1, $queue );
		$this->assertSame( 'cst_bad', $queue[0]['client_site_id'] );
	}

	public function test_the_worst_thing_is_at_the_top(): void {
		$delayed = Sync::row(
			$this->site( array( 'client_site_id' => 'cst_late' ) ),
			array(
				'count'  => 1,
				'oldest' => self::NOW - 100000,
			),
			self::NOW
		);

		$broken = Sync::row(
			$this->site(
				array(
					'client_site_id' => 'cst_bad',
					'last_error_at'  => self::NOW - 10,
				)
			),
			array(),
			self::NOW
		);

		$queue = Sync::queue( array( $delayed, $broken ) );

		$this->assertSame( 'cst_bad', $queue[0]['client_site_id'] );
	}

	public function test_among_equal_problems_the_oldest_is_at_the_top(): void {
		// The one somebody has been walking past for a week, rather than the one
		// that broke this morning.
		$recent = Sync::row(
			$this->site(
				array(
					'client_site_id' => 'cst_recent',
					'last_seen_at'   => self::NOW - 60,
					'last_error_at'  => self::NOW - 10,
				)
			),
			array(),
			self::NOW
		);

		$ancient = Sync::row(
			$this->site(
				array(
					'client_site_id' => 'cst_ancient',
					'last_seen_at'   => self::NOW - 900000,
					'last_error_at'  => self::NOW - 10,
				)
			),
			array(),
			self::NOW
		);

		$queue = Sync::queue( array( $recent, $ancient ) );

		$this->assertSame( 'cst_ancient', $queue[0]['client_site_id'] );
	}

	/* ------------------------------------------------ enough detail to act on */

	public function test_every_reason_says_what_it_means_and_what_to_do(): void {
		/*
		 * The acceptance criterion is "with enough detail to act on", and a
		 * reason with no next step is a notification rather than a queue entry.
		 * Checked over the whole list so a reason added later cannot arrive
		 * without one.
		 */
		foreach ( Sync::REASONS as $reason ) {
			$this->assertNotSame( '', Sync::label( $reason ), $reason );
			$this->assertNotSame( '', Sync::what_to_do( $reason ), $reason );
			$this->assertNotSame( 'Unknown', Sync::label( $reason ), $reason );
		}
	}

	public function test_a_row_carries_how_long_it_has_been_true(): void {
		$row = Sync::row(
			$this->site( array( 'last_seen_at' => self::NOW - 7200 ) ),
			array(
				'count'  => 1,
				'oldest' => self::NOW - 3600,
			),
			self::NOW
		);

		$this->assertSame( 7200, $row['silent_for'] );
		$this->assertSame( 3600, $row['waiting_for'] );
	}
}
