<?php
/**
 * The repo a piece of work is filed under.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\GitHub\RepoChoice;
use Blueworx\Forge\GitHub\Settings;
use Blueworx\Forge\Work\Validate;
use PHPUnit\Framework\TestCase;

final class GitHubRepoChoiceTest extends TestCase {

	public function test_no_repo_needed_always_passes(): void {
		self::assertNull( RepoChoice::check( 'none', false, array() ) );
		self::assertNull( RepoChoice::check( '', false, array() ) );
	}

	public function test_a_repo_needs_github_connected(): void {
		self::assertSame( 'Connect GitHub before choosing a repo, or choose No repo needed.', RepoChoice::check( 'blueworx-io/alpha', false, array() ) );
	}

	public function test_a_repo_outside_the_account_is_refused(): void {
		self::assertSame( 'That repo isn\'t one of the connected GitHub account\'s repos.', RepoChoice::check( 'someone/else', true, array( 'blueworx-io/alpha' ) ) );
		self::assertNull( RepoChoice::check( 'blueworx-io/alpha', true, array( 'blueworx-io/alpha' ) ) );
	}

	public function test_an_unreadable_list_lets_a_well_formed_repo_through(): void {
		self::assertNull( RepoChoice::check( 'someone/else', true, null ) );
	}

	protected function tearDown(): void {
		Settings::forget();
		delete_transient( Settings::REPOS );
		delete_transient( Settings::REPOS_DOWN );
		$GLOBALS['bwx_forge_test_http'] = array();
	}

	/**
	 * @param string $repo What was sent.
	 * @return array<string, string>
	 */
	private function errors_for( string $repo ): array {
		$base = array(
			'title'     => 'T',
			'level'     => 'feature',
			'work_type' => 'feature',
		);

		return Validate::item( array_merge( $base, array( 'github_repo' => $repo ) ), false )['errors'];
	}

	public function test_validate_checks_the_shape_and_tidies_the_name(): void {
		self::assertSame( array(), $this->errors_for( 'Blueworx-IO/Alpha' ) );
		self::assertSame( array(), $this->errors_for( 'none' ) );
		self::assertArrayHasKey( 'github_repo', $this->errors_for( 'not a repo' ) );

		$checked = Validate::item( array( 'github_repo' => ' Blueworx-IO/Alpha ' ), true );
		self::assertSame( 'blueworx-io/alpha', $checked['values']['github_repo'] );
	}

	public function test_validate_never_asks_github(): void {
		$GLOBALS['bwx_forge_test_http_requests'] = array();

		self::assertSame( array(), $this->errors_for( 'someone/else' ) );
		self::assertSame( array(), $GLOBALS['bwx_forge_test_http_requests'] );
	}

	public function test_a_repo_is_checked_against_the_account_when_it_changes(): void {
		Settings::save( 'ghp_abcd1234', 's3cret', 'blueworx-io', 1 );
		set_transient( Settings::REPOS, array( 'blueworx-io/alpha' ), 600 );

		self::assertNull( RepoChoice::refusal( 'blueworx-io/alpha', '' ) );
		self::assertNotNull( RepoChoice::refusal( 'someone/else', '' ) );
		self::assertNull( RepoChoice::refusal( 'none', '' ) );
		self::assertNull( RepoChoice::refusal( '', 'blueworx-io/alpha' ) );
	}

	public function test_a_repo_left_as_it_was_is_not_checked_again(): void {
		Settings::save( 'ghp_abcd1234', 's3cret', 'blueworx-io', 1 );
		set_transient( Settings::REPOS, array(), 600 );

		self::assertNull( RepoChoice::refusal( 'someone/else', 'someone/else' ) );
	}

	public function test_github_being_down_does_not_stop_a_save(): void {
		Settings::save( 'ghp_abcd1234', 's3cret', 'blueworx-io', 1 );
		$GLOBALS['bwx_forge_test_http'] = array();

		self::assertNull( RepoChoice::refusal( 'blueworx-io/alpha', '' ) );
	}

	public function test_a_repo_with_github_not_connected_is_refused(): void {
		self::assertNotNull( RepoChoice::refusal( 'blueworx-io/alpha', '' ) );
	}
}
