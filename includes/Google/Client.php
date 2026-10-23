<?php
/**
 * The calls Forge makes to Google.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Google;

use WP_Error;

/**
 * #606. Four calls, all reads apart from the token exchange: swap a consent
 * code for tokens, refresh an access token, read the primary calendar's
 * address, and read one page of events. Forge never writes to Google.
 *
 * Two failures are named because the sync acts on them: {@see self::GONE}
 * (the sync token has expired; start again with a full read) and
 * {@see self::DENIED} (the person withdrew access, or the tokens no longer
 * work; they have to connect again).
 */
final class Client {

	/**
	 * Google's token endpoint.
	 */
	public const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

	/**
	 * The Calendar API.
	 */
	public const API = 'https://www.googleapis.com/calendar/v3';

	/**
	 * The sync token has expired.
	 */
	public const GONE = 'bwx_forge_google_gone';

	/**
	 * Access was withdrawn, or the tokens no longer work.
	 */
	public const DENIED = 'bwx_forge_google_denied';

	/**
	 * Anything else that went wrong.
	 */
	public const FAILED = 'bwx_forge_google_failed';

	/**
	 * How long a call may take, in seconds.
	 */
	private const TIMEOUT = 15;

	/**
	 * Swaps a consent code for tokens.
	 *
	 * @param string $code What Google sent back to the redirect.
	 * @return array{access_token: string, refresh_token: string, expires_at: int}|WP_Error
	 */
	public static function exchange( string $code ) {
		$answer = self::token(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => Settings::client_id(),
				'client_secret' => (string) Settings::secret(),
				'redirect_uri'  => Settings::redirect_uri(),
			)
		);

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		if ( '' === (string) ( $answer['refresh_token'] ?? '' ) ) {
			return new WP_Error( self::FAILED, __( 'Google did not give Forge lasting access. Try connecting again.', 'blueworx-forge' ) );
		}

		return self::tokens_from( $answer, (string) $answer['refresh_token'] );
	}

	/**
	 * A fresh access token.
	 *
	 * @param string $refresh_token The lasting token.
	 * @return array{access_token: string, refresh_token: string, expires_at: int}|WP_Error
	 */
	public static function refresh( string $refresh_token ) {
		$answer = self::token(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
				'client_id'     => Settings::client_id(),
				'client_secret' => (string) Settings::secret(),
			)
		);

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		// Google sends a new refresh token only sometimes.
		return self::tokens_from( $answer, '' === (string) ( $answer['refresh_token'] ?? '' ) ? $refresh_token : (string) $answer['refresh_token'] );
	}

	/**
	 * The connected account's address: the primary calendar's id.
	 *
	 * @param string $access_token Access token.
	 * @return string|WP_Error
	 */
	public static function primary_email( string $access_token ) {
		$answer = self::get( self::API . '/calendars/primary', $access_token );

		return is_wp_error( $answer ) ? $answer : sanitize_text_field( (string) ( $answer['id'] ?? '' ) );
	}

	/**
	 * One page of the primary calendar's events.
	 *
	 * @param string               $access_token Access token.
	 * @param array<string, mixed> $query        Query parameters.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function events( string $access_token, array $query ) {
		return self::get( self::API . '/calendars/primary/events?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ), $access_token );
	}

	/**
	 * A call to the token endpoint.
	 *
	 * @param array<string, string> $body Form fields.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function token( array $body ) {
		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => self::TIMEOUT,
				'body'    => $body,
			)
		);

		return self::read( $response );
	}

	/**
	 * A read from the Calendar API.
	 *
	 * @param string $url          Where.
	 * @param string $access_token Access token.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function get( string $url, string $access_token ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
			)
		);

		return self::read( $response );
	}

	/**
	 * Google's answer, or what went wrong, in the words the sync acts on.
	 *
	 * @param array<string, mixed>|WP_Error $response From wp_remote_*.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function read( $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( self::FAILED, __( 'Google could not be reached.', 'blueworx-forge' ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$body = is_array( $body ) ? $body : array();

		if ( 410 === $code ) {
			return new WP_Error( self::GONE, __( 'Google asked for a full read.', 'blueworx-forge' ) );
		}

		if ( 401 === $code || 403 === $code || 'invalid_grant' === ( $body['error'] ?? '' ) ) {
			return new WP_Error( self::DENIED, __( 'Google no longer lets Forge read this calendar. Connect it again.', 'blueworx-forge' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d: the HTTP status Google answered with. */
			return new WP_Error( self::FAILED, sprintf( __( 'Google answered with an error (%d).', 'blueworx-forge' ), $code ) );
		}

		return $body;
	}

	/**
	 * Tokens in the shape Forge keeps.
	 *
	 * @param array<string, mixed> $answer  The token endpoint's answer.
	 * @param string               $refresh The refresh token to keep.
	 * @return array{access_token: string, refresh_token: string, expires_at: int}
	 */
	private static function tokens_from( array $answer, string $refresh ): array {
		return array(
			'access_token'  => (string) ( $answer['access_token'] ?? '' ),
			'refresh_token' => $refresh,
			'expires_at'    => bwx_forge_now() + max( 0, (int) ( $answer['expires_in'] ?? 0 ) ),
		);
	}
}
