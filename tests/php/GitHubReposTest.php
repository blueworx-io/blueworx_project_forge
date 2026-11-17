<?php
/**
 * Which GitHub repositories belong to which client site.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\GitHub\Repos;
use Blueworx\Forge\GitHub\Settings;
use PHPUnit\Framework\TestCase;

final class GitHubReposTest extends TestCase {

	public function test_repo_names_are_normalised(): void {
		self::assertSame( 'blueworx-io/forge', Repos::normalise( ' Blueworx-IO/Forge ' ) );
		self::assertSame( '', Repos::normalise( 'not a repo' ) );
		self::assertSame( '', Repos::normalise( 'a/b/c' ) );
	}

	public function test_a_repo_missing_from_the_kept_list_is_asked_for_once_more(): void {
		$GLOBALS['bwx_forge_test_http']          = array();
		$GLOBALS['bwx_forge_test_http_requests'] = array();
		Settings::save( 'ghp_abcd1234', 's3cret', 'blueworx-io', 1 );
		set_transient( Settings::REPOS, array( 'blueworx-io/old' ), 600 );

		$GLOBALS['bwx_forge_test_http'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( array( 'full_name' => 'blueworx-io/old' ), array( 'full_name' => 'blueworx-io/new' ) ) ),
		);

		self::assertSame( array( 'blueworx-io/new', 'blueworx-io/old' ), Repos::known( array( 'blueworx-io/new' ) ) );
		self::assertCount( 1, $GLOBALS['bwx_forge_test_http_requests'] );

		// Everything wanted is in the kept list: nobody is asked.
		self::assertSame( array( 'blueworx-io/new', 'blueworx-io/old' ), Repos::known( array( 'blueworx-io/old' ) ) );
		self::assertCount( 1, $GLOBALS['bwx_forge_test_http_requests'] );

		Settings::forget();
	}

	public function test_a_repo_held_by_another_site_conflicts(): void {
		self::assertSame(
			array( 'a/x' => 'cst_2' ),
			Repos::conflicts( array( 'a/x', 'a/y' ), 'cst_1', array( 'a/x' => 'cst_2', 'a/y' => 'cst_1' ) )
		);
	}
}
