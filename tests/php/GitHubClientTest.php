<?php
/**
 * The calls Forge makes to GitHub.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\GitHub\Client;
use Blueworx\Forge\GitHub\Settings;
use PHPUnit\Framework\TestCase;

/**
 * #680. Nothing here reaches GitHub: the answers are queued in the bootstrap's
 * HTTP stub, in the order Forge makes the calls.
 */
final class GitHubClientTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_http']          = array();
		$GLOBALS['bwx_forge_test_http_requests'] = array();
		unset( $GLOBALS['bwx_forge_test_options'][ Settings::OPTION ], $GLOBALS['bwx_forge_test_options'][ Settings::STATUS ] );
		delete_transient( 'bwx_forge_github_repos' );
		Settings::save( 'ghp_abcd1234', 's3cret', 'blueworx-io', 1 );
	}

	/**
	 * A response in WordPress's shape.
	 *
	 * @param int   $code HTTP status.
	 * @param mixed $body JSON body.
	 * @return array<string, mixed>
	 */
	private function answer( int $code, $body ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => (string) wp_json_encode( $body ),
		);
	}

	public function test_a_refused_token_is_denied(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 401, array( 'message' => 'Bad credentials' ) );

		$answer = Client::whoami( 'ghp_nope' );

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( Client::DENIED, $answer->get_error_code() );
		$this->assertStringNotContainsString( 'ghp_nope', $answer->get_error_message() );
		// A token being tried out is not the saved one: nothing is recorded.
		$this->assertSame( '', Settings::problem()['what'] );
	}

	public function test_the_saved_token_refused_is_recorded(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 401, array( 'message' => 'Bad credentials' ) );

		$this->assertSame( Client::DENIED, Client::request( 'GET', '/user/repos' )->get_error_code() );
		$this->assertStringContainsString( 'no longer accepts the token', Settings::problem()['what'] );
	}

	public function test_a_forbidden_call_is_refused_not_denied(): void {
		// GitHub answers 403 when the token works but lacks a permission, such as webhooks.
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 403, array( 'message' => 'Resource not accessible by personal access token' ) );

		$answer = Client::request( 'POST', '/repos/blueworx-io/a/hooks', array( 'name' => 'web' ) );

		$this->assertSame( Client::REFUSED, $answer->get_error_code() );
		$this->assertSame( 403, $answer->get_error_data()['github_status'] );
		$this->assertSame( '', Settings::problem()['what'] );
	}

	public function test_a_denial_reads_as_reconnect(): void {
		$denied = Client::refusal( new WP_Error( Client::DENIED, 'x' ), 'Unreachable words.', 'Refused words.' );

		$this->assertSame( 'bwx_forge_github_denied', $denied->get_error_code() );
		$this->assertSame( 422, $denied->get_error_data()['status'] );
		$this->assertStringContainsString( 'Reconnect it on the Connections page', $denied->get_error_message() );
		$this->assertSame( 'Unreachable words.', Client::refusal( new WP_Error( Client::UNREACHABLE, 'x' ), 'Unreachable words.', 'Refused words.' )->get_error_message() );
		$this->assertSame( 'Refused words.', Client::refusal( new WP_Error( Client::REFUSED, 'x' ), 'Unreachable words.', 'Refused words.' )->get_error_message() );
	}

	public function test_other_errors_are_refused_with_githubs_words_cut_short(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 422, array( 'message' => str_repeat( 'x', 300 ) ) );

		$answer = Client::request( 'GET', '/user' );

		$this->assertSame( Client::REFUSED, $answer->get_error_code() );
		$this->assertStringContainsString( '422', $answer->get_error_message() );
		$this->assertLessThan( 200, strlen( $answer->get_error_message() ) );
	}

	public function test_github_out_of_reach_is_unreachable(): void {
		$GLOBALS['bwx_forge_test_http'][] = new WP_Error( 'http_request_failed', 'cURL error 28' );

		$this->assertSame( Client::UNREACHABLE, Client::request( 'GET', '/user' )->get_error_code() );
	}

	public function test_whoami_gives_the_login(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 200, array( 'login' => 'luke', 'id' => 5 ) );

		$this->assertSame( array( 'login' => 'luke' ), Client::whoami( 'ghp_new' ) );
		$this->assertSame( 'https://api.github.com/user', $GLOBALS['bwx_forge_test_http_requests'][0]['url'] );
		$this->assertSame( 'Bearer ghp_new', $GLOBALS['bwx_forge_test_http_requests'][0]['args']['headers']['Authorization'] );
	}

	public function test_repos_are_read_across_pages(): void {
		$first = array();

		for ( $i = 0; $i < 100; $i++ ) {
			$first[] = array( 'full_name' => 'Blueworx-IO/Repo-' . str_pad( (string) $i, 3, '0', STR_PAD_LEFT ) );
		}

		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 200, $first );
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 200, array( array( 'full_name' => 'Blueworx-IO/Aaa' ), array( 'full_name' => 'Blueworx-IO/Zzz' ) ) );

		$repos = Client::repos();

		$this->assertCount( 102, $repos );
		$this->assertSame( 'blueworx-io/aaa', $repos[0] );
		$this->assertSame( 'blueworx-io/zzz', $repos[101] );
		$this->assertSame( 'https://api.github.com/user/repos?affiliation=owner&per_page=100&page=1', $GLOBALS['bwx_forge_test_http_requests'][0]['url'] );
		$this->assertStringContainsString( 'page=2', $GLOBALS['bwx_forge_test_http_requests'][1]['url'] );

		// Cached: a second read asks nobody.
		Client::repos();
		$this->assertCount( 2, $GLOBALS['bwx_forge_test_http_requests'] );
	}

	public function test_requests_carry_the_api_headers(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 200, array() );

		Client::request( 'GET', '/user' );

		$request = $GLOBALS['bwx_forge_test_http_requests'][0];

		$this->assertSame( 'Bearer ghp_abcd1234', $request['args']['headers']['Authorization'] );
		$this->assertSame( 'application/vnd.github+json', $request['args']['headers']['Accept'] );
		$this->assertSame( '2022-11-28', $request['args']['headers']['X-GitHub-Api-Version'] );
		$this->assertSame( 'Blueworx-Forge', $request['args']['headers']['User-Agent'] );
		$this->assertSame( 10, $request['args']['timeout'] );
	}

	public function test_nothing_is_asked_when_there_is_no_token(): void {
		Settings::forget();

		$answer = Client::request( 'GET', '/user' );

		$this->assertSame( Client::DENIED, $answer->get_error_code() );
		$this->assertSame( array(), $GLOBALS['bwx_forge_test_http_requests'] );
	}
}
