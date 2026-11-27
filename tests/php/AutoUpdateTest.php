<?php
/**
 * Both plugins install their own releases, and nobody else's.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #713. The update checker only *offers* a release; without a decision to
 * install it, the offer waits on the Plugins screen for somebody to press
 * Update, and a release can sit there on a site nobody logs into.
 *
 * The decision is WordPress's `auto_update_plugin` filter, which every plugin
 * on the site passes through. So two things have to hold, and this holds both:
 * each plugin says yes to itself, and every other plugin comes back exactly as
 * it arrived — including a deliberate "no" somebody else set.
 */
final class AutoUpdateTest extends TestCase {

	/**
	 * The studio plugin's main file.
	 */
	private const STUDIO = __DIR__ . '/../../blueworx-forge.php';

	/**
	 * The client plugin's main file.
	 */
	private const CLIENT = __DIR__ . '/../../client/blueworx-forge-client.php';

	/**
	 * An update offer the way WordPress hands one to the filter.
	 *
	 * @param string $plugin The plugin's folder and main file.
	 * @return object
	 */
	private function offer( string $plugin ): object {
		return (object) array(
			'plugin'      => $plugin,
			'slug'        => dirname( $plugin ),
			'new_version' => '9.9.9',
		);
	}

	// ---- Each plugin installs its own release ---------------------------

	/**
	 * The studio plugin is updated without anybody pressing Update.
	 */
	public function test_the_studio_installs_its_own_release(): void {
		$item = $this->offer( 'blueworx-forge/blueworx-forge.php' );

		$this->assertTrue( Blueworx\Forge\Updates::force_own_auto_update( null, $item ) );
		$this->assertTrue( Blueworx\Forge\Updates::force_own_auto_update( false, $item ), 'even over a no that nobody chose' );
	}

	/**
	 * And so is the client plugin.
	 */
	public function test_the_client_installs_its_own_release(): void {
		$item = $this->offer( 'blueworx-forge-client/blueworx-forge-client.php' );

		$this->assertTrue( Blueworx\Forge\Client\Updates::force_own_auto_update( null, $item ) );
		$this->assertTrue( Blueworx\Forge\Client\Updates::force_own_auto_update( false, $item ) );
	}

	// ---- Nobody else's --------------------------------------------------

	/**
	 * Every other plugin keeps whatever decision it arrived with.
	 *
	 * The studio and client names are in the list on purpose: the studio's
	 * folder is the first half of the client's, so a prefix match would have
	 * each plugin installing the other's release decision.
	 *
	 * @see self::other_plugins()
	 *
	 * @param string $plugin Somebody else's plugin file.
	 */
	#[DataProvider( 'other_plugins' )]
	public function test_other_plugins_are_left_as_they_were( string $plugin ): void {
		foreach ( array( true, false, null ) as $decision ) {
			$item = $this->offer( $plugin );

			$this->assertSame( $decision, Blueworx\Forge\Updates::force_own_auto_update( $decision, $item ), $plugin );
			$this->assertSame( $decision, Blueworx\Forge\Client\Updates::force_own_auto_update( $decision, $item ), $plugin );
		}
	}

	/**
	 * The other plugins.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function other_plugins(): array {
		return array(
			'akismet'                         => array( 'akismet/akismet.php' ),
			'a single-file plugin'            => array( 'hello.php' ),
			'a lookalike of the studio'       => array( 'blueworx-forge-extra/blueworx-forge.php' ),
			'the studio file in another home' => array( 'somewhere/blueworx-forge.php' ),
		);
	}

	/**
	 * Each plugin leaves the other one alone.
	 */
	public function test_the_studio_and_the_client_do_not_decide_for_each_other(): void {
		$client = $this->offer( 'blueworx-forge-client/blueworx-forge-client.php' );
		$studio = $this->offer( 'blueworx-forge/blueworx-forge.php' );

		$this->assertFalse( Blueworx\Forge\Updates::force_own_auto_update( false, $client ) );
		$this->assertFalse( Blueworx\Forge\Client\Updates::force_own_auto_update( false, $studio ) );
	}

	/**
	 * An offer with no plugin on it, or not an offer at all, is not touched.
	 */
	public function test_an_offer_that_names_no_plugin_is_left_alone(): void {
		$this->assertFalse( Blueworx\Forge\Updates::force_own_auto_update( false, (object) array( 'theme' => 'blueworx' ) ) );
		$this->assertNull( Blueworx\Forge\Updates::force_own_auto_update( null, 'blueworx-forge/blueworx-forge.php' ) );
		$this->assertFalse( Blueworx\Forge\Client\Updates::force_own_auto_update( false, null ) );
	}

	// ---- It is actually switched on -------------------------------------

	/**
	 * Each main file hands the decision to WordPress, for plugins.
	 *
	 * Read out of the plugin files, because a method nothing hooks up decides
	 * nothing on a site — and the hook is the line that runs there.
	 */
	public function test_each_plugin_hooks_the_decision_up(): void {
		$this->assertMatchesRegularExpression(
			"/add_filter\(\s*'auto_update_plugin',\s*array\(\s*\\\\Blueworx\\\\Forge\\\\Updates::class,\s*'force_own_auto_update'\s*\)/",
			(string) file_get_contents( self::STUDIO )
		);

		$this->assertMatchesRegularExpression(
			"/add_filter\(\s*'auto_update_plugin',\s*array\(\s*\\\\Blueworx\\\\Forge\\\\Client\\\\Updates::class,\s*'force_own_auto_update'\s*\)/",
			(string) file_get_contents( self::CLIENT )
		);
	}

	/**
	 * Each plugin recognises itself by where WordPress lists it, not by a
	 * folder name written into the code.
	 *
	 * A copy installed in a renamed folder is offered its releases under that
	 * folder, so that is the name it has to say yes to.
	 */
	public function test_a_plugin_in_a_renamed_folder_still_installs_its_release(): void {
		$GLOBALS['bwx_forge_test_plugin_folders'] = array(
			BWX_FORGE_FILE        => 'forge-copy',
			BWX_FORGE_CLIENT_FILE => 'forge-client-copy',
		);

		try {
			$this->assertTrue( Blueworx\Forge\Updates::force_own_auto_update( null, $this->offer( 'forge-copy/blueworx-forge.php' ) ) );
			$this->assertTrue( Blueworx\Forge\Client\Updates::force_own_auto_update( null, $this->offer( 'forge-client-copy/blueworx-forge-client.php' ) ) );

			// The usual folder is somebody else's now.
			$this->assertNull( Blueworx\Forge\Updates::force_own_auto_update( null, $this->offer( 'blueworx-forge/blueworx-forge.php' ) ) );
			$this->assertNull( Blueworx\Forge\Client\Updates::force_own_auto_update( null, $this->offer( 'blueworx-forge-client/blueworx-forge-client.php' ) ) );
		} finally {
			unset( $GLOBALS['bwx_forge_test_plugin_folders'] );
		}
	}
}
