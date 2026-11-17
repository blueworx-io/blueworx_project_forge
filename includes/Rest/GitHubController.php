<?php
/**
 * Connecting GitHub, over REST.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\GitHub\Client;
use Blueworx\Forge\GitHub\Deliveries;
use Blueworx\Forge\GitHub\Inbound;
use Blueworx\Forge\GitHub\Repos;
use Blueworx\Forge\GitHub\Settings;
use WP_REST_Request;

/**
 * #680. An administrator gives Forge a GitHub token, the organisation to
 * watch and the webhook secret. The token is checked with GitHub before
 * anything is kept, and neither it nor the secret ever comes back out.
 */
final class GitHubController {

	/**
	 * Registers the routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		$scope = array(
			'kind'   => Boundary::SCOPE_OPEN,
			'reason' => 'The studio\'s own GitHub connection: administrators only, and neither the token nor the secret is ever in an answer.',
		);

		$routes = array(
			array( '/connections/github', 'POST', 'connect' ),
			array( '/connections/github', 'DELETE', 'disconnect' ),
			array( '/github/repos', 'GET', 'repos' ),
		);

		foreach ( $routes as list( $path, $method, $callback ) ) {
			Server::register_route(
				$route_namespace,
				$path,
				array(
					'methods'             => $method,
					'callback'            => array( self::class, $callback ),
					'permission_callback' => array( Permissions::class, 'manage' ),
					'scope'               => $scope,
				)
			);
		}

		Server::register_route(
			$route_namespace,
			'/github/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'webhook' ),
				'permission_callback' => array( Permissions::class, 'github' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'GitHub\'s organisation hook. Proved by its signature with the shared secret, not by a login.',
				),
			)
		);

		$site_scope = array(
			'kind'   => Boundary::SCOPE_SITE,
			'param'  => 'site_id',
			'record' => 'client_site',
		);

		foreach ( array(
			'GET' => 'site_repos',
			'PUT' => 'set_site_repos',
		) as $method => $callback ) {
			Server::register_route(
				$route_namespace,
				'/client-sites/(?P<site_id>[A-Za-z0-9_-]+)/github-repos',
				array(
					'methods'             => $method,
					'callback'            => array( self::class, $callback ),
					'permission_callback' => array( Permissions::class, 'manage' ),
					'scope'               => $site_scope,
				)
			);
		}
	}

	/**
	 * A signed message from GitHub. By the time this runs the signature has
	 * been checked; a message that failed it never gets here.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function webhook( WP_REST_Request $request ) {
		$event    = sanitize_key( (string) $request->get_header( 'x_github_event' ) );
		$delivery = trim( (string) $request->get_header( 'x_github_delivery' ) );
		$payload  = (array) $request->get_json_params();

		if ( '' === $event || '' === $delivery ) {
			return Errors::rest( 'invalid_github_message', __( 'That message did not say what it was or which one it was.', 'blueworx-forge' ), 400 );
		}

		if ( ! Deliveries::claim( $delivery, $event, sanitize_key( (string) ( $payload['action'] ?? '' ) ) ) ) {
			return rest_ensure_response(
				array(
					'ok'      => true,
					'outcome' => 'duplicate',
				)
			);
		}

		Settings::heard( bwx_forge_now() );

		$outcome = Inbound::handle( $event, $payload );

		Deliveries::settle( $delivery, $outcome );

		return rest_ensure_response(
			array(
				'ok'      => true,
				'outcome' => $outcome,
			)
		);
	}

	/**
	 * Connects GitHub, once GitHub has accepted the token and can show the
	 * organisation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function connect( WP_REST_Request $request ) {
		$body   = (array) $request->get_json_params();
		$token  = trim( (string) ( $body['token'] ?? '' ) );
		$org    = strtolower( trim( sanitize_text_field( (string) ( $body['org'] ?? '' ) ) ) );
		$secret = trim( (string) ( $body['secret'] ?? '' ) );

		$fields = array();

		if ( '' === $token ) {
			$fields['token'] = __( 'Enter the GitHub token.', 'blueworx-forge' );
		}

		if ( '' === $org ) {
			$fields['org'] = __( 'Enter the GitHub organisation, such as blueworx-io.', 'blueworx-forge' );
		}

		if ( '' === $secret ) {
			$fields['secret'] = __( 'Enter the secret you will paste into the organisation\'s webhook.', 'blueworx-forge' );
		}

		if ( array() !== $fields ) {
			return Errors::rest( 'invalid_github', (string) reset( $fields ), 400, array( 'fields' => $fields ) );
		}

		// Two reads, so nothing is kept that GitHub would refuse.
		$who   = Client::whoami( $token );
		$repos = is_wp_error( $who ) ? $who : Client::org_repos( $org, $token );

		if ( is_wp_error( $repos ) ) {
			return Client::UNREACHABLE === $repos->get_error_code()
				? Errors::rest( 'github_unreachable', __( 'GitHub could not be reached, so the token could not be checked. Nothing was saved; try again.', 'blueworx-forge' ), 502 )
				: Errors::rest( 'github_refused', __( 'GitHub did not accept that token, or it can\'t see that organisation. Nothing was saved.', 'blueworx-forge' ), 422 );
		}

		Settings::save( $token, $org, $secret, (string) ( $who['login'] ?? '' ), get_current_user_id() );

		// A list kept from an earlier connection may be another organisation's.
		delete_transient( Settings::REPOS );

		return rest_ensure_response( ConnectionsController::answer() );
	}

	/**
	 * Disconnects GitHub. Repo links and item links stay.
	 *
	 * @return \WP_REST_Response
	 */
	public static function disconnect() {
		Settings::forget();

		return rest_ensure_response( ConnectionsController::answer() );
	}

	/**
	 * Every repository in the organisation, and the site each is linked to.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function repos() {
		if ( ! Settings::connected() ) {
			return rest_ensure_response(
				array(
					'ok'        => true,
					'connected' => false,
					'repos'     => array(),
				)
			);
		}

		$names = Client::org_repos( Settings::org() );

		if ( is_wp_error( $names ) ) {
			return Errors::rest( 'github_unreachable', __( 'GitHub could not be reached, so the repositories could not be listed. Try again.', 'blueworx-forge' ), 502 );
		}

		$sites = Repos::all();

		return rest_ensure_response(
			array(
				'ok'        => true,
				'connected' => true,
				'repos'     => array_map(
					static fn( string $name ): array => array(
						'repo'      => $name,
						'site_id'   => $sites[ $name ] ?? '',
						'site_name' => Repos::site_name( $sites[ $name ] ?? '' ),
					),
					$names
				),
			)
		);
	}

	/**
	 * The repositories linked to a client site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function site_repos( WP_REST_Request $request ) {
		return rest_ensure_response(
			array(
				'ok'    => true,
				'repos' => Repos::for_site( (string) $request['site_id'] ),
			)
		);
	}

	/**
	 * Replaces the repositories linked to a client site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_site_repos( WP_REST_Request $request ) {
		$body = (array) $request->get_json_params();
		$done = Repos::set_for_site( (string) $request['site_id'], array_map( 'strval', (array) ( $body['repos'] ?? array() ) ), get_current_user_id() );

		if ( is_wp_error( $done ) ) {
			return $done;
		}

		return self::site_repos( $request );
	}
}
