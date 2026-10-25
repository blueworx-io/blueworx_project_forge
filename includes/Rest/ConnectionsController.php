<?php
/**
 * Connections to outside services, over REST.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Admin\ConnectionsScreen;
use Blueworx\Forge\Cloudways\Client as Cloudways;
use Blueworx\Forge\Cloudways\Settings as CloudwaysSettings;
use Blueworx\Forge\Cloudways\Status;
use Blueworx\Forge\Network\Client as NetworkClient;
use Blueworx\Forge\Network\Deploys;
use Blueworx\Forge\Network\Settings as NetworkSettings;
use Blueworx\Forge\Tenancy\ClientSites;
use WP_REST_Request;

/**
 * #621. The Connections panel in Settings and the Servers page read and write
 * through here, and only an administrator gets in.
 *
 * A secret goes in and never comes back out: answers carry whether a service
 * is connected, its account email and the last four characters of its key,
 * and nothing else about the key.
 */
final class ConnectionsController {

	/**
	 * Registers the routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		$scope = array(
			'kind'   => Boundary::SCOPE_OPEN,
			'reason' => 'The studio\'s own connections to outside services and its own servers: administrators only, and no secret is ever in the answer.',
		);

		$routes = array(
			array( '/connections', 'GET', 'index' ),
			array( '/connections/cloudways', 'POST', 'connect_cloudways' ),
			array( '/connections/cloudways', 'DELETE', 'disconnect_cloudways' ),
			array( '/servers', 'GET', 'servers' ),
			array( '/connections/network', 'POST', 'connect_network' ),
			array( '/connections/network', 'DELETE', 'disconnect_network' ),
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

		// #621, Part B. Creating a Forge site's WordPress site on the network.
		foreach ( array(
			'GET'  => 'deploy',
			'POST' => 'run_deploy',
		) as $method => $callback ) {
			Server::register_route(
				$route_namespace,
				'/client-sites/(?P<site_id>[A-Za-z0-9_-]+)/deploy',
				array(
					'methods'             => $method,
					'callback'            => array( self::class, $callback ),
					'permission_callback' => array( Permissions::class, 'manage' ),
					'scope'               => array(
						'kind'   => Boundary::SCOPE_SITE,
						'param'  => 'site_id',
						'record' => 'client_site',
					),
				)
			);
		}
	}

	/**
	 * Connects the multisite network, once it has answered a call signed with
	 * the key.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function connect_network( WP_REST_Request $request ) {
		$body = (array) $request->get_json_params();
		$url  = trim( (string) ( $body['url'] ?? '' ) );
		$key  = trim( sanitize_text_field( (string) ( $body['key'] ?? '' ) ) );

		$fields = array();

		if ( ! NetworkSettings::valid_url( $url ) ) {
			$fields['url'] = __( 'Enter the network\'s address, starting https://.', 'blueworx-forge' );
		}

		if ( '' === $key ) {
			$fields['key'] = __( 'Enter the key from Network Admin, Settings, Forge network.', 'blueworx-forge' );
		}

		if ( array() !== $fields ) {
			return Errors::rest( 'invalid_network', (string) reset( $fields ), 400, array( 'fields' => $fields ) );
		}

		$hello = NetworkClient::send( $url, $key, 'GET', '/network/hello' );

		if ( is_wp_error( $hello ) ) {
			return NetworkClient::DENIED === $hello->get_error_code()
				? Errors::rest( 'network_refused', __( 'The network did not accept that key. Nothing was saved.', 'blueworx-forge' ), 422 )
				/* translators: %s: what went wrong reaching the network. */
				: Errors::rest( 'network_unreachable', sprintf( __( '%s Nothing was saved.', 'blueworx-forge' ), $hello->get_error_message() ), 502 );
		}

		NetworkSettings::save( $url, $key, $hello );

		return rest_ensure_response( self::answer() );
	}

	/**
	 * Disconnects the network.
	 *
	 * @return \WP_REST_Response
	 */
	public static function disconnect_network() {
		NetworkSettings::forget();

		return rest_ensure_response( self::answer() );
	}

	/**
	 * Whether a Forge site can have its WordPress site created, and how far
	 * creating it has got.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function deploy( WP_REST_Request $request ) {
		$site = ClientSites::get( (string) $request['site_id'] );

		return null === $site ? Boundary::absent( 'client_site' ) : rest_ensure_response( self::deploy_answer( $site ) );
	}

	/**
	 * Runs one step of creating a Forge site's WordPress site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function run_deploy( WP_REST_Request $request ) {
		$site = ClientSites::get( (string) $request['site_id'] );

		if ( null === $site ) {
			return Boundary::absent( 'client_site' );
		}

		$body = (array) $request->get_json_params();
		$step = (string) ( $body['step'] ?? '' );

		if ( ! in_array( $step, Deploys::STEPS, true ) ) {
			return Errors::rest( 'invalid_step', __( 'That is not a step of creating a site.', 'blueworx-forge' ), 400 );
		}

		if ( ! Deploys::available( $site ) ) {
			return Errors::rest( 'deploy_unavailable', __( 'This site already has a WordPress site connected, or the network is not connected.', 'blueworx-forge' ), 409 );
		}

		$done = Deploys::run( $site, $step, $body, get_current_user_id() );

		if ( is_wp_error( $done ) ) {
			$data = (array) $done->get_error_data();

			return Errors::rest(
				'deploy_failed',
				$done->get_error_message(),
				(int) ( $data['status'] ?? 502 ),
				array(
					'step'   => (string) ( $data['step'] ?? $step ),
					'fields' => (array) ( $data['fields'] ?? array() ),
				)
			);
		}

		return rest_ensure_response( self::deploy_answer( $site ) );
	}

	/**
	 * The deploy answer.
	 *
	 * @param array<string, mixed> $site The Forge site.
	 * @return array<string, mixed>
	 */
	private static function deploy_answer( array $site ): array {
		$about = NetworkSettings::about();

		return array(
			'ok'        => true,
			'available' => Deploys::available( $site ),
			'network'   => array(
				'connected'  => NetworkSettings::connected(),
				'name'       => $about['name'],
				'domain'     => $about['domain'],
				'path'       => $about['path'],
				'subdomains' => $about['subdomains'],
			),
			'deploy'    => Deploys::get( (string) $site['id'] ),
		);
	}

	/**
	 * Every service and whether it is connected.
	 *
	 * @return \WP_REST_Response
	 */
	public static function index() {
		return rest_ensure_response( self::answer() );
	}

	/**
	 * Connects Cloudways, once Cloudways has accepted the access token (#645).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function connect_cloudways( WP_REST_Request $request ) {
		$body  = (array) $request->get_json_params();
		$token = trim( sanitize_text_field( (string) ( $body['token'] ?? '' ) ) );

		if ( '' === $token ) {
			$message = __( 'Enter an access token from Cloudways (Account, then API).', 'blueworx-forge' );

			return Errors::rest( 'invalid_cloudways', $message, 400, array( 'fields' => array( 'token' => $message ) ) );
		}

		// One read, so nothing is kept that Cloudways would refuse.
		$checked = Cloudways::servers( $token );

		if ( is_wp_error( $checked ) ) {
			return Cloudways::DENIED === $checked->get_error_code()
				? Errors::rest( 'cloudways_refused', __( 'Cloudways did not accept that access token. It may have expired or been copied wrongly. Nothing was saved.', 'blueworx-forge' ), 422 )
				: Errors::rest( 'cloudways_unreachable', __( 'Cloudways could not be reached, so the token could not be checked. Nothing was saved; try again.', 'blueworx-forge' ), 502 );
		}

		CloudwaysSettings::save( $token );

		return rest_ensure_response( self::answer() );
	}

	/**
	 * Disconnects Cloudways and forgets what was seen through it.
	 *
	 * @return \WP_REST_Response
	 */
	public static function disconnect_cloudways() {
		CloudwaysSettings::forget();

		return rest_ensure_response( self::answer() );
	}

	/**
	 * The studio's servers. Opening the page asks Cloudways if the last check
	 * is old enough; `check=now` is the Check now button.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function servers( WP_REST_Request $request ) {
		if ( ! CloudwaysSettings::connected() ) {
			return rest_ensure_response(
				array(
					'ok'         => true,
					'connected'  => false,
					'checked_at' => 0,
					'error'      => '',
					'servers'    => array(),
				)
			);
		}

		$status = Status::read( 'now' === (string) $request->get_param( 'check' ) );

		return rest_ensure_response(
			array_merge(
				array(
					'ok'        => true,
					'connected' => true,
				),
				$status
			)
		);
	}

	/**
	 * The Connections answer.
	 *
	 * @return array<string, mixed>
	 */
	private static function answer(): array {
		$wp_admin = admin_url( 'admin.php?page=' . ConnectionsScreen::SLUG );

		return array(
			'ok'       => true,
			'services' => array(
				array(
					'id'           => 'cloudways',
					'name'         => 'Cloudways',
					'connected'    => CloudwaysSettings::connected(),
					'hint'         => CloudwaysSettings::token_hint(),
					'connected_at' => CloudwaysSettings::connected_at(),
					'last_checked' => Status::last()['checked_at'],
				),
				array(
					'id'           => 'network',
					'name'         => 'WordPress multisite network',
					'connected'    => NetworkSettings::connected(),
					'email'        => NetworkSettings::url(),
					'hint'         => NetworkSettings::key_hint(),
					'last_checked' => NetworkSettings::about()['last_ok_at'],
				),
				array(
					'id'   => 'surecart',
					'name' => 'SureCart stores',
					'link' => $wp_admin,
				),
				array(
					'id'   => 'slack',
					'name' => 'Slack',
					'link' => $wp_admin,
				),
			),
		);
	}
}
