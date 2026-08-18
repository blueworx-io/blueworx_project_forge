<?php
/**
 * The client site's own route for the workspace it reads from the studio.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Rest;

use Blueworx\Forge\Client\Workspace;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Reading the workspace, through this site rather than from it.
 *
 * The route is on the client artifact's own namespace and gated to this site's
 * administrator. It holds no data: it asks Workspace, which asks the studio and
 * falls back to what it last saw (ARCH-2, ARCH-4).
 *
 * `refresh` exists so a person who has just fixed something can ask again
 * without waiting out the staleness window. It is a parameter rather than a
 * separate route because it changes nothing — it is still a read.
 */
final class WorkspaceController {

	/**
	 * The client artifact's REST namespace.
	 */
	public const NAMESPACE = 'blueworx-forge-client/v1';

	/**
	 * Registers the route.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/workspace',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'read' ),
				// Never public. The record names the client and when their site
				// was connected, and the sync block would tell a stranger whether
				// this site is talking to anything.
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => array(
					'refresh' => array(
						'type'     => 'boolean',
						'required' => false,
						'default'  => false,
					),
				),
			)
		);
	}

	/**
	 * Whether the current user administers this site.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * The workspace as this site can currently see it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function read( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( Workspace::view( (bool) $request->get_param( 'refresh' ) ) );
	}
}
