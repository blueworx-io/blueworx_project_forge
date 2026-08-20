<?php
/**
 * Client site registration, from the studio.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Sites\Registry;
use Blueworx\Forge\Sites\SecurityLog;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Registering, rotating and revoking a client site's key.
 *
 * Every route here requires the studio administrator capability, matching
 * "register or revoke a client site key" in
 * docs/architecture/permission-matrix.md — which is `yes` for the primary admin
 * and `no` for everybody else, including every client role.
 *
 * There is no route by which a site enrols itself. ARCH-6 makes registration a
 * manual studio action because sites are installed and connected by us, and an
 * endpoint that let a site introduce itself would be the one hole in the whole
 * scheme.
 */
final class SitesController {

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		Server::register_route(
			$route_namespace,
			'/sites',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'index' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'The connection key registry: which endpoints the studio has issued keys to. Studio infrastructure, administrator-only, and no client record is in it.',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/sites',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'The connection key registry: which endpoints the studio has issued keys to. Studio infrastructure, administrator-only, and no client record is in it.',
				),
				'args'                => array(
					'name' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'url'  => array(
						'type'              => 'string',
						'required'          => true,
						'format'            => 'uri',
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/sites/(?P<site_id>[A-Za-z0-9_\-]+)/rotate',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rotate' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'The connection key registry: which endpoints the studio has issued keys to. Studio infrastructure, administrator-only, and no client record is in it.',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/sites/(?P<site_id>[A-Za-z0-9_\-]+)/revoke',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'revoke' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'The connection key registry: which endpoints the studio has issued keys to. Studio infrastructure, administrator-only, and no client record is in it.',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/security-log',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'security_log' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'The connection key registry: which endpoints the studio has issued keys to. Studio infrastructure, administrator-only, and no client record is in it.',
				),
			)
		);
	}

	/**
	 * Every registered site. Never includes a key.
	 *
	 * @return WP_REST_Response
	 */
	public static function index(): WP_REST_Response {
		return rest_ensure_response(
			array(
				'ok'    => true,
				'sites' => Registry::all(),
			)
		);
	}

	/**
	 * Registers a site and issues its key.
	 *
	 * The key is in this response and in no other, ever. If it is lost, the
	 * answer is to rotate rather than to look it up.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function create( WP_REST_Request $request ): WP_REST_Response {
		$site = Registry::register(
			(string) $request->get_param( 'name' ),
			(string) $request->get_param( 'url' )
		);

		return rest_ensure_response(
			array(
				'ok'      => true,
				'site_id' => $site['site_id'],
				'name'    => $site['name'],
				'url'     => $site['url'],
				'key'     => $site['key'],
				'notice'  => __( 'This key is shown once. Store it on the client site now; it cannot be retrieved later.', 'blueworx-forge' ),
			)
		);
	}

	/**
	 * Issues a new key, invalidating the old one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function rotate( WP_REST_Request $request ) {
		$site_id = (string) $request->get_param( 'site_id' );
		$key     = Registry::rotate( $site_id );

		if ( null === $key ) {
			return Errors::rest(
				'cannot_rotate',
				__( 'That site is not registered, or has been revoked. A revoked site is reinstated by registering it again, not by rotating its key.', 'blueworx-forge' ),
				404
			);
		}

		return rest_ensure_response(
			array(
				'ok'      => true,
				'site_id' => $site_id,
				'key'     => $key,
				'notice'  => __( 'The previous key stopped working immediately.', 'blueworx-forge' ),
			)
		);
	}

	/**
	 * Cuts a site off.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function revoke( WP_REST_Request $request ) {
		$site_id = (string) $request->get_param( 'site_id' );

		if ( ! Registry::revoke( $site_id ) ) {
			return Errors::rest(
				'unknown_site',
				__( 'That site is not registered.', 'blueworx-forge' ),
				404
			);
		}

		return rest_ensure_response(
			array(
				'ok'      => true,
				'site_id' => $site_id,
				'status'  => Registry::STATUS_REVOKED,
			)
		);
	}

	/**
	 * Recent refused requests, newest first.
	 *
	 * @return WP_REST_Response
	 */
	public static function security_log(): WP_REST_Response {
		return rest_ensure_response(
			array(
				'ok'      => true,
				'refused' => SecurityLog::recent(),
			)
		);
	}
}
