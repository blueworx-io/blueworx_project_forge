<?php
/**
 * The studio's Google OAuth client.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Google;

use Blueworx\Forge\Tenancy\Secrets;

/**
 * #606. Entered once by an administrator in Forge's Settings: the client id
 * and secret of the studio's Google Cloud project. The secret is sealed at
 * rest and never handed back — only its last four characters, so an
 * administrator can tell which one is in.
 */
final class Settings {

	/**
	 * Where it is kept.
	 */
	public const OPTION = 'bwx_forge_google_client';

	/**
	 * Read-only calendar access, and nothing else (the design's one scope).
	 */
	public const SCOPE = 'https://www.googleapis.com/auth/calendar.readonly';

	/**
	 * Google's consent screen.
	 */
	public const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

	/**
	 * Saves the client. A blank secret keeps the one already saved, so the
	 * id can be corrected without pasting the secret again.
	 *
	 * @param string $client_id The OAuth client id.
	 * @param string $secret    The OAuth client secret, or '' to keep it.
	 */
	public static function save( string $client_id, string $secret ): void {
		$current = self::stored();

		update_option(
			self::OPTION,
			array(
				'client_id' => trim( $client_id ),
				'secret'    => '' === trim( $secret ) ? (string) ( $current['secret'] ?? '' ) : Secrets::seal( trim( $secret ) ),
				'hint'      => '' === trim( $secret ) ? (string) ( $current['hint'] ?? '' ) : substr( trim( $secret ), -4 ),
			)
		);
	}

	/**
	 * The client id, or ''.
	 *
	 * @return string
	 */
	public static function client_id(): string {
		return (string) ( self::stored()['client_id'] ?? '' );
	}

	/**
	 * The secret, opened. For the token exchange only.
	 *
	 * @return string|null
	 */
	public static function secret(): ?string {
		$sealed = (string) ( self::stored()['secret'] ?? '' );

		return '' === $sealed ? null : Secrets::open( $sealed );
	}

	/**
	 * What an administrator is shown of the secret.
	 *
	 * @return string '' when none is saved.
	 */
	public static function secret_hint(): string {
		$hint = (string) ( self::stored()['hint'] ?? '' );

		return '' === $hint ? '' : '••••' . $hint;
	}

	/**
	 * Whether people can connect.
	 *
	 * @return bool
	 */
	public static function configured(): bool {
		return '' !== self::client_id() && null !== self::secret();
	}

	/**
	 * The exact address to enter in Google Cloud as the redirect URI.
	 *
	 * @return string
	 */
	public static function redirect_uri(): string {
		return admin_url( 'admin-post.php?action=bwx_forge_google' );
	}

	/**
	 * Google's consent screen for one connection attempt.
	 *
	 * @param string $state The single-use state for this attempt.
	 * @return string
	 */
	public static function auth_url( string $state ): string {
		$base = (string) apply_filters( 'bwx_forge_google_auth_endpoint', self::AUTH_ENDPOINT );

		return $base . '?' . http_build_query(
			array(
				'client_id'              => self::client_id(),
				'redirect_uri'           => self::redirect_uri(),
				'response_type'          => 'code',
				'scope'                  => self::SCOPE,
				'access_type'            => 'offline',
				'prompt'                 => 'consent',
				'include_granted_scopes' => 'true',
				'state'                  => $state,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * What is saved.
	 *
	 * @return array<string, mixed>
	 */
	private static function stored(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}
}
