<?php
/**
 * Routes a registered client site may call.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Sites\Registry;
use WP_REST_Request;
use WP_REST_Response;

/**
 * What a client site can ask the studio, once it has proved which client it is.
 *
 * For now that is one route: the handshake, which answers "yes, you are this
 * site, and here is what I know about you". It exists so authentication is
 * provable end to end before any data hangs off it — Milestone 1's read-through
 * layer (#84) is built on this permission callback, and a signed request that
 * nothing ever called would be authentication nobody had tested.
 *
 * Every route here uses Permissions::client_site, never a capability check: the
 * caller is a machine, not a logged-in user.
 */
final class ClientController {

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		Server::register_route(
			$route_namespace,
			'/client/handshake',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handshake' ),
				'permission_callback' => array( Permissions::class, 'client_site' ),
			)
		);
	}

	/**
	 * Confirms the calling site's identity.
	 *
	 * The site id is read from the verified header rather than from a parameter:
	 * the header is what the signature covers, so it is the only one that has
	 * been proven. A site_id in the body would be whatever the caller typed.
	 *
	 * `server_time` is here so a client whose clock has drifted can tell that is
	 * what is wrong, rather than seeing its requests refused for no visible
	 * reason once drift passes the signing window.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handshake( WP_REST_Request $request ): WP_REST_Response {
		$site_id = (string) $request->get_header( Signature::HEADER_SITE );
		$site    = Registry::get( $site_id );

		return rest_ensure_response(
			array(
				'ok'          => true,
				'site_id'     => $site_id,
				'name'        => $site['name'] ?? '',
				'status'      => $site['status'] ?? '',
				'server_time' => bwx_forge_now(),
				'version'     => BWX_FORGE_VERSION,
			)
		);
	}
}
