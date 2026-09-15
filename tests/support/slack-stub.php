<?php
/**
 * A stand-in for a Slack incoming webhook, for the test suite only.
 *
 * Copied into the test WordPress's mu-plugins directory by
 * tests/e2e/helpers/slack.js before a spec that needs it, and removed
 * afterwards. Never shipped: tests/ is outside every build allowlist.
 *
 * It takes what Forge posts and keeps every message in an option, so a
 * spec can read back exactly what would have reached Slack. A route hands
 * the list over, and a DELETE empties it.
 *
 * The post is caught inside the same request rather than served over HTTP:
 * the suite blocks outbound requests (tests/global-setup.js), and PHP's
 * built-in server could not take a call from itself while busy with the
 * first one. The webhook URL still looks like a route on this site, so the
 * spec can name who a message was for.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

const BWX_FORGE_SLACK_STUB_OPTION = 'bwx_forge_test_slack_messages';

add_filter(
	'bwx_forge_slack_webhook_hosts',
	static function ( array $hosts ): array {
		$hosts[] = rest_url( 'bwx-forge-test/v1/slack/' );

		return $hosts;
	}
);

add_filter(
	'pre_http_request',
	/**
	 * Keeps a post to the stand-in webhook. Last, so the suite's offline
	 * guard, which answers every request with an error, does not have the
	 * final word.
	 *
	 * @param false|array|WP_Error $answer What an earlier filter decided.
	 * @param array<string, mixed> $args   The request.
	 * @param string               $url    Where it was going.
	 * @return false|array|WP_Error
	 */
	static function ( $answer, array $args, string $url ) {
		$prefix = rest_url( 'bwx-forge-test/v1/slack/hook/' );

		if ( 0 !== strpos( $url, $prefix ) ) {
			return $answer;
		}

		$messages   = (array) get_option( BWX_FORGE_SLACK_STUB_OPTION, array() );
		$messages[] = array(
			'who'  => substr( $url, strlen( $prefix ) ),
			'body' => json_decode( (string) ( $args['body'] ?? '' ), true ),
			'at'   => time(),
		);
		update_option( BWX_FORGE_SLACK_STUB_OPTION, $messages, false );

		return array(
			'headers'  => array(),
			'body'     => 'ok',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	PHP_INT_MAX,
	3
);

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'bwx-forge-test/v1',
			'/slack/messages',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => '__return_true',
					'callback'            => static function () {
						return new WP_REST_Response( (array) get_option( BWX_FORGE_SLACK_STUB_OPTION, array() ), 200 );
					},
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => '__return_true',
					'callback'            => static function () {
						delete_option( BWX_FORGE_SLACK_STUB_OPTION );

						return new WP_REST_Response( array(), 200 );
					},
				),
			)
		);
	}
);
