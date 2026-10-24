<?php
/**
 * The routes the studio calls to make a client's site on this network.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Rest;

use Blueworx\Forge\Client\Network\Gate;
use Blueworx\Forge\Client\Network\Sites;
use WP_Error;
use WP_REST_Request;

/**
 * #621. Four routes, and nobody but the studio gets past any of them: each is
 * checked by Network\Gate — signed with the network's key, fresh, never seen
 * before — before anything else is asked. A refusal says only that.
 *
 * They answer only on the main site of a multisite network where this plugin
 * is network-activated. Anywhere else they exist (WordPress registers routes
 * per request, not per site) but refuse, after the signature check, so even
 * that is not said to a stranger.
 */
final class NetworkController {

	/**
	 * Registers the routes.
	 */
	public static function register_routes(): void {
		$routes = array(
			array( '/network/hello', 'GET', 'hello' ),
			array( '/network/sites', 'POST', 'create' ),
			array( '/network/sites/(?P<blog>\d+)/link', 'POST', 'link' ),
			array( '/network/sites/(?P<blog>\d+)/people', 'POST', 'people' ),
		);

		foreach ( $routes as list( $path, $method, $callback ) ) {
			register_rest_route(
				ConnectionController::NAMESPACE,
				$path,
				array(
					'methods'             => $method,
					'callback'            => array( self::class, $callback ),
					'permission_callback' => array( self::class, 'from_the_studio' ),
				)
			);
		}
	}

	/**
	 * Whether the call is the studio's, signed with the network's key.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function from_the_studio( WP_REST_Request $request ) {
		$genuine = Gate::verify(
			$request->get_method(),
			$request->get_route(),
			$request->get_body(),
			array(
				'site'      => (string) $request->get_header( 'X-BWX-Site' ),
				'timestamp' => (string) $request->get_header( 'X-BWX-Timestamp' ),
				'nonce'     => (string) $request->get_header( 'X-BWX-Nonce' ),
				'signature' => (string) $request->get_header( 'X-BWX-Signature' ),
			)
		);

		return $genuine
			? true
			: new WP_Error( 'bwx_forge_network_refused', __( 'This call was not accepted.', 'blueworx-forge' ), array( 'status' => 401 ) );
	}

	/**
	 * What the studio checks when it connects: that the key works, and what
	 * kind of network this is, so it can show where a new site's address goes.
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	public static function hello() {
		$refused = self::refuse_unless_network();

		if ( null !== $refused ) {
			return $refused;
		}

		$network = get_network();

		return rest_ensure_response(
			array(
				'ok'         => true,
				'name'       => (string) get_site_option( 'site_name', '' ),
				'domain'     => (string) $network->domain,
				'path'       => (string) $network->path,
				'subdomains' => is_subdomain_install(),
			)
		);
	}

	/**
	 * Step one: make the site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$refused = self::refuse_unless_network();

		if ( null !== $refused ) {
			return $refused;
		}

		$body = (array) $request->get_json_params();
		$made = Sites::create(
			(string) ( $body['slug'] ?? '' ),
			sanitize_text_field( (string) ( $body['title'] ?? '' ) ),
			(string) ( $body['site_id'] ?? '' )
		);

		return is_wp_error( $made ) ? $made : rest_ensure_response( array_merge( array( 'ok' => true ), $made ) );
	}

	/**
	 * Step two: link it to its Forge site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public static function link( WP_REST_Request $request ) {
		$refused = self::refuse_unless_network();

		if ( null !== $refused ) {
			return $refused;
		}

		$body   = (array) $request->get_json_params();
		$linked = Sites::link(
			(int) $request['blog'],
			(string) ( $body['site_id'] ?? '' ),
			(string) ( $body['studio_url'] ?? '' ),
			(string) ( $body['registry_site_id'] ?? '' ),
			(string) ( $body['key'] ?? '' )
		);

		return is_wp_error( $linked ) ? $linked : rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Step three: the client's logins.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public static function people( WP_REST_Request $request ) {
		$refused = self::refuse_unless_network();

		if ( null !== $refused ) {
			return $refused;
		}

		$body     = (array) $request->get_json_params();
		$outcomes = Sites::people(
			(int) $request['blog'],
			(string) ( $body['site_id'] ?? '' ),
			array_values( array_filter( (array) ( $body['people'] ?? array() ), 'is_array' ) )
		);

		return is_wp_error( $outcomes )
			? $outcomes
			: rest_ensure_response(
				array(
					'ok'     => true,
					'people' => $outcomes,
				)
			);
	}

	/**
	 * Refuses anywhere but the main site of a network this plugin is active
	 * across.
	 *
	 * @return WP_Error|null Null when this is such a place.
	 */
	private static function refuse_unless_network(): ?WP_Error {
		$active = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();

		if ( ! is_multisite() || ! is_main_site() || ! isset( $active[ plugin_basename( BWX_FORGE_CLIENT_FILE ) ] ) ) {
			return new WP_Error(
				'bwx_forge_network_not_a_network',
				__( 'This is not the main site of a multisite network with the Forge client plugin network-activated.', 'blueworx-forge' ),
				array( 'status' => 409 )
			);
		}

		return null;
	}
}
