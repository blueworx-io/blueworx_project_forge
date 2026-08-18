<?php
/**
 * Class autoloading for the client plugin's own namespace.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

if ( ! function_exists( 'bwx_forge_client_register_autoloader' ) ) {
	/**
	 * Registers a PSR-4 style autoloader for Blueworx\Forge\Client.
	 *
	 * Deliberately its own function rather than a shared one: the two artifacts
	 * ship separately and may both be active on a studio machine during
	 * development, so neither may depend on the other having loaded first.
	 *
	 * The prefix is the whole Client namespace, not Blueworx\Forge — this
	 * autoloader must never resolve a studio class, because on a client site
	 * there is no file for it to resolve to and the failure would surface as a
	 * confusing "class not found" rather than as the boundary being crossed.
	 *
	 * @param string $base_dir Directory holding the client plugin's classes.
	 */
	function bwx_forge_client_register_autoloader( string $base_dir ): void {
		spl_autoload_register(
			static function ( string $class_name ) use ( $base_dir ): void {
				$prefix = 'Blueworx\\Forge\\Client\\';

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
