<?php
/**
 * Which zip each plugin updates itself from.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * #184. One repo publishes two plugins, and every Release carries both zips.
 *
 * The update checker takes the *first* release asset that matches its filter,
 * and with no filter it accepts them all — so an unfiltered site is offered
 * whichever zip GitHub happens to list first. On this repo that is the studio
 * plugin, which means an unfiltered client site would download the studio
 * plugin, install it over itself, and be deactivated by WordPress for having
 * the wrong main file. That would reach every client site at once, on the
 * first update after they were set up, and the only sign beforehand is the
 * absence of two arguments.
 *
 * So each plugin names its own artifact, and this holds them to it. Read out
 * of the plugin files rather than declared here, because the point is the
 * lines that actually run on a site.
 *
 * **It asserts the patterns tell the two zips apart**, which is the mistake
 * worth catching: `blueworx-forge-` is a prefix of `blueworx-forge-client-`,
 * so a filter written the obvious way matches both and the client site is back
 * where it started.
 */
final class UpdateAssetTest extends TestCase {

	/**
	 * The studio plugin's main file.
	 */
	private const STUDIO = __DIR__ . '/../../blueworx-forge.php';

	/**
	 * The client plugin's main file.
	 */
	private const CLIENT = __DIR__ . '/../../client/blueworx-forge-client.php';

	/**
	 * A release's two assets, named as the release workflow names them.
	 */
	private const STUDIO_ZIP = 'blueworx-forge-2.72.0.zip';

	/**
	 * The other one.
	 */
	private const CLIENT_ZIP = 'blueworx-forge-client-2.72.0.zip';

	// ---- Each plugin asks for an asset by name --------------------------

	/**
	 * The studio plugin picks the studio zip, and could not pick the other.
	 */
	public function test_the_studio_asks_for_the_studio_zip(): void {
		$pattern = $this->filter_in( self::STUDIO );

		$this->assertSame( 1, preg_match( $pattern, self::STUDIO_ZIP ), 'the studio zip is offered' );
		$this->assertSame( 0, preg_match( $pattern, self::CLIENT_ZIP ), 'the client zip is not' );
	}

	/**
	 * And the client plugin the client zip.
	 */
	public function test_the_client_asks_for_the_client_zip(): void {
		$pattern = $this->filter_in( self::CLIENT );

		$this->assertSame( 1, preg_match( $pattern, self::CLIENT_ZIP ), 'the client zip is offered' );
		$this->assertSame( 0, preg_match( $pattern, self::STUDIO_ZIP ), 'the studio zip is not' );
	}

	/**
	 * Neither falls back to the source tarball.
	 *
	 * A Release missing its zip should offer nothing. The alternative is
	 * WordPress installing GitHub's auto-generated source archive, whose folder
	 * is named after the repository and the tag — a second copy of the plugin,
	 * carrying every development file in it.
	 */
	public function test_neither_falls_back_to_the_source_archive(): void {
		foreach ( array( self::STUDIO, self::CLIENT ) as $file ) {
			$this->assertStringContainsString(
				'REQUIRE_RELEASE_ASSETS',
				(string) file_get_contents( $file ),
				basename( $file ) . ' would install the source archive if its zip were missing'
			);
		}
	}

	/**
	 * The pattern one plugin file filters release assets with.
	 *
	 * @param string $file Path to a plugin's main file.
	 * @return string A PCRE pattern.
	 */
	private function filter_in( string $file ): string {
		$source = (string) file_get_contents( $file );

		$found = preg_match( "/enableReleaseAssets\(\s*'([^']+)'/", $source, $matches );

		$this->assertSame(
			1,
			$found,
			basename( $file ) . ' does not name the release asset it updates from'
		);

		return $matches[1];
	}
}
