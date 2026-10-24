<?php
/**
 * A stand-in for the Cloudways API, for the test suite only (#621).
 *
 * Copied into the test WordPress's mu-plugins directory by
 * tests/e2e/helpers/cloudways.js before a spec that needs it, and removed
 * afterwards. Never shipped: tests/ is outside every build allowlist.
 *
 * Any email with a key starting `good-` gets a token; any other key is
 * refused. The server list is whatever the spec put in through the route at
 * the bottom, and every server-list read is counted, so a spec can see that
 * opening the page twice asked once.
 *
 * It answers inside the same request rather than over HTTP: the suite blocks
 * outbound requests (tests/global-setup.js).
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

const BWX_FORGE_CLOUDWAYS_STUB_OPTION = 'bwx_forge_cloudways_stub';

/**
 * The stub's state.
 *
 * @return array<string, mixed>
 */
function bwx_forge_cloudways_stub_state(): array {
	$state = get_option( BWX_FORGE_CLOUDWAYS_STUB_OPTION, array() );

	return array_merge(
		array(
			'servers' => array(),
			'reads'   => 0,
			'down'    => false,
		),
		is_array( $state ) ? $state : array()
	);
}

/**
 * A response shaped the way wp_remote_* returns it.
 *
 * @param array<string, mixed> $body   JSON body.
 * @param int                  $status HTTP status.
 * @return array<string, mixed>
 */
function bwx_forge_cloudways_stub_response( array $body, int $status = 200 ): array {
	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => (string) wp_json_encode( $body ),
		'response' => array(
			'code'    => $status,
			'message' => 200 === $status ? 'OK' : 'Error',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

add_filter(
	'pre_http_request',
	/**
	 * Answers Forge's calls to Cloudways before WordPress tries the network.
	 *
	 * @param false|array|WP_Error $answer What an earlier filter decided.
	 * @param array<string, mixed> $args   The request.
	 * @param string               $url    Where it was going.
	 * @return false|array|WP_Error
	 */
	static function ( $answer, array $args, string $url ) {
		if ( 0 !== strpos( $url, 'https://api.cloudways.com/api/v1/' ) ) {
			return $answer;
		}

		$state = bwx_forge_cloudways_stub_state();

		if ( $state['down'] ) {
			return new WP_Error( 'http_request_failed', 'Cloudways stand-in is down.' );
		}

		if ( false !== strpos( $url, '/oauth/access_token' ) ) {
			$body = is_array( $args['body'] ?? null ) ? $args['body'] : array();

			return 0 === strpos( (string) ( $body['api_key'] ?? '' ), 'good-' )
				? bwx_forge_cloudways_stub_response(
					array(
						'access_token' => 'cw-token',
						'expires_in'   => 3600,
					)
				)
				: bwx_forge_cloudways_stub_response( array( 'error' => 'invalid_credentials' ), 401 );
		}

		if ( 'Bearer cw-token' !== (string) ( $args['headers']['Authorization'] ?? '' ) ) {
			return bwx_forge_cloudways_stub_response( array( 'error' => 'unauthorised' ), 401 );
		}

		++$state['reads'];
		update_option( BWX_FORGE_CLOUDWAYS_STUB_OPTION, $state, false );

		return bwx_forge_cloudways_stub_response(
			array(
				'status'  => true,
				'servers' => $state['servers'],
			)
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
			'/cloudways',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					$body  = (array) $request->get_json_params();
					$state = bwx_forge_cloudways_stub_state();

					foreach ( array( 'servers', 'down' ) as $field ) {
						if ( array_key_exists( $field, $body ) ) {
							$state[ $field ] = $body[ $field ];
						}
					}

					if ( ! empty( $body['reset'] ) ) {
						$state['reads'] = 0;
					}

					update_option( BWX_FORGE_CLOUDWAYS_STUB_OPTION, $state, false );

					return rest_ensure_response( $state );
				},
			)
		);

		register_rest_route(
			'bwx-forge-test/v1',
			'/cloudways',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					return rest_ensure_response( bwx_forge_cloudways_stub_state() );
				},
			)
		);
	}
);
