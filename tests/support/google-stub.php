<?php
/**
 * A stand-in for Google's consent screen, token endpoint and Calendar API,
 * for the test suite only (#606).
 *
 * Copied into the test WordPress's mu-plugins directory by
 * tests/e2e/helpers/google.js before a spec that needs it, and removed
 * afterwards. Never shipped: tests/ is outside every build allowlist.
 *
 * Each test person is a "who". Their consent code is `code-<who>`, their
 * tokens `at-<who>` / `rt-<who>`, and their calendar is whatever the spec put
 * in it through the routes at the bottom. A full read returns every event
 * that is not cancelled; an incremental read returns what changed since,
 * deletions included — the way Google's own sync tokens behave.
 *
 * It answers inside the same request rather than over HTTP: the suite blocks
 * outbound requests (tests/global-setup.js).
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

const BWX_FORGE_GOOGLE_STUB_OPTION = 'bwx_forge_google_stub';

/**
 * The stub's state.
 *
 * @return array<string, mixed>
 */
function bwx_forge_google_stub_state(): array {
	$state = get_option( BWX_FORGE_GOOGLE_STUB_OPTION, array() );

	return array_merge(
		array(
			'calendars' => array(),
			'changed'   => array(),
			'gone'      => array(),
			'next'      => '',
			'reads'     => array(),
		),
		is_array( $state ) ? $state : array()
	);
}

/**
 * Saves the stub's state.
 *
 * @param array<string, mixed> $state The state.
 */
function bwx_forge_google_stub_save( array $state ): void {
	update_option( BWX_FORGE_GOOGLE_STUB_OPTION, $state, false );
}

/**
 * A response shaped the way wp_remote_* returns it.
 *
 * @param array<string, mixed> $body   JSON body.
 * @param int                  $status HTTP status.
 * @return array<string, mixed>
 */
function bwx_forge_google_stub_response( array $body, int $status = 200 ): array {
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

// Google's consent screen is this site's own route, which sends the browser
// straight back with a code.
add_filter(
	'bwx_forge_google_auth_endpoint',
	static function (): string {
		return rest_url( 'bwx-forge-test/v1/google/authorize' );
	}
);

add_filter(
	'pre_http_request',
	/**
	 * Answers Forge's calls to Google before WordPress tries the network.
	 *
	 * @param false|array|WP_Error $answer What an earlier filter decided.
	 * @param array<string, mixed> $args   The request.
	 * @param string               $url    Where it was going.
	 * @return false|array|WP_Error
	 */
	static function ( $answer, array $args, string $url ) {
		if ( 0 === strpos( $url, 'https://oauth2.googleapis.com/token' ) ) {
			$body = is_array( $args['body'] ?? null ) ? $args['body'] : array();

			if ( 'authorization_code' === ( $body['grant_type'] ?? '' ) && 0 === strpos( (string) ( $body['code'] ?? '' ), 'code-' ) ) {
				$who = substr( (string) $body['code'], 5 );

				return bwx_forge_google_stub_response(
					array(
						'access_token'  => 'at-' . $who,
						'refresh_token' => 'rt-' . $who,
						'expires_in'    => 3600,
					)
				);
			}

			if ( 'refresh_token' === ( $body['grant_type'] ?? '' ) && 0 === strpos( (string) ( $body['refresh_token'] ?? '' ), 'rt-' ) ) {
				return bwx_forge_google_stub_response(
					array(
						'access_token' => 'at-' . substr( (string) $body['refresh_token'], 3 ),
						'expires_in'   => 3600,
					)
				);
			}

			return bwx_forge_google_stub_response( array( 'error' => 'invalid_grant' ), 400 );
		}

		if ( 0 !== strpos( $url, 'https://www.googleapis.com/calendar/v3/calendars/primary' ) ) {
			return $answer;
		}

		$headers = is_array( $args['headers'] ?? null ) ? $args['headers'] : array();
		$auth    = (string) ( $headers['Authorization'] ?? '' );

		if ( 0 !== strpos( $auth, 'Bearer at-' ) ) {
			return bwx_forge_google_stub_response( array( 'error' => array( 'code' => 401 ) ), 401 );
		}

		$who   = substr( $auth, strlen( 'Bearer at-' ) );
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$state = bwx_forge_google_stub_state();

		if ( '/calendar/v3/calendars/primary' === $path ) {
			return bwx_forge_google_stub_response( array( 'id' => $who . '@studio.test' ) );
		}

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$calendar = (array) ( $state['calendars'][ $who ] ?? array() );
		$changed  = (array) ( $state['changed'][ $who ] ?? array() );
		$items    = array();

		$state['reads'][ $who ][] = isset( $query['syncToken'] ) ? 'incremental' : 'full';

		if ( isset( $query['syncToken'] ) ) {
			if ( ! empty( $state['gone'][ $who ] ) ) {
				unset( $state['gone'][ $who ] );
				bwx_forge_google_stub_save( $state );

				return bwx_forge_google_stub_response( array( 'error' => array( 'code' => 410 ) ), 410 );
			}

			foreach ( array_keys( $changed ) as $id ) {
				if ( isset( $calendar[ $id ] ) ) {
					$items[] = $calendar[ $id ];
				}
			}
		} else {
			foreach ( $calendar as $event ) {
				if ( 'cancelled' !== ( $event['status'] ?? '' ) ) {
					$items[] = $event;
				}
			}
		}

		$state['changed'][ $who ] = array();
		bwx_forge_google_stub_save( $state );

		return bwx_forge_google_stub_response(
			array(
				'items'         => $items,
				'nextSyncToken' => 'st-' . $who . '-' . count( (array) $state['reads'][ $who ] ),
			)
		);
	},
	PHP_INT_MAX,
	3
);

// #657. A first connection records today as the start date; a spec that
// asked for an earlier one, so its past events still come across, gets it.
add_filter(
	'pre_update_option_bwx_forge_google_starts',
	static function ( $value, $old ) {
		global $wpdb;

		$state = bwx_forge_google_stub_state();
		$value = is_array( $value ) ? $value : array();
		$table = \Blueworx\Forge\Data\Schema::google_connections_table();

		foreach ( array_keys( array_diff_key( $value, is_array( $old ) ? $old : array() ) ) as $user_id ) {
			$email = (string) $wpdb->get_var( $wpdb->prepare( "SELECT email FROM {$table} WHERE id = %s", $user_id ) );
			$who   = strstr( $email, '@studio.test', true );
			$since = false === $who ? '' : (string) ( $state['since'][ $who ] ?? '' );

			if ( '' !== $since ) {
				$value[ $user_id ] = $since;
			}
		}

		return $value;
	},
	10,
	2
);

add_action(
	'rest_api_init',
	static function (): void {
		$open = '__return_true';

		// The consent screen: straight back to Forge with the next person's code.
		register_rest_route(
			'bwx-forge-test/v1',
			'/google/authorize',
			array(
				'methods'             => 'GET',
				'permission_callback' => $open,
				'callback'            => static function ( WP_REST_Request $request ) {
					$state    = bwx_forge_google_stub_state();
					$back     = (string) $request->get_param( 'redirect_uri' );
					$response = new WP_REST_Response( null, 302 );

					$response->header(
						'Location',
						add_query_arg(
							array(
								'code'  => 'code-' . (string) $state['next'],
								'state' => rawurlencode( (string) $request->get_param( 'state' ) ),
							),
							$back
						)
					);

					return $response;
				},
			)
		);

		// Who the next consent is for.
		register_rest_route(
			'bwx-forge-test/v1',
			'/google/next',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => static function ( WP_REST_Request $request ) {
					$state         = bwx_forge_google_stub_state();
					$state['next'] = sanitize_key( (string) $request->get_param( 'who' ) );
					$since         = (string) $request->get_param( 'since' );

					// #657. The start date their first connection records.
					$state['since'][ $state['next'] ] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ? $since : '';
					bwx_forge_google_stub_save( $state );

					return new WP_REST_Response( array( 'ok' => true ), 200 );
				},
			)
		);

		// Puts events on somebody's calendar, or changes them; status
		// "cancelled" deletes one.
		register_rest_route(
			'bwx-forge-test/v1',
			'/google/events',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => static function ( WP_REST_Request $request ) {
					$state = bwx_forge_google_stub_state();
					$who   = sanitize_key( (string) $request->get_param( 'who' ) );

					foreach ( (array) $request->get_param( 'events' ) as $event ) {
						$id = (string) ( $event['id'] ?? '' );

						$state['calendars'][ $who ][ $id ] = array_merge( (array) ( $state['calendars'][ $who ][ $id ] ?? array() ), (array) $event );
						$state['changed'][ $who ][ $id ]   = true;
					}

					bwx_forge_google_stub_save( $state );

					return new WP_REST_Response( array( 'ok' => true ), 200 );
				},
			)
		);

		// The next incremental read for somebody answers 410.
		register_rest_route(
			'bwx-forge-test/v1',
			'/google/gone',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => static function ( WP_REST_Request $request ) {
					$state = bwx_forge_google_stub_state();

					$state['gone'][ sanitize_key( (string) $request->get_param( 'who' ) ) ] = true;
					bwx_forge_google_stub_save( $state );

					return new WP_REST_Response( array( 'ok' => true ), 200 );
				},
			)
		);

		// What reads somebody's calendar has had, full or incremental.
		register_rest_route(
			'bwx-forge-test/v1',
			'/google/reads/(?P<who>[a-z0-9_\-]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $open,
				'callback'            => static function ( WP_REST_Request $request ) {
					$state = bwx_forge_google_stub_state();

					return new WP_REST_Response( (array) ( $state['reads'][ (string) $request['who'] ] ?? array() ), 200 );
				},
			)
		);
	}
);
