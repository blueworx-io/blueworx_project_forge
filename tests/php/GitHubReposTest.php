<?php
/**
 * Which GitHub repositories belong to which client site.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\GitHub\Repos;
use PHPUnit\Framework\TestCase;

final class GitHubReposTest extends TestCase {

	public function test_repo_names_are_normalised(): void {
		self::assertSame( 'blueworx-io/forge', Repos::normalise( ' Blueworx-IO/Forge ' ) );
		self::assertSame( '', Repos::normalise( 'not a repo' ) );
		self::assertSame( '', Repos::normalise( 'a/b/c' ) );
	}

	public function test_a_repo_held_by_another_site_conflicts(): void {
		self::assertSame(
			array( 'a/x' => 'cst_2' ),
			Repos::conflicts( array( 'a/x', 'a/y' ), 'cst_1', array( 'a/x' => 'cst_2', 'a/y' => 'cst_1' ) )
		);
	}
}
