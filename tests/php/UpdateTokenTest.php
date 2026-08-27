<?php
/**
 * The token a site fetches its own updates with.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Updates;
use PHPUnit\Framework\TestCase;

/**
 * Setting the update token without editing a file on the server (#200).
 *
 * The behaviour worth pinning down is not the storing — it is what the site is
 * told. A site with no token is indistinguishable from an up-to-date one: the
 * repository is private, so GitHub reports nothing rather than refusing, and
 * WordPress offers no update and logs nothing. Every case below is a way that
 * silence can happen, and each has to come back as something a person can read
 * and act on.
 */
final class UpdateTokenTest extends TestCase {

	/**
	 * Clears the option store and the queued HTTP answers.
	 */
	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_options']       = array();
		$GLOBALS['bwx_forge_test_http']          = array();
		$GLOBALS['bwx_forge_test_http_requests'] = array();
		$GLOBALS['bwx_forge_test_calls']         = array();
		$GLOBALS['bwx_forge_test_transients']    = array();
	}

	/**
	 * Drawing the screen must not be a network round trip every time.
	 *
	 * The answer is only worth a few minutes, but those few minutes are the
	 * difference between opening the screen and waiting for a request that,
	 * on a site with no way out to the internet, pays the whole timeout.
	 */
	public function test_the_answer_is_remembered_briefly(): void {
		Updates::store( 'github_pat_good' );
		$this->queue( 200, array( 'tag_name' => 'v2.31.0' ) );

		$this->assertSame( 'ok', Updates::status()['state'] );

		// Nothing further is queued, so a second question to GitHub would come
		// back as an error rather than as the same answer.
		$this->assertSame( 'ok', Updates::status()['state'] );
		$this->assertCount( 1, $GLOBALS['bwx_forge_test_http_requests'] );
	}

	/**
	 * And a different token is a different question, answered fresh.
	 */
	public function test_a_new_token_is_not_answered_from_the_old_one(): void {
		Updates::store( 'github_pat_good' );
		$this->queue( 200, array( 'tag_name' => 'v2.31.0' ) );
		$this->assertSame( 'ok', Updates::status()['state'] );

		Updates::store( 'github_pat_expired' );
		$this->queue( 401, array() );

		$this->assertSame( 'refused', Updates::status()['state'] );
	}

	/**
	 * A token typed into the dashboard is the one the site uses.
	 */
	public function test_a_token_set_in_the_dashboard_is_used(): void {
		Updates::store( 'github_pat_dashboard' );

		$this->assertSame( 'github_pat_dashboard', Updates::token() );
		$this->assertSame( 'github_pat_dashboard', Updates::stored_token() );
		$this->assertFalse( Updates::is_fixed() );
	}

	/**
	 * Removing it leaves the site with none, rather than an empty one.
	 */
	public function test_a_stored_token_can_be_removed(): void {
		Updates::store( 'github_pat_dashboard' );
		Updates::forget();

		$this->assertSame( '', Updates::token() );
		$this->assertSame( '', Updates::stored_token() );
	}

	/**
	 * The case the issue exists for: no token at all, said out loud.
	 */
	public function test_a_site_with_no_token_is_told_so(): void {
		$status = Updates::status();

		$this->assertSame( 'none', $status['state'] );
		$this->assertStringContainsString( 'no token', $status['message'] );

		// And it did not ask GitHub, because there is nothing to ask with.
		$this->assertSame( array(), $GLOBALS['bwx_forge_test_http_requests'] );
	}

	/**
	 * A working token reports the release it can see, so the answer is provably
	 * about this repository rather than about GitHub being up.
	 */
	public function test_a_working_token_names_the_latest_release(): void {
		Updates::store( 'github_pat_good' );
		$this->queue( 200, array( 'tag_name' => 'v2.31.0' ) );

		$status = Updates::status();

		$this->assertSame( 'ok', $status['state'] );
		$this->assertSame( 'v2.31.0', $status['release'] );

		$request = $GLOBALS['bwx_forge_test_http_requests'][0];

		$this->assertStringContainsString( Updates::REPO . '/releases/latest', $request['url'] );
		$this->assertSame( 'Bearer github_pat_good', $request['args']['headers']['Authorization'] );
	}

	/**
	 * An expired or revoked token.
	 */
	public function test_a_token_github_rejects_is_reported_as_refused(): void {
		Updates::store( 'github_pat_expired' );
		$this->queue( 401, array() );

		$this->assertSame( 'refused', Updates::status()['state'] );
	}

	/**
	 * The quiet one. A token scoped to the wrong repository cannot see this one
	 * at all, so GitHub answers 404 rather than 403 — which reads as "no such
	 * release" unless it is deliberately treated as the refusal it is.
	 */
	public function test_a_token_that_cannot_see_the_repository_is_reported_as_refused(): void {
		Updates::store( 'github_pat_wrong_scope' );
		$this->queue( 404, array() );

		$this->assertSame( 'refused', Updates::status()['state'] );
	}

	/**
	 * A site that cannot reach GitHub is a different problem with a different
	 * fix, and must not be reported as a bad token.
	 */
	public function test_an_unreachable_github_is_not_reported_as_a_bad_token(): void {
		Updates::store( 'github_pat_good' );
		$GLOBALS['bwx_forge_test_http'] = array( new WP_Error( 'http_request_failed', 'Connection timed out.' ) );

		$this->assertSame( 'unreachable', Updates::status()['state'] );
	}

	/**
	 * wp-config.php wins.
	 *
	 * Must stay the last test in this file. A PHP constant cannot be undefined
	 * once it is set, so defining it here would change the answer every test
	 * above expects.
	 */
	public function test_wp_config_wins_over_the_dashboard(): void {
		Updates::store( 'github_pat_dashboard' );

		define( Updates::CONSTANT, 'github_pat_wp_config' );

		$this->assertTrue( Updates::is_fixed() );
		$this->assertSame( 'github_pat_wp_config', Updates::token() );

		// The stored one is still there, untouched rather than overwritten.
		$this->assertSame( 'github_pat_dashboard', Updates::stored_token() );
	}

	/**
	 * Queues one GitHub answer.
	 *
	 * @param int                  $code Status code.
	 * @param array<string, mixed> $body Response body.
	 */
	private function queue( int $code, array $body ): void {
		$GLOBALS['bwx_forge_test_http'] = array(
			array(
				'response' => array( 'code' => $code ),
				'body'     => wp_json_encode( $body ),
			),
		);
	}
}
