<?php
/**
 * The status routes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use WP_REST_Request;
use WP_REST_Response;

/**
 * The skeleton's only routes. They exist so the namespace is real, and so both
 * shapes — a public read and a capability-gated write — are proven end to end
 * before any feature relies on them.
 */
final class StatusController {

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		register_rest_route(
			$route_namespace,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'status' ),
				'permission_callback' => array( Permissions::class, 'read' ),
			)
		);

		register_rest_route(
			$route_namespace,
			'/status/echo',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'echo_message' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'args'                => array(
					'message' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Reports that the plugin is installed and answering.
	 *
	 * @return WP_REST_Response
	 */
	public static function status(): WP_REST_Response {
		return rest_ensure_response(
			array(
				'plugin'  => BWX_FORGE_SLUG,
				'version' => BWX_FORGE_VERSION,
				'ready'   => true,
			)
		);
	}

	/**
	 * Echoes a message back. The gated counterpart to status(), and the route the
	 * access-control spec proves is refused.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function echo_message( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response(
			array(
				'message' => (string) $request->get_param( 'message' ),
			)
		);
	}
}
