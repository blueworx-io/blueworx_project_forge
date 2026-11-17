<?php
/**
 * The GitHub outbox's rules.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\GitHub\Client;
use Blueworx\Forge\GitHub\Jobs;
use Blueworx\Forge\GitHub\Outbound;
use PHPUnit\Framework\TestCase;

/**
 * #681. One job per kind per item, and a job that keeps failing waits longer
 * each time, up to an hour.
 */
final class GitHubJobsTest extends TestCase {

	public function test_one_job_per_kind_per_item(): void {
		self::assertSame( Jobs::id_for( 'create_issue', 'wrk_1' ), Jobs::id_for( 'create_issue', 'wrk_1' ) );
		self::assertNotSame( Jobs::id_for( 'create_issue', 'wrk_1' ), Jobs::id_for( 'set_label', 'wrk_1' ) );
		self::assertNotSame( Jobs::id_for( 'create_issue', 'wrk_1' ), Jobs::id_for( 'create_issue', 'wrk_2' ) );
		// It is the table's key, which holds 32 characters.
		self::assertSame( 32, strlen( Jobs::id_for( 'create_issue', 'wrk_1' ) ) );
		self::assertStringStartsWith( 'ghj_', Jobs::id_for( 'create_issue', 'wrk_1' ) );
	}

	public function test_tries_back_off_to_an_hour(): void {
		self::assertSame( 120, Jobs::backoff( 1 ) );
		self::assertSame( 1920, Jobs::backoff( 5 ) );
		self::assertSame( 3600, Jobs::backoff( 6 ) );
		self::assertSame( 3600, Jobs::backoff( 10 ) );
	}

	/**
	 * A refused token is the connection's fault, not the job's: it uses up
	 * no try, so a token that runs out never makes a change give up.
	 */
	public function test_a_refused_token_uses_up_no_try(): void {
		self::assertFalse( Jobs::counts_as_try( new WP_Error( Client::DENIED, 'No.' ) ) );
		self::assertTrue( Jobs::counts_as_try( new WP_Error( Client::UNREACHABLE, 'Down.' ) ) );
		self::assertTrue( Jobs::counts_as_try( new WP_Error( Client::REFUSED, 'Gone.' ) ) );
	}

	/**
	 * A try that lost GitHub's answer may still have made the issue: the next
	 * one finds it by the item's marker instead of making another.
	 */
	public function test_an_issue_already_made_is_found_by_its_marker(): void {
		$issues = array(
			array(
				'number' => 3,
				'id'     => 30,
				'body'   => "Someone else's\n<!-- forge:wrk_other -->",
			),
			array(
				'number'       => 4,
				'id'           => 40,
				'body'         => "A pull request\n<!-- forge:wrk_mine -->",
				'pull_request' => array(),
			),
			array(
				'number' => 5,
				'id'     => 50,
				'body'   => "Ours\n<!-- forge:wrk_mine -->",
			),
		);

		self::assertSame( 5, Outbound::marked( $issues, 'wrk_mine' )['number'] );
		self::assertNull( Outbound::marked( $issues, 'wrk_none' ) );
		self::assertNull( Outbound::marked( array( array( 'body' => null ) ), 'wrk_mine' ) );
	}

	/**
	 * What the GitHub row on Connections says about changes not yet sent:
	 * those that gave up, and those still being tried.
	 */
	public function test_the_connections_row_says_what_has_not_gone(): void {
		$none = array(
			'count'      => 0,
			'last_error' => '',
		);

		self::assertSame( '', Jobs::describe( $none, $none ) );
		self::assertSame(
			"2 changes couldn't be sent to GitHub: GitHub could not be reached.",
			Jobs::describe(
				array(
					'count'      => 2,
					'last_error' => 'GitHub could not be reached.',
				),
				$none
			)
		);
		self::assertSame(
			'1 change is waiting to be sent to GitHub. Last try: GitHub could not be reached.',
			Jobs::describe(
				$none,
				array(
					'count'      => 1,
					'last_error' => 'GitHub could not be reached.',
				)
			)
		);
	}
}
