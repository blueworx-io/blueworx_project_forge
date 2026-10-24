<?php
/**
 * A stand-in for a WordPress multisite network running the Forge client
 * plugin, for the test suite only (#621).
 *
 * Copied into the test WordPress's mu-plugins directory by
 * tests/e2e/helpers/network.js before a spec that needs it, and removed
 * afterwards. Never shipped: tests/ is outside every build allowlist.
 *
 * It checks every call's signature against the one key it knows, the way the
 * real network's Gate does, so a spec proves the studio signs what it sends.
 * What the studio asked for is kept for the spec to read back — the site,
 * the link (the key's length, never the key) and the people.
 *
 * It answers inside the same request rather than over HTTP: the suite blocks
 * outbound requests (tests/global-setup.js).
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

const BWX_FORGE_NETWORK_STUB_OPTION = 'bwx_forge_network_stub';
const BWX_FORGE_NETWORK_STUB_URL    = 'https://network.forge.test';
const BWX_FORGE_NETWORK_STUB_KEY    = 'stubnetworkkey0123456789abcdef0123456789abcdef0123456789abcdef01';

/**
 * The stub's state.
 *
 * @return array<string, mixed>
 */
function bwx_forge_network_stub_state(): array {
	$state = get_option( BWX_FORGE_NETWORK_STUB_OPTION, array() );

	return array_merge(
		array(
			'sites'            => array(),
			'linked'           => array(),
			'people'           => array(),
			'fail_people_once' => false,
			'refused'          => 0,
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
function bwx_forge_network_stub_response( array $body, int $status = 200 ): array {
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
	 * Answers Forge's calls to the network before WordPress tries the network.
	 *
	 * @param false|array|WP_Error $answer What an earlier filter decided.
	 * @param array<string, mixed> $args   The request.
	 * @param string               $url    Where it was going.
	 * @return false|array|WP_Error
	 */
	static function ( $answer, array $args, string $url ) {
		$base = BWX_FORGE_NETWORK_STUB_URL . '/wp-json';

		if ( 0 !== strpos( $url, $base . '/' ) ) {
			return $answer;
		}

		$path    = substr( $url, strlen( $base ) );
		$method  = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$body    = (string) ( $args['body'] ?? '' );
		$headers = (array) ( $args['headers'] ?? array() );
		$state   = bwx_forge_network_stub_state();

		$canonical = implode(
			"\n",
			array(
				(string) ( $headers['X-BWX-Site'] ?? '' ),
				$method,
				$path,
				(string) ( $headers['X-BWX-Timestamp'] ?? '' ),
				(string) ( $headers['X-BWX-Nonce'] ?? '' ),
				hash( 'sha256', $body ),
			)
		);

		if ( 'network' !== ( $headers['X-BWX-Site'] ?? '' ) || ! hash_equals( hash_hmac( 'sha256', $canonical, BWX_FORGE_NETWORK_STUB_KEY ), (string) ( $headers['X-BWX-Signature'] ?? '' ) ) ) {
			++$state['refused'];
			update_option( BWX_FORGE_NETWORK_STUB_OPTION, $state, false );

			return bwx_forge_network_stub_response( array( 'code' => 'bwx_forge_network_refused' ), 401 );
		}

		$sent = json_decode( $body, true );
		$sent = is_array( $sent ) ? $sent : array();
		$path = substr( $path, strlen( '/blueworx-forge-client/v1' ) );

		if ( '/network/hello' === $path ) {
			return bwx_forge_network_stub_response(
				array(
					'ok'         => true,
					'name'       => 'Stub network',
					'domain'     => 'network.forge.test',
					'path'       => '/',
					'subdomains' => false,
				)
			);
		}

		if ( '/network/sites' === $path ) {
			$slug = (string) ( $sent['slug'] ?? '' );

			if ( 'taken' === $slug ) {
				return bwx_forge_network_stub_response(
					array(
						'code'    => 'bwx_forge_network_address_taken',
						'message' => 'That address is already taken on the network.',
					),
					409
				);
			}

			$blog = 100 + count( $state['sites'] );

			$state['sites'][ (string) $sent['site_id'] ] = array(
				'blog_id' => $blog,
				'slug'    => $slug,
				'title'   => (string) ( $sent['title'] ?? '' ),
			);
			update_option( BWX_FORGE_NETWORK_STUB_OPTION, $state, false );

			return bwx_forge_network_stub_response(
				array(
					'ok'        => true,
					'blog_id'   => $blog,
					'url'       => BWX_FORGE_NETWORK_STUB_URL . '/' . $slug,
					'admin_url' => BWX_FORGE_NETWORK_STUB_URL . '/' . $slug . '/wp-admin/',
					'existed'   => false,
				)
			);
		}

		if ( 1 === preg_match( '#^/network/sites/(\d+)/link$#', $path, $match ) ) {
			$state['linked'][ (string) ( $sent['site_id'] ?? '' ) ] = array(
				'blog_id'          => (int) $match[1],
				'registry_site_id' => (string) ( $sent['registry_site_id'] ?? '' ),
				'studio_url'       => (string) ( $sent['studio_url'] ?? '' ),
				'key_length'       => strlen( (string) ( $sent['key'] ?? '' ) ),
			);
			update_option( BWX_FORGE_NETWORK_STUB_OPTION, $state, false );

			return bwx_forge_network_stub_response( array( 'ok' => true ) );
		}

		if ( 1 === preg_match( '#^/network/sites/(\d+)/people$#', $path ) ) {
			if ( $state['fail_people_once'] ) {
				$state['fail_people_once'] = false;
				update_option( BWX_FORGE_NETWORK_STUB_OPTION, $state, false );

				return bwx_forge_network_stub_response( array( 'message' => 'The network could not send email just now.' ), 500 );
			}

			$people = (array) ( $sent['people'] ?? array() );

			$state['people'][ (string) ( $sent['site_id'] ?? '' ) ] = $people;
			update_option( BWX_FORGE_NETWORK_STUB_OPTION, $state, false );

			return bwx_forge_network_stub_response(
				array(
					'ok'     => true,
					'people' => array_map(
						static function ( $person ): array {
							return array(
								'email'   => (string) ( $person['email'] ?? '' ),
								'outcome' => 'created',
								'reason'  => '',
							);
						},
						$people
					),
				)
			);
		}

		return bwx_forge_network_stub_response( array( 'message' => 'No such route.' ), 404 );
	},
	PHP_INT_MAX,
	3
);

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'bwx-forge-test/v1',
			'/network',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					return rest_ensure_response( bwx_forge_network_stub_state() );
				},
			)
		);

		register_rest_route(
			'bwx-forge-test/v1',
			'/network',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					$state = bwx_forge_network_stub_state();
					$body  = (array) $request->get_json_params();

					if ( array_key_exists( 'fail_people_once', $body ) ) {
						$state['fail_people_once'] = (bool) $body['fail_people_once'];
					}

					update_option( BWX_FORGE_NETWORK_STUB_OPTION, $state, false );

					return rest_ensure_response( $state );
				},
			)
		);
	}
);
