<?php
/**
 * Signed calls from the studio to the multisite network.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Network;

use Blueworx\Forge\Rest\Signature;
use WP_Error;

/**
 * #621. The studio's half of the network's signed routes: the same canonical
 * form a client site signs its calls to the studio with (Rest\Signature), as
 * the fixed site `network`, under the network's key. The client plugin's
 * Network\Gate checks it.
 *
 * The body is encoded once and both signed and sent, so the bytes the
 * network hashes are the bytes that were signed.
 */
final class Client {

	/**
	 * The client plugin's REST namespace, as a path.
	 */
	public const NAMESPACE_PATH = '/blueworx-forge-client/v1';

	/**
	 * The network refused the call: the key is wrong, or it was not signed.
	 */
	public const DENIED = 'bwx_forge_network_denied';

	/**
	 * The network could not be reached, or answered with an error.
	 */
	public const FAILED = 'bwx_forge_network_failed';

	/**
	 * How long a call may take, in seconds. Making a site takes a while.
	 */
	private const TIMEOUT = 45;

	/**
	 * A call to the connected network.
	 *
	 * @param string               $method GET or POST.
	 * @param string               $route  Route under the namespace, e.g. /network/sites.
	 * @param array<string, mixed> $body   JSON body, for a POST.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function call( string $method, string $route, array $body = array() ) {
		$key = Settings::key();

		if ( null === $key || '' === Settings::url() ) {
			return new WP_Error( self::DENIED, __( 'The WordPress network is not connected. Connect it in Settings, under Connections.', 'blueworx-forge' ) );
		}

		$answer = self::send( Settings::url(), $key, $method, $route, $body );

		if ( ! is_wp_error( $answer ) ) {
			Settings::note_ok();
		}

		return $answer;
	}

	/**
	 * A call to a network with a given address and key: connecting uses this
	 * to check them before they are saved.
	 *
	 * @param string               $url    The network's address.
	 * @param string               $key    The network's key.
	 * @param string               $method GET or POST.
	 * @param string               $route  Route under the namespace.
	 * @param array<string, mixed> $body   JSON body, for a POST.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function send( string $url, string $key, string $method, string $route, array $body = array() ) {
		$method  = strtoupper( $method );
		$path    = self::NAMESPACE_PATH . $route;
		$payload = 'GET' === $method ? '' : (string) wp_json_encode( $body );
		$stamp   = (string) time();
		$nonce   = bin2hex( random_bytes( 16 ) );

		$args = array(
			'method'      => $method,
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'headers'     => array(
				'Content-Type'    => 'application/json',
				'Accept'          => 'application/json',
				'X-BWX-Site'      => 'network',
				'X-BWX-Timestamp' => $stamp,
				'X-BWX-Nonce'     => $nonce,
				'X-BWX-Signature' => Signature::sign( $key, 'network', $method, $path, $stamp, $nonce, $payload ),
			),
		);

		if ( 'GET' !== $method ) {
			$args['body'] = $payload;
		}

		$target   = untrailingslashit( $url ) . '/wp-json' . $path;
		$response = 'GET' === $method ? wp_remote_get( $target, $args ) : wp_remote_post( $target, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( self::FAILED, __( 'The WordPress network could not be reached.', 'blueworx-forge' ) );
		}

		$code   = wp_remote_retrieve_response_code( $response );
		$answer = json_decode( wp_remote_retrieve_body( $response ), true );
		$answer = is_array( $answer ) ? $answer : array();

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( self::DENIED, __( 'The WordPress network did not accept the key.', 'blueworx-forge' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			$said = sanitize_text_field( (string) ( $answer['message'] ?? '' ) );

			return new WP_Error(
				self::FAILED,
				'' !== $said
					? $said
					/* translators: %d: the HTTP status the network answered with. */
					: sprintf( __( 'The WordPress network answered with an error (%d).', 'blueworx-forge' ), $code )
			);
		}

		return $answer;
	}
}
