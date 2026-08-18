<?php
/**
 * The client site's own routes for its studio connection.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Rest;

use Blueworx\Forge\Client\Connection;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Connecting this site to the studio, and checking that it still is.
 *
 * These live in the client artifact's own namespace, `blueworx-forge-client/v1`,
 * which is distinct from the studio's so the two can never be confused for one
 * another — a client site answering on the studio's namespace is exactly the
 * failure ARCH-1 exists to prevent.
 *
 * Both routes require `manage_options` on *this* site: they are the client
 * administrator configuring their own WordPress. That is unrelated to the
 * per-site key, which authenticates this site to the studio.
 */
final class ConnectionController {

	/**
	 * The client artifact's REST namespace.
	 */
	public const NAMESPACE = 'blueworx-forge-client/v1';

	/**
	 * Registers the routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/connection',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'status' ),
				// The site administrator, on this site. Never public: the response
				// says whether a key is present and would confirm to a stranger
				// that this site is connected to something.
				'permission_callback' => array( self::class, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/connection',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'connect' ),
				'permission_callback' => array( self::class, 'can_manage' ),
				'args'                => array(
					'studio_url' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'esc_url_raw',
					),
					'site_id'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'key'        => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
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
	 * Whether this site is connected, proven by asking the studio.
	 *
	 * The answer never includes the key. It reports only whether one is present,
	 * which is what an administrator needs in order to tell "not set up yet" from
	 * "set up and being refused".
	 *
	 * @return WP_REST_Response
	 */
	public static function status(): WP_REST_Response {
		if ( ! Connection::is_configured() ) {
			return rest_ensure_response(
				array(
					'ok'         => false,
					'configured' => false,
					'reason'     => 'not_configured',
				)
			);
		}

		$handshake = Connection::get( '/client/handshake' );

		if ( is_wp_error( $handshake ) ) {
			$data = $handshake->get_error_data();

			return rest_ensure_response(
				array(
					'ok'         => false,
					'configured' => true,
					'reason'     => $handshake->get_error_code(),
					'status'     => $data['status'] ?? 0,
				)
			);
		}

		return rest_ensure_response(
			array(
				'ok'         => true,
				'configured' => true,
				'site_id'    => $handshake['site_id'] ?? '',
				'name'       => $handshake['name'] ?? '',
			)
		);
	}

	/**
	 * Stores the credentials the studio issued for this site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function connect( WP_REST_Request $request ): WP_REST_Response {
		Connection::store(
			(string) $request->get_param( 'studio_url' ),
			(string) $request->get_param( 'site_id' ),
			(string) $request->get_param( 'key' )
		);

		return rest_ensure_response(
			array(
				'ok'         => true,
				'configured' => Connection::is_configured(),
			)
		);
	}
}
