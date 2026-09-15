<?php
/**
 * A stand-in for SureCart's API, for the test suite only.
 *
 * Copied into the test WordPress's mu-plugins directory by
 * tests/e2e/helpers/surecart.js before a spec that needs it, and removed
 * afterwards. Never shipped: tests/ is outside every build allowlist.
 *
 * It answers the one call Forge makes — GET /subscriptions — with the fixture
 * in tests/php/fixtures, with the renewal dates rewritten so the first and
 * third subscriptions renew today and the second in thirty days. Any token
 * other than "test-token" is refused, so the failure path can be tested too.
 *
 * It answers inside the same request rather than over HTTP: the suite blocks
 * outbound requests (tests/global-setup.js), and PHP's built-in server could
 * not take a call from itself while it is busy answering the first one.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

const BWX_FORGE_SURECART_STUB_BASE = 'https://surecart.test/v1';

add_filter(
	'bwx_forge_surecart_base_url',
	static function (): string {
		return BWX_FORGE_SURECART_STUB_BASE;
	}
);

add_filter(
	'pre_http_request',
	/**
	 * Answers Forge's call to the stand-in store before WordPress tries the network.
	 *
	 * @param false|array|WP_Error $answer What an earlier filter decided.
	 * @param array<string, mixed> $args   The request.
	 * @param string               $url    Where it was going.
	 * @return false|array|WP_Error
	 */
	static function ( $answer, array $args, string $url ) {
		// Last, not first: the suite's offline guard answers every request with
		// an error whatever came before it, so the stand-in has the final word.

		if ( 0 !== strpos( $url, BWX_FORGE_SURECART_STUB_BASE . '/subscriptions' ) ) {
			return $answer;
		}

		$headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
		$auth    = (string) ( $headers['Authorization'] ?? '' );

		if ( 'Bearer test-token' !== $auth ) {
			return bwx_forge_surecart_stub_response( array( 'error' => 'unauthorized' ), 401 );
		}

		// The fixture is copied in beside this file by the helper.
		$fixture = json_decode( (string) file_get_contents( __DIR__ . '/surecart-subscriptions.json' ), true );

		if ( ! is_array( $fixture ) ) {
			return bwx_forge_surecart_stub_response( array( 'error' => 'fixture missing' ), 500 );
		}

		$today = new DateTimeImmutable( 'today', wp_timezone() );

		$fixture['data'][0]['current_period_end_at'] = $today->getTimestamp() + 3600;
		$fixture['data'][1]['current_period_end_at'] = $today->modify( '+30 days' )->getTimestamp() + 3600;
		$fixture['data'][2]['current_period_end_at'] = $today->getTimestamp() + 3600;

		return bwx_forge_surecart_stub_response( $fixture, 200 );
	},
	PHP_INT_MAX,
	3
);

/**
 * A response shaped the way wp_remote_get would return it.
 *
 * @param array<string, mixed> $body   What the store says.
 * @param int                  $status HTTP status.
 * @return array<string, mixed>
 */
function bwx_forge_surecart_stub_response( array $body, int $status ): array {
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
