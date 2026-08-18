<?php
/**
 * Class autoloading for the plugin's own namespace.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

if ( ! function_exists( 'bwx_forge_register_autoloader' ) ) {
	/**
	 * Registers a PSR-4 style autoloader for Blueworx\Forge.
	 *
	 * Blueworx\Forge\Rest\Server resolves to <base>/Rest/Server.php. Names outside
	 * the plugin's own namespace are ignored rather than guessed at, so this can
	 * never fight another plugin's autoloader.
	 *
	 * @param string $base_dir Directory holding the plugin's classes.
	 */
	function bwx_forge_register_autoloader( string $base_dir ): void {
		spl_autoload_register(
			static function ( string $class_name ) use ( $base_dir ): void {
				$prefix = 'Blueworx\\Forge\\';

				if ( 0 !== strpos( $class_name, $prefix ) ) {
					return;
				}

				$relative = substr( $class_name, strlen( $prefix ) );
				$path     = rtrim( $base_dir, '/\\' ) . '/' . str_replace( '\\', '/', $relative ) . '.php';

				if ( is_readable( $path ) ) {
					require_once $path;
				}
			}
		);
	}
}
