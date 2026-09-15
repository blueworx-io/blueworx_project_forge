<?php
/**
 * A person's own Slack connection.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Slack\Morning;
use Blueworx\Forge\Slack\Notify;
use Blueworx\Forge\Slack\People;
use Blueworx\Forge\Tenancy\Users;
use WP_REST_Request;

/**
 * Everything here is about the person asking and nobody else: their row is
 * found from who is signed in, never from a parameter, so there is no id to
 * get wrong. The webhook goes in and is never given back.
 */
final class SlackController {

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		foreach ( array( 'GET' => 'show', 'POST' => 'connect', 'PATCH' => 'prefs', 'DELETE' => 'disconnect' ) as $method => $callback ) {
			Server::register_route(
				$route_namespace,
				'/me/slack',
				array(
					'methods'             => $method,
					'callback'            => array( self::class, $callback ),
					'permission_callback' => array( Permissions::class, 'signed_in' ),
					'scope'               => array(
						'kind'   => Boundary::SCOPE_OPEN,
						'reason' => 'The signed-in person\'s own row, keyed by who is asking; nothing about any client is read or written.',
					),
				)
			);
		}

		Server::register_route(
			$route_namespace,
			'/slack/morning',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'morning' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Sends the morning message now; administrators only, and it reads nothing back to the caller.',
				),
			)
		);
	}

	/**
	 * Whether the person is connected, and what they get.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function show() {
		$me = self::me();

		if ( null === $me ) {
			return self::nobody();
		}

		return rest_ensure_response( self::state( (string) $me['id'] ) );
	}

	/**
	 * Connects, or replaces the webhook, and sends a test.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function connect( WP_REST_Request $request ) {
		$me = self::me();

		if ( null === $me ) {
			return self::nobody();
		}

		$input = (array) $request->get_json_params();
		$url   = trim( (string) ( $input['url'] ?? '' ) );

		if ( ! People::acceptable( $url ) ) {
			return Errors::rest( 'invalid_webhook', __( 'That is not a Slack incoming webhook. It starts with https://hooks.slack.com/.', 'blueworx-forge' ), 400 );
		}

		People::connect( (string) $me['id'], $url );

		$sent = Notify::test( (string) $me['id'], $url );

		if ( true !== $sent ) {
			return Errors::rest( 'webhook_failed', $sent->get_error_message(), 400 );
		}

		return rest_ensure_response( self::state( (string) $me['id'] ) );
	}

	/**
	 * Changes what the person is told about.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function prefs( WP_REST_Request $request ) {
		$me = self::me();

		if ( null === $me ) {
			return self::nobody();
		}

		$current = People::get( (string) $me['id'] );

		if ( null === $current ) {
			return Errors::rest( 'not_connected', __( 'Connect Slack first.', 'blueworx-forge' ), 409 );
		}

		$input = (array) $request->get_json_params();

		People::set_prefs( (string) $me['id'], People::prefs_from( (array) ( $input['prefs'] ?? array() ), $current['prefs'] ) );

		return rest_ensure_response( self::state( (string) $me['id'] ) );
	}

	/**
	 * Forgets the webhook.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function disconnect() {
		$me = self::me();

		if ( null === $me ) {
			return self::nobody();
		}

		People::disconnect( (string) $me['id'] );

		return rest_ensure_response( self::state( (string) $me['id'] ) );
	}

	/**
	 * Sends the morning message now.
	 *
	 * @return \WP_REST_Response
	 */
	public static function morning() {
		return rest_ensure_response(
			array(
				'ok'   => true,
				'sent' => Morning::run(),
			)
		);
	}

	/**
	 * What the panel draws.
	 *
	 * @param string $user_id The person.
	 * @return array<string, mixed>
	 */
	private static function state( string $user_id ): array {
		$person = People::get( $user_id );

		return array(
			'ok'         => true,
			'connected'  => null !== $person,
			'prefs'      => null === $person ? People::prefs_from( array() ) : $person['prefs'],
			'last_ok_at' => null === $person ? 0 : (int) $person['last_ok_at'],
			'last_error' => null === $person ? '' : (string) $person['last_error'],
			'morning_at' => Morning::time(),
		);
	}

	/**
	 * The Forge person behind the signed-in account.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function me(): ?array {
		return Users::by_wp_user( get_current_user_id() );
	}

	/**
	 * The answer for an account that is not a Forge person.
	 *
	 * @return \WP_Error
	 */
	private static function nobody() {
		return Errors::rest( 'not_a_person', __( 'Your account is not a person in Forge yet, so there is nothing to connect Slack to.', 'blueworx-forge' ), 403 );
	}
}
