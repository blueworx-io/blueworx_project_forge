<?php
/**
 * The calls Forge makes to Cloudways.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Cloudways;

use WP_Error;

/**
 * #621. One call, a read: list the account's servers with the applications on
 * each. Forge never changes anything at Cloudways.
 *
 * #645. Cloudways retired its email-and-API-key exchange on 15 October 2026.
 * An access token is made in Cloudways with its own expiry and scope, and is
 * sent as it is; Read-Only is enough for this.
 */
final class Client {

	/**
	 * The Cloudways API.
	 */
	public const API = 'https://api.cloudways.com/api/v2';

	/**
	 * Cloudways refused the token.
	 */
	public const DENIED = 'bwx_forge_cloudways_denied';

	/**
	 * Anything else that went wrong.
	 */
	public const FAILED = 'bwx_forge_cloudways_failed';

	/**
	 * How long a call may take, in seconds.
	 */
	private const TIMEOUT = 15;

	/**
	 * Every server on the account, with the applications on each.
	 *
	 * Connecting passes the token it is about to save, so Cloudways checks it
	 * before anything is kept; everything else reads with the saved one.
	 *
	 * @param string|null $token A token to check, or null for the saved one.
	 * @return array<int, array{id: string, label: string, status: string, apps: array<int, array{id: string, label: string, domains: array<int, string>}>}>|WP_Error
	 */
	public static function servers( ?string $token = null ) {
		$token = $token ?? Settings::token();

		if ( null === $token || '' === $token ) {
			return new WP_Error( self::DENIED, __( 'Cloudways is not connected.', 'blueworx-forge' ) );
		}

		$answer = self::get( '/server', $token );

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$servers = array();

		foreach ( (array) ( $answer['servers'] ?? array() ) as $server ) {
			$server = (array) $server;
			$apps   = array();

			foreach ( (array) ( $server['apps'] ?? array() ) as $app ) {
				$app       = (array) $app;
				$addresses = array_merge(
					array( (string) ( $app['cname'] ?? '' ), (string) ( $app['app_fqdn'] ?? '' ) ),
					array_map( 'strval', (array) ( $app['aliases'] ?? array() ) )
				);

				$apps[] = array(
					'id'      => sanitize_text_field( (string) ( $app['id'] ?? '' ) ),
					'label'   => sanitize_text_field( (string) ( $app['label'] ?? '' ) ),
					'domains' => array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $addresses ) ) ) ),
				);
			}

			$servers[] = array(
				'id'     => sanitize_text_field( (string) ( $server['id'] ?? '' ) ),
				'label'  => sanitize_text_field( (string) ( $server['label'] ?? '' ) ),
				'status' => sanitize_key( (string) ( $server['status'] ?? '' ) ),
				'apps'   => $apps,
			);
		}

		return $servers;
	}

	/**
	 * A read from the API.
	 *
	 * @param string $path  Path under the API.
	 * @param string $token The access token.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function get( string $path, string $token ) {
		return self::read(
			wp_remote_get(
				self::API . $path,
				array(
					'timeout' => self::TIMEOUT,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Accept'        => 'application/json',
					),
				)
			)
		);
	}

	/**
	 * Cloudways' answer, or what went wrong in words an administrator can use.
	 * Nothing from the request — least of all the token — is repeated back.
	 *
	 * @param array<string, mixed>|WP_Error $response From wp_remote_*.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function read( $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( self::FAILED, __( 'Cloudways could not be reached.', 'blueworx-forge' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( self::DENIED, __( 'Cloudways did not accept that access token. It may have expired: make a new one in Cloudways and connect again.', 'blueworx-forge' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d: the HTTP status Cloudways answered with. */
			return new WP_Error( self::FAILED, sprintf( __( 'Cloudways answered with an error (%d).', 'blueworx-forge' ), $code ) );
		}

		return is_array( $body ) ? $body : array();
	}
}
