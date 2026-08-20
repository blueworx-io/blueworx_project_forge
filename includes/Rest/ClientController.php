<?php
/**
 * Routes a registered client site may call.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Sites\Registry;
use Blueworx\Forge\Tenancy\Integrations;
use Blueworx\Forge\Tenancy\Validate;
use WP_REST_Request;
use WP_REST_Response;

/**
 * What a client site can ask the studio, once it has proved which client it is.
 *
 * Two routes: the handshake, which answers "yes, you are this site", and the
 * workspace, which is the first canonical record a client site renders without
 * holding a copy of it (ARCH-2).
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
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Authenticated by the client site\'s own key, not by a person: the signature names which site is calling, so the boundary is the signature (ARCH-6).',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/client/report',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'report' ),
				'permission_callback' => array( Permissions::class, 'client_site' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Authenticated by the client site\'s own key, not by a person: the signature names which site is calling, so the boundary is the signature (ARCH-6).',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/client/workspace',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'workspace' ),
				'permission_callback' => array( Permissions::class, 'client_site' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Authenticated by the client site\'s own key, not by a person: the signature names which site is calling, so the boundary is the signature (ARCH-6).',
				),
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

	/**
	 * Records what the calling site says about itself (#89).
	 *
	 * The site it describes is the one that signed the request, never one named
	 * in the body — the same rule the workspace read follows, and for the same
	 * reason: a signed request proves which site is calling and that is the only
	 * site it may touch.
	 *
	 * A site with no integration record is one whose key was issued through M1's
	 * routes rather than against a Client Site. It is turned away rather than
	 * given a record: an integration exists to say which client site a
	 * connection belongs to, and inventing one here would have to guess.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function report( WP_REST_Request $request ) {
		$site_id = (string) $request->get_header( Signature::HEADER_SITE );
		$checked = Validate::report( (array) $request->get_json_params() );

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_report',
				__( 'That report could not be recorded.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		$updated = Integrations::note_report( $site_id, $checked['values'] );

		if ( null === $updated ) {
			return Errors::rest(
				'no_integration',
				__( 'This site is not connected to a client site.', 'blueworx-forge' ),
				409
			);
		}

		return rest_ensure_response(
			array(
				'ok'       => true,
				'recorded' => $updated['last_report_at'],
			)
		);
	}

	/**
	 * The calling site's workspace record.
	 *
	 * This is the canonical record the client site renders and does not store
	 * (ARCH-2). The site it describes is always the one that signed the request,
	 * never one named in a parameter — a signed request proves which site is
	 * calling, and that is the only site it may read.
	 *
	 * `generated` is the studio's own clock at the moment the record was read.
	 * The client site stamps its cache with the time it received the answer
	 * rather than with this, because the age it shows a human has to be measured
	 * on the clock that human's browser is on. It is here for support: a record
	 * that looks stale on one side and fresh on the other is clock drift, and
	 * this is what shows that.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function workspace( WP_REST_Request $request ): WP_REST_Response {
		$site_id = (string) $request->get_header( Signature::HEADER_SITE );
		$site    = Registry::get( $site_id );

		return rest_ensure_response(
			array(
				'ok'        => true,
				'generated' => bwx_forge_now(),
				'record'    => array(
					'site_id'         => $site_id,
					'name'            => $site['name'] ?? '',
					'url'             => $site['url'] ?? '',
					'status'          => $site['status'] ?? '',
					'connected_since' => (int) ( $site['created_at'] ?? 0 ),
				),
			)
		);
	}
}
