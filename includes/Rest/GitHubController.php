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
use Blueworx\Forge\GitHub\Jobs;
use Blueworx\Forge\GitHub\Repos;
use Blueworx\Forge\GitHub\Settings;
use Throwable;
use WP_Error;
use WP_REST_Request;

/**
 * #680. An administrator gives Forge a GitHub token, and nothing else: the
 * account it belongs to is the one whose repos are listed, and Forge makes
 * the webhook secret itself and adds the webhook to each repo it links. The
 * token is checked with GitHub before anything is kept, and neither it nor
 * the secret ever comes back out.
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
					'reason' => 'The webhook Forge adds to each linked repo. Proved by its signature with the secret Forge made, not by a login.',
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
		$type     = $request->get_content_type();

		// Forge's own hooks send JSON. A hook made by hand with the form
		// content type would otherwise be answered as if all were well.
		if ( 'application/json' !== (string) ( $type['value'] ?? '' ) || ! is_array( $request->get_json_params() ) ) {
			return Errors::rest( 'github_not_json', __( 'Forge only reads GitHub messages sent as JSON. Set the webhook\'s content type to application/json.', 'blueworx-forge' ), 400 );
		}

		$payload = (array) $request->get_json_params();

		if ( '' === $event || '' === $delivery ) {
			return Errors::rest( 'invalid_github_message', __( 'That message did not say what it was or which one it was.', 'blueworx-forge' ), 400 );
		}

		try {
			$claimed = Deliveries::claim( $delivery, $event, sanitize_key( (string) ( $payload['action'] ?? '' ) ) );
		} catch ( Throwable ) {
			return self::not_taken();
		}

		if ( ! $claimed ) {
			return rest_ensure_response(
				array(
					'ok'      => true,
					'outcome' => 'duplicate',
				)
			);
		}

		Settings::heard( bwx_forge_now() );

		try {
			$outcome = Inbound::handle( $event, $payload );
		} catch ( Throwable ) {
			$outcome = 'failed';
		}

		// Forgotten rather than kept as failed, so GitHub's Redeliver, which
		// sends the same delivery id, is handled instead of being a repeat.
		if ( 'failed' === $outcome ) {
			Deliveries::release( $delivery );

			return self::not_taken();
		}

		Deliveries::settle( $delivery, $outcome );

		return rest_ensure_response(
			array(
				'ok'      => true,
				'outcome' => $outcome,
			)
		);
	}

	/**
	 * The answer when a message could not be taken in. A failure, so GitHub
	 * shows it as one and it can be sent again.
	 *
	 * @return WP_Error
	 */
	private static function not_taken(): WP_Error {
		Settings::failed( __( 'Forge could not take in a message from GitHub. Press Redeliver on it in the repo\'s webhook settings on GitHub.', 'blueworx-forge' ) );

		return Errors::rest( 'github_message_failed', __( 'Forge could not take that message in. Nothing was kept; send it again from GitHub.', 'blueworx-forge' ), 500 );
	}

	/**
	 * Connects GitHub, once GitHub has accepted the token and can list the
	 * account's repos. Forge makes a new webhook secret each time, and signs
	 * the hook on every linked repo with it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function connect( WP_REST_Request $request ) {
		$body  = (array) $request->get_json_params();
		$token = trim( (string) ( $body['token'] ?? '' ) );

		if ( '' === $token ) {
			$fields = array( 'token' => __( 'Enter the GitHub token.', 'blueworx-forge' ) );

			return Errors::rest( 'invalid_github', $fields['token'], 400, array( 'fields' => $fields ) );
		}

		// Two reads, so nothing is kept that GitHub would refuse.
		$who   = Client::whoami( $token );
		$repos = is_wp_error( $who ) ? $who : Client::repos( $token );

		if ( is_wp_error( $repos ) ) {
			return Client::UNREACHABLE === $repos->get_error_code()
				? Errors::rest( 'github_unreachable', __( 'GitHub could not be reached, so the token could not be checked. Nothing was saved; try again.', 'blueworx-forge' ), 502 )
				: Errors::rest( 'github_refused', __( 'GitHub did not accept that token, or it can\'t list the account\'s repos. Nothing was saved.', 'blueworx-forge' ), 422 );
		}

		$login = (string) ( $who['login'] ?? '' );

		if ( '' === $login ) {
			return Errors::rest( 'github_refused', __( 'GitHub did not say which account the token belongs to. Nothing was saved.', 'blueworx-forge' ), 422 );
		}

		$secret = wp_generate_password( 40, false, false );

		Settings::save( $token, $secret, $login, get_current_user_id() );

		// A list kept from an earlier connection may be another account's.
		delete_transient( Settings::REPOS );
		delete_transient( Settings::REPOS_DOWN );

		// Hooks on repos linked before are still signed with the old secret.
		// Any that can't be updated are shown on the GitHub row.
		Repos::refresh_hooks( $secret );

		// #681. Changes that gave up, most often because the old token ran
		// out, get their tries back and go once this answer has.
		Jobs::reopen();

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
	 * Every repository the account owns, and the site each is linked to.
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

		$names = Client::repos();

		if ( is_wp_error( $names ) ) {
			return Client::refusal(
				$names,
				__( 'GitHub could not be reached, so the repositories could not be listed. Try again.', 'blueworx-forge' ),
				__( 'GitHub would not list the repositories. Try again.', 'blueworx-forge' )
			);
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
