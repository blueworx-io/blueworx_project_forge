<?php
/**
 * The calls Forge makes to GitHub.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\GitHub;

use BlueworxForgeRestErrors;
use Blueworx\Forge\Rest\Errors;
use WP_Error;

/**
 * #680. One door to the GitHub API, so the token, the headers and the way a
 * failure is described are decided once.
 */
final class Client {

	/**
	 * The GitHub API.
	 */
	public const API = 'https://api.github.com';

	/**
	 * GitHub refused the token: it has expired or been revoked.
	 */
	public const DENIED = 'bwx_forge_github_denied';

	/**
	 * GitHub could not be reached.
	 */
	public const UNREACHABLE = 'bwx_forge_github_unreachable';

	/**
	 * GitHub answered with any other error.
	 */
	public const REFUSED = 'bwx_forge_github_refused';

	/**
	 * How long a call may take, in seconds.
	 */
	private const TIMEOUT = 10;

	/**
	 * How long the repository list is kept, in seconds.
	 */
	private const REPOS_TTL = 600;

	/**
	 * One call.
	 *
	 * @param string               $method GET, POST, PATCH...
	 * @param string               $path   Path under the API, starting with a slash.
	 * @param array<string, mixed> $body   Sent as JSON when not empty.
	 * @param string|null          $token  A token to check, or null for the saved one.
	 * @return array<mixed>|WP_Error The decoded answer.
	 */
	public static function request( string $method, string $path, array $body = array(), ?string $token = null ) {
		$saved = null === $token;
		$token = $token ?? Settings::token();

		if ( null === $token || '' === $token ) {
			return new WP_Error( self::DENIED, __( 'GitHub is not connected.', 'blueworx-forge' ) );
		}

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'Authorization'        => 'Bearer ' . $token,
				'Accept'               => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => '2022-11-28',
				'User-Agent'           => 'Blueworx-Forge',
			),
		);

		if ( array() !== $body ) {
			$args['body']                    = (string) wp_json_encode( $body );
			$args['headers']['Content-Type'] = 'application/json';
		}

		$response = wp_remote_request( self::API . $path, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( self::UNREACHABLE, __( 'GitHub could not be reached.', 'blueworx-forge' ) );
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		// 401 is the token itself refused. A 403 is a token GitHub knows but
		// that lacks a permission for this one call, so it is a refusal.
		if ( 401 === $code ) {
			if ( $saved ) {
				Settings::failed( __( 'GitHub no longer accepts the token. Reconnect it on the Connections page.', 'blueworx-forge' ) );
			}

			return new WP_Error( self::DENIED, __( 'GitHub did not accept that token. It may have expired or lack access: make a new one in GitHub and connect again.', 'blueworx-forge' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			$said = is_array( $decoded ) ? sanitize_text_field( (string) ( $decoded['message'] ?? '' ) ) : '';

			return new WP_Error(
				self::REFUSED,
				substr(
					/* translators: 1: the HTTP status, 2: what GitHub said. */
					sprintf( __( 'GitHub answered with an error (%1$d): %2$s', 'blueworx-forge' ), $code, $said ),
					0,
					120
				),
				array( 'github_status' => $code )
			);
		}

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Who a token belongs to. Checks it before anything is kept.
	 *
	 * @param string $token The token to check.
	 * @return array{login: string}|WP_Error
	 */
	public static function whoami( string $token ) {
		$answer = self::request( 'GET', '/user', array(), $token );

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		return array( 'login' => sanitize_text_field( (string) ( $answer['login'] ?? '' ) ) );
	}

	/**
	 * The answer to give a person when a call to GitHub went wrong.
	 *
	 * @param WP_Error $error       What the call gave back.
	 * @param string   $unreachable What to say when GitHub could not be reached.
	 * @param string   $refused     What to say when GitHub refused for any other reason.
	 * @return WP_Error
	 */
	public static function refusal( WP_Error $error, string $unreachable, string $refused ): WP_Error {
		if ( self::DENIED === $error->get_error_code() ) {
			return Errors::rest( 'github_denied', __( 'GitHub no longer accepts the token. Reconnect it on the Connections page.', 'blueworx-forge' ), 422 );
		}

		return self::UNREACHABLE === $error->get_error_code()
			? Errors::rest( 'github_unreachable', $unreachable, 502 )
			: Errors::rest( 'github_refused', $refused, 502 );
	}

	/**
	 * Every repository the token's own account owns, as lower-case owner/name, sorted.
	 *
	 * @param string|null $token A token to check, or null for the saved one. A token given is
	 *                           not saved yet, so nothing is read from or kept in the cache.
	 * @return array<int, string>|WP_Error
	 */
	public static function repos( ?string $token = null ) {
		$cached = null === $token ? get_transient( Settings::REPOS ) : false;

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$names = array();
		$page  = 1;

		do {
			$answer = self::request( 'GET', '/user/repos?affiliation=owner&per_page=100&page=' . $page, array(), $token );

			if ( is_wp_error( $answer ) ) {
				return $answer;
			}

			foreach ( $answer as $repo ) {
				$name = strtolower( sanitize_text_field( (string) ( $repo['full_name'] ?? '' ) ) );

				if ( '' !== $name ) {
					$names[] = $name;
				}
			}

			$count = count( $answer );
			++$page;
		} while ( $count >= 100 );

		sort( $names );

		if ( null !== $token ) {
			return $names;
		}

		set_transient( Settings::REPOS, $names, self::REPOS_TTL );

		return $names;
	}
}
