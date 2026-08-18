<?php
/**
 * Uninstall tests.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * Uninstall is the one code path nobody runs by accident and everybody relies on
 * being right. It is tested here, as a unit, rather than through the browser:
 * uninstalling through wp-admin deletes the plugin directory, which in the local
 * harness is a link to this repository.
 */
final class UninstallTest extends TestCase {

	/**
	 * Runs uninstall.php against the stubs and returns what it called.
	 *
	 * @return array<int, array{0: string, 1: mixed}>
	 */
	private function run_uninstall(): array {
		$GLOBALS['bwx_forge_test_calls'] = array();

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'blueworx-forge/blueworx-forge.php' );
		}

		require dirname( __DIR__, 2 ) . '/uninstall.php';

		return $GLOBALS['bwx_forge_test_calls'];
	}

	/**
	 * Every option the plugin owns is deleted.
	 */
	public function test_it_deletes_its_own_options(): void {
		$calls = $this->run_uninstall();

		$deleted = array_map(
			static fn( array $call ): string => (string) $call[1],
			array_filter( $calls, static fn( array $call ): bool => 'delete_option' === $call[0] )
		);

		$this->assertContains( 'bwx_forge_app_page_id', $deleted );
	}

	/**
	 * Nothing it touches belongs to anyone else: every deleted name carries the
	 * plugin's own prefix. This is what keeps an uninstall from taking the old
	 * Forge plugin's data with it while both are installed during migration.
	 */
	public function test_it_touches_nothing_it_does_not_own(): void {
		$calls = $this->run_uninstall();

		$this->assertNotEmpty( $calls );

		foreach ( $calls as $call ) {
			$this->assertStringStartsWith(
				'bwx_forge_',
				(string) $call[1],
				sprintf( '%s() was called with a name the plugin does not own', $call[0] )
			);
		}
	}
}
