<?php
/**
 * The webhook Forge keeps on each linked repo.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\GitHub\Hooks;
use Blueworx\Forge\GitHub\Settings;
use PHPUnit\Framework\TestCase;

/**
 * #680. The account is personal, so there is no organisation hook: Forge adds
 * its own webhook to each repo it links, and takes it off when it unlinks.
 * Answers are queued in the bootstrap's HTTP stub, in the order Forge asks.
 */
final class GitHubHooksTest extends TestCase {

	private const URL = 'https://forge.test/wp-json/blueworx-forge/v1/github/webhook';

	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_http']          = array();
		$GLOBALS['bwx_forge_test_http_requests'] = array();
		unset( $GLOBALS['bwx_forge_test_options'][ Settings::OPTION ], $GLOBALS['bwx_forge_test_options'][ Settings::STATUS ] );
		Settings::save( 'ghp_abcd1234', 'the-secret', 'blueworx-io', 1 );
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
			'body'     => null === $body ? '' : (string) wp_json_encode( $body ),
		);
	}

	/**
	 * The calls made, as method and URL.
	 *
	 * @return array<int, string>
	 */
	private function calls(): array {
		return array_map(
			static fn( array $call ): string => $call['args']['method'] . ' ' . $call['url'],
			$GLOBALS['bwx_forge_test_http_requests']
		);
	}

	public function test_the_hook_points_at_forges_webhook_route(): void {
		$this->assertSame( self::URL, Hooks::url() );
	}

	public function test_a_repo_without_forges_hook_gets_one(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 200, array( array( 'id' => 5, 'config' => array( 'url' => 'https://elsewhere.test/hook' ) ) ) );
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 201, array( 'id' => 77 ) );

		$this->assertSame( 77, Hooks::put( 'blueworx-io/alpha', 'the-secret' ) );
		$this->assertSame(
			array(
				'GET https://api.github.com/repos/blueworx-io/alpha/hooks?per_page=100',
				'POST https://api.github.com/repos/blueworx-io/alpha/hooks',
			),
			$this->calls()
		);

		$sent = json_decode( $GLOBALS['bwx_forge_test_http_requests'][1]['args']['body'], true );

		$this->assertSame(
			array(
				'active' => true,
				'events' => array( 'issues' ),
				'config' => array(
					'url'          => self::URL,
					'content_type' => 'json',
					'secret'       => 'the-secret',
					'insecure_ssl' => '0',
				),
				'name'   => 'web',
			),
			$sent
		);
	}

	public function test_a_repo_that_has_forges_hook_has_it_updated_not_doubled(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 200, array( array( 'id' => 42, 'config' => array( 'url' => self::URL ) ) ) );
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 200, array( 'id' => 42 ) );

		$this->assertSame( 42, Hooks::put( 'blueworx-io/alpha', 'new-secret' ) );
		$this->assertSame( 'PATCH https://api.github.com/repos/blueworx-io/alpha/hooks/42', $this->calls()[1] );
		$this->assertCount( 2, $this->calls() );

		$sent = json_decode( $GLOBALS['bwx_forge_test_http_requests'][1]['args']['body'], true );
		$this->assertSame( 'new-secret', $sent['config']['secret'] );
		$this->assertArrayNotHasKey( 'name', $sent );
	}

	public function test_a_refused_hook_is_an_error_and_nothing_is_added(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 403, array( 'message' => 'Resource not accessible by personal access token' ) );

		$answer = Hooks::put( 'blueworx-io/alpha', 'the-secret' );

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertCount( 1, $this->calls() );
	}

	public function test_a_hook_is_taken_off(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 204, null );

		$this->assertTrue( Hooks::remove( 'blueworx-io/alpha', 42 ) );
		$this->assertSame( array( 'DELETE https://api.github.com/repos/blueworx-io/alpha/hooks/42' ), $this->calls() );
	}

	public function test_a_hook_already_gone_counts_as_taken_off(): void {
		$GLOBALS['bwx_forge_test_http'][] = $this->answer( 404, array( 'message' => 'Not Found' ) );

		$this->assertTrue( Hooks::remove( 'blueworx-io/alpha', 42 ) );
		$this->assertTrue( Hooks::remove( 'blueworx-io/alpha', 0 ) );
		$this->assertCount( 1, $this->calls() );
	}

	public function test_a_hook_that_could_not_be_taken_off_is_an_error(): void {
		$GLOBALS['bwx_forge_test_http'][] = new WP_Error( 'http_request_failed', 'cURL error 28' );

		$this->assertInstanceOf( WP_Error::class, Hooks::remove( 'blueworx-io/alpha', 42 ) );
	}
}
