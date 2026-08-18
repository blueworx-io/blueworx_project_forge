<?php
/**
 * Autoloader tests.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

/**
 * The autoloader is the one piece every other class depends on: if it resolves
 * a name wrongly, every later failure is a confusing "class not found".
 */
final class AutoloaderTest extends TestCase {

	/**
	 * A namespaced class resolves to its file under includes/.
	 */
	public function test_it_loads_a_plugin_class(): void {
		$this->assertTrue( class_exists( '\Blueworx\Forge\Plugin' ) );
	}

	/**
	 * A class in a sub-namespace resolves to the matching sub-directory.
	 */
	public function test_it_loads_a_namespaced_class(): void {
		$this->assertTrue( class_exists( '\Blueworx\Forge\Rest\Permissions' ) );
	}

	/**
	 * A name outside the plugin's namespace is left alone rather than guessed at,
	 * so the autoloader cannot fight another plugin's autoloader.
	 */
	public function test_it_ignores_foreign_namespaces(): void {
		$this->assertFalse( class_exists( '\SomeOther\Package\Thing' ) );
	}
}
