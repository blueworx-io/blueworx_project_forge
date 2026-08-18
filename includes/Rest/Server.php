<?php
/**
 * REST namespace registration.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

/**
 * One place that knows which controllers exist. A controller missing from this
 * list is a route that silently does not exist, so new controllers are added
 * here and nowhere else.
 */
final class Server {

	/**
	 * The plugin's REST namespace.
	 */
	public const NAMESPACE = 'blueworx-forge/v1';

	/**
	 * Registers every controller's routes. Hooked to rest_api_init.
	 */
	public static function register_routes(): void {
		StatusController::register_routes( self::NAMESPACE );
	}
}
