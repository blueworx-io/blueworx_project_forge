<?php
/**
 * Whether a site can see its own updates.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Updates;
use PHPUnit\Framework\TestCase;

/**
 * What a site is told about its updates (#200, #340).
 *
 * Releases live in a public repository now, so nothing is set on a site and
 * nothing is authenticated. The behaviour worth pinning down is still what the
 * site says: a site that cannot see a release must say so, in words that name
 * the cause, rather than look exactly like a site that is up to date.
 */
final class UpdatesStatusTest extends TestCase {

	/**
	 * Clears the queued HTTP answers and the remembered ones.
	 */
	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_http']          = array();
		$GLOBALS['bwx_forge_test_http_requests'] = array();
		$GLOBALS['bwx_forge_test_transients']    = array();
	}

	/**
	 * The question goes to the public releases repository, with no credential
	 * on it — exactly as the update checker asks, so this answer is that one.
	 */
	public function test_the_release_repository_is_asked_without_a_token(): void {
		$this->queue( 200, array( 'tag_name' => 'v2.126.0' ) );

		$status = Updates::status();

		$this->assertSame( 'ok', $status['state'] );
		$this->assertSame( 'v2.126.0', $status['release'] );

		$request = $GLOBALS['bwx_forge_test_http_requests'][0];

		$this->assertStringContainsString( Updates::REPO . '/releases/latest', $request['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $request['args']['headers'] );
	}

	/**
	 * Drawing the screen must not be a network round trip every time.
	 */
	public function test_the_answer_is_remembered_briefly(): void {
		$this->queue( 200, array( 'tag_name' => 'v2.126.0' ) );

		$this->assertSame( 'ok', Updates::status()['state'] );

		// Nothing further is queued, so a second question to GitHub would come
		// back as an error rather than as the same answer.
		$this->assertSame( 'ok', Updates::status()['state'] );
		$this->assertCount( 1, $GLOBALS['bwx_forge_test_http_requests'] );
	}

	/**
	 * Nothing published yet, or a build pointed at the wrong place: there is no
	 * release to take, and the site says so.
	 */
	public function test_no_release_where_the_site_looks_is_said_plainly(): void {
		$this->queue( 404, array() );

		$status = Updates::status();

		$this->assertSame( 'missing', $status['state'] );
		$this->assertStringContainsString( 'no release was found', $status['message'] );
	}

	/**
	 * GitHub allows a fixed number of anonymous requests an hour from one
	 * address, and a busy host can use them up. That passes, and is not an
	 * error with this site.
	 */
	public function test_a_rate_limited_answer_is_reported_as_temporary(): void {
		$this->queue( 403, array() );

		$this->assertSame( 'limited', Updates::status()['state'] );

		$GLOBALS['bwx_forge_test_transients'] = array();
		$this->queue( 429, array() );

		$this->assertSame( 'limited', Updates::status()['state'] );
	}

	/**
	 * A site that cannot reach GitHub is a different problem with a different
	 * fix.
	 */
	public function test_an_unreachable_github_is_said_to_be_unreachable(): void {
		$GLOBALS['bwx_forge_test_http'] = array( new WP_Error( 'http_request_failed', 'Connection timed out.' ) );

		$this->assertSame( 'unreachable', Updates::status()['state'] );
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
