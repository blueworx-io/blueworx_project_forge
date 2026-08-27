<?php
/**
 * Where this site's update token lives, and whether it works.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * The credential that lets a site see this repository's releases (#200).
 *
 * The repository is private, so a site with no token is told by GitHub that
 * there is nothing to see — not that it was refused. WordPress reports the
 * plugin as up to date and logs nothing, which is how a site can sit months
 * behind without anybody noticing. That silence is what this class exists to
 * end: the token can be set without editing a file on the server, and the
 * screen that sets it says plainly whether updates can currently be fetched.
 *
 * wp-config.php still wins where it is set. A secret in a file is not in the
 * database, so it does not travel in a database export — the better home on a
 * real site, and the same rule this site's studio credentials already follow.
 */
final class Updates {

	/**
	 * Option holding the token, when wp-config.php does not set one.
	 */
	public const OPTION = 'bwx_forge_client_update_token';

	/**
	 * The wp-config.php constant, which wins wherever it is defined.
	 */
	public const CONSTANT = 'BLUEWORX_PLUGIN_UPDATE_TOKEN';

	/**
	 * The repository releases are published to.
	 */
	public const REPO = 'blueworx-io/blueworx_project_forge';

	/**
	 * Transient prefix the last answer from GitHub is remembered under.
	 */
	public const CACHE_PREFIX = 'bwx_forge_client_update_status_';

	/**
	 * How long that answer is worth reusing. Short, because the screen exists to
	 * be believed — long enough that drawing it is not a network round trip
	 * every time somebody opens it.
	 */
	public const CACHE_SECONDS = 300;

	/**
	 * The token this site should authenticate with.
	 *
	 * @return string
	 */
	public static function token(): string {
		$fixed = self::fixed_token();

		return '' !== $fixed ? $fixed : self::stored_token();
	}

	/**
	 * The token wp-config.php sets, if it sets one.
	 *
	 * @return string
	 */
	public static function fixed_token(): string {
		return defined( self::CONSTANT ) && constant( self::CONSTANT )
			? (string) constant( self::CONSTANT )
			: '';
	}

	/**
	 * The token stored in the database, ignoring wp-config.php.
	 *
	 * @return string
	 */
	public static function stored_token(): string {
		return (string) get_option( self::OPTION, '' );
	}

	/**
	 * Whether wp-config.php fixes the token, so the dashboard cannot change it.
	 *
	 * @return bool
	 */
	public static function is_fixed(): bool {
		return '' !== self::fixed_token();
	}

	/**
	 * Stores a token set in the dashboard.
	 *
	 * @param string $token The token.
	 */
	public static function store( string $token ): void {
		update_option( self::OPTION, sanitize_text_field( $token ) );
	}

	/**
	 * Forgets the stored token. Does not touch wp-config.php.
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Whether updates can currently be fetched, proven by asking GitHub.
	 *
	 * Asked rather than assumed. Holding a token and being allowed to read
	 * releases with it are different things, and the difference is the whole
	 * point: a token that has expired, been revoked, or was scoped to the wrong
	 * repository looks exactly like a working one from here.
	 *
	 * @return array{state: string, message: string, release: string}
	 */
	public static function status(): array {
		$token = self::token();

		if ( '' === $token ) {
			return self::result(
				'none',
				__( 'Updates cannot be fetched: this site has no token, so the releases are invisible to it.', 'blueworx-forge' )
			);
		}

		// Briefly remembered, because this runs while somebody is waiting for
		// the screen to draw and a site that cannot reach GitHub pays the full
		// timeout for it. The token is part of the key rather than something
		// that clears the cache: a different token is a different question, so
		// changing one is answered fresh without anything having to remember to
		// invalidate anything.
		$cache_key = self::CACHE_PREFIX . md5( $token );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$answer = self::ask_github( $token );

		set_transient( $cache_key, $answer, self::CACHE_SECONDS );

		return $answer;
	}

	/**
	 * Asks GitHub whether this token can read the releases.
	 *
	 * @param string $token The token to ask with.
	 * @return array{state: string, message: string, release: string}
	 */
	private static function ask_github( string $token ): array {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'               => 'application/vnd.github+json',
					'Authorization'        => 'Bearer ' . $token,
					'X-GitHub-Api-Version' => '2022-11-28',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::result(
				'unreachable',
				__( 'Updates cannot be fetched: GitHub could not be reached from this site.', 'blueworx-forge' )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			$body    = json_decode( wp_remote_retrieve_body( $response ), true );
			$release = is_array( $body ) ? (string) ( $body['tag_name'] ?? '' ) : '';

			return self::result( 'ok', __( 'Updates can be fetched.', 'blueworx-forge' ), $release );
		}

		// A private repository is invisible rather than forbidden, so a token
		// that cannot read this one answers 404 and not 403. Both mean the same
		// thing to whoever is reading the screen: this token is not the one.
		if ( 401 === $code || 403 === $code || 404 === $code ) {
			return self::result(
				'refused',
				__( 'Updates cannot be fetched: GitHub would not read the releases with this token. Check it has not expired, and that it grants read access to the plugin repository.', 'blueworx-forge' )
			);
		}

		return self::result(
			'unreachable',
			__( 'Updates cannot be fetched: GitHub answered unexpectedly.', 'blueworx-forge' )
		);
	}

	/**
	 * One status answer.
	 *
	 * @param string $state   Machine-readable state.
	 * @param string $message What to show.
	 * @param string $release The latest release tag, where there is one.
	 * @return array{state: string, message: string, release: string}
	 */
	private static function result( string $state, string $message, string $release = '' ): array {
		return array(
			'state'   => $state,
			'message' => $message,
			'release' => $release,
		);
	}
}
