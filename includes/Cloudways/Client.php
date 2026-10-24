<?php
/**
 * The calls Forge makes to Cloudways.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Cloudways;

use Blueworx\Forge\Tenancy\Secrets;
use WP_Error;

/**
 * #621. Two calls, both reads apart from asking for a token: swap the email
 * and API key for an access token, and list the account's servers with the
 * applications on each. Forge never changes anything at Cloudways.
 *
 * The token lasts about an hour. It is kept sealed for a little less, so a
 * status check is one call rather than two, and asked for again once if
 * Cloudways says it has expired early.
 */
final class Client {

	/**
	 * The Cloudways API.
	 */
	public const API = 'https://api.cloudways.com/api/v1';

	/**
	 * Cloudways refused the email and key.
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
	 * How long a token is kept, in seconds: under Cloudways' hour.
	 */
	private const TOKEN_LIFE = 3000;

	/**
	 * Asks Cloudways for an access token. Never cached: connecting uses this to
	 * check a key before it is saved.
	 *
	 * @param string $email The account email.
	 * @param string $key   The API key.
	 * @return string|WP_Error
	 */
	public static function token( string $email, string $key ) {
		$answer = self::read(
			wp_remote_post(
				self::API . '/oauth/access_token',
				array(
					'timeout' => self::TIMEOUT,
					'body'    => array(
						'email'   => $email,
						'api_key' => $key,
					),
				)
			)
		);

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$token = (string) ( $answer['access_token'] ?? '' );

		return '' === $token
			? new WP_Error( self::DENIED, __( 'Cloudways did not accept that email and API key.', 'blueworx-forge' ) )
			: $token;
	}

	/**
	 * Every server on the account, with the applications on each.
	 *
	 * @return array<int, array{id: string, label: string, status: string, apps: array<int, array{id: string, label: string, domains: array<int, string>}>}>|WP_Error
	 */
	public static function servers() {
		$answer = self::get( '/server', false );

		if ( is_wp_error( $answer ) && self::DENIED === $answer->get_error_code() ) {
			// The token may have run out before Cloudways said it would.
			$answer = self::get( '/server', true );
		}

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
	 * A read from the API with a held, or fresh, token.
	 *
	 * @param string $path  Path under the API.
	 * @param bool   $fresh Ask for a new token first.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function get( string $path, bool $fresh ) {
		$token = self::held_token( $fresh );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

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
	 * The token kept from last time, or a new one.
	 *
	 * The expiry travels inside the value rather than relying on the
	 * transient's own, so a persistent object cache that ignores expiry
	 * cannot hand back a dead token forever.
	 *
	 * @param bool $fresh Ignore any token held.
	 * @return string|WP_Error
	 */
	private static function held_token( bool $fresh ) {
		$held = get_transient( Settings::TOKEN );

		if ( ! $fresh && is_array( $held ) && (int) ( $held['until'] ?? 0 ) > bwx_forge_now() ) {
			$token = Secrets::open( (string) ( $held['token'] ?? '' ) );

			if ( null !== $token ) {
				return $token;
			}
		}

		$key = Settings::key();

		if ( null === $key ) {
			return new WP_Error( self::DENIED, __( 'Cloudways is not connected.', 'blueworx-forge' ) );
		}

		$token = self::token( Settings::email(), $key );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		set_transient(
			Settings::TOKEN,
			array(
				'token' => Secrets::seal( $token ),
				'until' => bwx_forge_now() + self::TOKEN_LIFE,
			),
			self::TOKEN_LIFE
		);

		return $token;
	}

	/**
	 * Cloudways' answer, or what went wrong in words an administrator can use.
	 * Nothing from the request — least of all the key — is repeated back.
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
			return new WP_Error( self::DENIED, __( 'Cloudways did not accept that email and API key.', 'blueworx-forge' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d: the HTTP status Cloudways answered with. */
			return new WP_Error( self::FAILED, sprintf( __( 'Cloudways answered with an error (%d).', 'blueworx-forge' ), $code ) );
		}

		return is_array( $body ) ? $body : array();
	}
}
