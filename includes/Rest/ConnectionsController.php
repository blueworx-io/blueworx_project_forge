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
	 * Connects Cloudways, once Cloudways has accepted the email and key.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function connect_cloudways( WP_REST_Request $request ) {
		$body  = (array) $request->get_json_params();
		$email = trim( sanitize_email( (string) ( $body['email'] ?? '' ) ) );
		$key   = trim( sanitize_text_field( (string) ( $body['api_key'] ?? '' ) ) );

		$fields = array();

		if ( '' === $email || ! is_email( $email ) ) {
			$fields['email'] = __( 'Enter the email you sign in to Cloudways with.', 'blueworx-forge' );
		}

		if ( '' === $key ) {
			$fields['api_key'] = __( 'Enter the API key from Cloudways (Account, then API Integration).', 'blueworx-forge' );
		}

		if ( array() !== $fields ) {
			return Errors::rest( 'invalid_cloudways', (string) reset( $fields ), 400, array( 'fields' => $fields ) );
		}

		$token = Cloudways::token( $email, $key );

		if ( is_wp_error( $token ) ) {
			return Cloudways::DENIED === $token->get_error_code()
				? Errors::rest( 'cloudways_refused', __( 'Cloudways did not accept that email and API key. Nothing was saved.', 'blueworx-forge' ), 422 )
				: Errors::rest( 'cloudways_unreachable', __( 'Cloudways could not be reached, so the key could not be checked. Nothing was saved; try again.', 'blueworx-forge' ), 502 );
		}

		CloudwaysSettings::save( $email, $key );

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
					'email'        => CloudwaysSettings::email(),
					'hint'         => CloudwaysSettings::key_hint(),
					'connected_at' => CloudwaysSettings::connected_at(),
					'last_checked' => Status::last()['checked_at'],
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
