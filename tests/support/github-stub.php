<?php
/**
 * A stand-in for the GitHub API, for the test suite only (#680).
 *
 * Copied into the test WordPress's mu-plugins directory by
 * tests/e2e/helpers/github.js before a spec that needs it, and removed
 * afterwards. Never shipped: tests/ is outside every build allowlist.
 *
 * Any token starting `good-` is accepted; any other is refused. The state is
 * in one option: `repos` (full names the organisation lists), `down` (GitHub
 * cannot be reached), `denied` (every token is refused) and `calls`, a log of
 * method, path and body.
 *
 * It answers inside the same request rather than over HTTP: the suite blocks
 * outbound requests (tests/global-setup.js).
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

const BWX_FORGE_GITHUB_STUB_OPTION = 'bwx_forge_github_stub';

/**
 * The stub's state.
 *
 * @return array<string, mixed>
 */
function bwx_forge_github_stub_state(): array {
	$state = get_option( BWX_FORGE_GITHUB_STUB_OPTION, array() );

	return array_merge(
		array(
			'repos'  => array(),
			'down'   => false,
			'denied' => false,
			'calls'  => array(),
		),
		is_array( $state ) ? $state : array()
	);
}

/**
 * A response shaped the way wp_remote_* returns it.
 *
 * @param array<mixed> $body   JSON body.
 * @param int          $status HTTP status.
 * @return array<string, mixed>
 */
function bwx_forge_github_stub_response( array $body, int $status = 200 ): array {
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
	 * Answers Forge's calls to GitHub before WordPress tries the network.
	 *
	 * @param false|array|WP_Error $answer What an earlier filter decided.
	 * @param array<string, mixed> $args   The request.
	 * @param string               $url    Where it was going.
	 * @return false|array|WP_Error
	 */
	static function ( $answer, array $args, string $url ) {
		if ( 0 !== strpos( $url, 'https://api.github.com/' ) ) {
			return $answer;
		}

		$state = bwx_forge_github_stub_state();

		if ( $state['down'] ) {
			return new WP_Error( 'http_request_failed', 'GitHub stand-in is down.' );
		}

		$path             = (string) substr( $url, strlen( 'https://api.github.com' ) );
		$state['calls'][] = array(
			'method' => (string) ( $args['method'] ?? 'GET' ),
			'path'   => $path,
			'body'   => (string) ( $args['body'] ?? '' ),
		);
		update_option( BWX_FORGE_GITHUB_STUB_OPTION, $state, false );

		if ( $state['denied'] || 0 !== strpos( (string) ( $args['headers']['Authorization'] ?? '' ), 'Bearer good-' ) ) {
			return bwx_forge_github_stub_response( array( 'message' => 'Bad credentials' ), 401 );
		}

		if ( 0 === strpos( $path, '/user' ) ) {
			return bwx_forge_github_stub_response( array( 'login' => 'blueworx-bot' ) );
		}

		if ( 1 === preg_match( '#^/orgs/[^/]+/repos#', $path ) ) {
			$page = 1;

			if ( 1 === preg_match( '#[?&]page=(\d+)#', $path, $found ) ) {
				$page = (int) $found[1];
			}

			$repos = array_map(
				static fn( $name ): array => array( 'full_name' => (string) $name ),
				array_slice( (array) $state['repos'], ( $page - 1 ) * 100, 100 )
			);

			return bwx_forge_github_stub_response( $repos );
		}

		return bwx_forge_github_stub_response( array( 'message' => 'Not Found' ), 404 );
	},
	PHP_INT_MAX,
	3
);

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'bwx-forge-test/v1',
			'/github',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					$body  = (array) $request->get_json_params();
					$state = bwx_forge_github_stub_state();

					foreach ( array( 'repos', 'down', 'denied' ) as $field ) {
						if ( array_key_exists( $field, $body ) ) {
							$state[ $field ] = $body[ $field ];
						}
					}

					if ( ! empty( $body['reset'] ) ) {
						$state['calls'] = array();
					}

					update_option( BWX_FORGE_GITHUB_STUB_OPTION, $state, false );

					return rest_ensure_response( $state );
				},
			)
		);

		register_rest_route(
			'bwx-forge-test/v1',
			'/github',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					return rest_ensure_response( bwx_forge_github_stub_state() );
				},
			)
		);
	}
);
