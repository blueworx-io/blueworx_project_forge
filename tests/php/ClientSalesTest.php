<?php
/**
 * What a client site shows about its own hours.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Client\Connection;
use Blueworx\Forge\Client\Sales;
use PHPUnit\Framework\TestCase;

/**
 * #156, COMM-2. The client's own view of what they have.
 *
 * **The number here is money**, which changes what "we do not know" has to look
 * like. Everywhere else on a client site, a missing figure can safely read as
 * nothing; here, a balance that has never been read showing as nought tells a
 * client with forty hours that they have none — and somebody decides not to ask
 * for work on the strength of it.
 *
 * So the one thing these tests are really about is the difference between
 * nought and unknown, and it is asserted in both directions.
 *
 * Nothing on this side is calculated. Every figure arrives as the studio worked
 * it out, because #158 asks that the two interfaces never disagree about money
 * and the only way to be sure of that is for there to be one calculation.
 */
final class ClientSalesTest extends TestCase {

	/**
	 * Clears every store and connects the site.
	 */
	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_options']       = array();
		$GLOBALS['bwx_forge_test_http']          = array();
		$GLOBALS['bwx_forge_test_http_requests'] = array();
		$GLOBALS['bwx_forge_client_test_now']    = 2000000;

		Connection::store( 'https://studio.example', 'site_abc', 'key-1' );
	}

	/**
	 * Queues an answer from the studio.
	 *
	 * @param array<string, mixed> $overrides Anything to say differently.
	 */
	private function studio_answers( array $overrides = array() ): void {
		$GLOBALS['bwx_forge_test_http'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode(
				array_merge(
					array(
						'ok'          => true,
						'generated'   => 5000,
						'entitlement' => array(
							'state'         => 'active',
							'label'         => 'On support',
							'may_use_hours' => true,
							'hours_granted' => 40.0,
							'term_ends_on'  => '2026-12-31',
						),
						'balance'     => 27.5,
						'purchases'   => array(
							array(
								'on'         => '2026-01-01',
								'kind'       => 'allocation',
								'hours'      => 40.0,
								'reason'     => '',
								'expires_at' => 0,
							),
						),
						'packages'    => array(
							array(
								'name'            => 'Standard',
								'hours'           => 40.0,
								'price'           => 120000,
								'currency'        => 'GBP',
								'validity_months' => 12,
							),
						),
					),
					$overrides
				)
			),
		);
	}

	/* ------------------------------------------------------ what comes back */

	public function test_the_studio_s_figures_arrive_as_the_studio_worked_them_out(): void {
		$this->studio_answers();

		$view = Sales::view();

		$this->assertTrue( $view['ok'] );
		$this->assertSame( 27.5, $view['balance'] );
		$this->assertSame( 'active', $view['entitlement']['state'] );
		$this->assertSame( 40.0, $view['entitlement']['hours_granted'] );
	}

	public function test_what_was_bought_comes_through_with_it(): void {
		$this->studio_answers();

		$view = Sales::view();

		$this->assertCount( 1, $view['purchases'] );
		$this->assertSame( 'allocation', $view['purchases'][0]['kind'] );
	}

	public function test_what_is_on_offer_comes_through_too(): void {
		// A client asking for more hours needs to see what "more" means. Prices
		// and hours only — COMM-2 keeps assignment manual, so there is nothing
		// here that could be mistaken for a checkout.
		$this->studio_answers();

		$this->assertSame( 'Standard', Sales::view()['packages'][0]['name'] );
	}

	/* ------------------------------------- nought and unknown are not the same */

	public function test_a_balance_never_read_is_unknown_rather_than_nought(): void {
		/*
		 * The test this class exists for. A client site that has never reached
		 * the studio knows nothing about the balance, and showing that as
		 * nought tells a client with forty hours that they have none.
		 */
		$GLOBALS['bwx_forge_test_http'][] = new WP_Error( 'http_request_failed', 'Connection refused.' );

		$view = Sales::view();

		$this->assertNull( $view['balance'] );
		$this->assertFalse( $view['ok'] );
	}

	public function test_a_balance_of_nought_is_nought_and_says_so(): void {
		// And the other direction: a client who really has run out is told, in
		// a figure, rather than being shown the same words as a site that
		// cannot reach the studio.
		$this->studio_answers( array( 'balance' => 0 ) );

		$view = Sales::view();

		$this->assertSame( 0.0, $view['balance'] );
		$this->assertStringContainsString( '0.00', Sales::balance_label( $view ) );
	}

	public function test_an_unknown_balance_reads_as_words_rather_than_a_figure(): void {
		$GLOBALS['bwx_forge_test_http'][] = new WP_Error( 'http_request_failed', 'Connection refused.' );

		$this->assertStringNotContainsString( '0', Sales::balance_label( Sales::view() ) );
	}

	/* ---------------------------------------------------- and the degradation */

	public function test_an_unreachable_studio_falls_back_to_what_was_last_seen(): void {
		// ARCH-4. The figures stay on screen and the sync notice says how old
		// they are, which is the only honest way to show money you cannot
		// currently confirm.
		$this->studio_answers();
		Sales::view();

		$GLOBALS['bwx_forge_client_test_now'] += 86400;
		$GLOBALS['bwx_forge_test_http'][]      = new WP_Error( 'http_request_failed', 'Connection refused.' );

		$view = Sales::view( true );

		$this->assertSame( 27.5, $view['balance'] );
		$this->assertNotSame( 'live', $view['sync']['state'] );
	}

	public function test_a_second_read_inside_the_window_does_not_call_the_studio(): void {
		$this->studio_answers();
		Sales::view();
		Sales::view();

		$this->assertCount( 1, $GLOBALS['bwx_forge_test_http_requests'] );
	}

	public function test_an_unconfigured_site_asks_nobody_anything(): void {
		$GLOBALS['bwx_forge_test_options'] = array();

		$view = Sales::view();

		$this->assertNull( $view['balance'] );
		$this->assertSame( array(), $GLOBALS['bwx_forge_test_http_requests'] );
	}
}
