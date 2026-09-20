<?php
/**
 * Where this site's updates come from, and whether it can see them.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge;

/**
 * Whether this site can see its own releases (#200, #340).
 *
 * The repository is public, so no site needs a token to see its releases
 * (#340). Before that, the repository was private, every site needed a
 * read-only token entered on it, and a site without one was told by GitHub
 * that there was nothing to see: WordPress reported the plugin as up to date
 * and a site could sit months behind without anybody noticing.
 *
 * What this class keeps from then is the honesty: the Updates screen asks the
 * release repository and says plainly whether updates can be fetched from
 * here, rather than leaving it to be discovered later.
 */
final class Updates {

	/**
	 * The repository releases are published to.
	 *
	 * Keep equal to the update checker's URL in blueworx-forge.php and to the
	 * release workflow's `releases_repo`, or the screen reports on a different
	 * place from the one WordPress updates from.
	 */
	public const REPO = 'blueworx-io/blueworx_project_forge';

	/**
	 * Transient the last answer from GitHub is remembered under.
	 */
	public const CACHE_KEY = 'bwx_forge_update_status';

	/**
	 * How long that answer is worth reusing. Short, because the screen exists to
	 * be believed — long enough that drawing it is not a network round trip
	 * every time somebody opens it.
	 */
	public const CACHE_SECONDS = 300;

	/**
	 * The option a token was kept in before #340. Removed on sight: it is a
	 * credential nothing reads any more, and a secret that lingers in a
	 * database is one that turns up in an export.
	 */
	public const OLD_TOKEN_OPTION = 'bwx_forge_update_token';

	/**
	 * Forgets a token a site was given before releases went public.
	 */
	public static function retire_stored_token(): void {
		delete_option( self::OLD_TOKEN_OPTION );
	}

	/**
	 * Whether updates can currently be fetched, proven by asking GitHub.
	 *
	 * @return array{state: string, message: string, release: string}
	 */
	public static function status(): array {
		// Briefly remembered, because this runs while somebody is waiting for
		// the screen to draw and a site that cannot reach GitHub pays the full
		// timeout for it.
		$cached = get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$answer = self::ask_github();

		set_transient( self::CACHE_KEY, $answer, self::CACHE_SECONDS );

		return $answer;
	}

	/**
	 * Asks GitHub for the latest release, with no credentials at all — which
	 * is exactly how the update checker asks, so this answer is that one.
	 *
	 * @return array{state: string, message: string, release: string}
	 */
	private static function ask_github(): array {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'               => 'application/vnd.github+json',
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

		// Nothing published yet, or the repository is not where this build
		// expects it: either way there is no release for this site to take.
		if ( 404 === $code ) {
			return self::result(
				'missing',
				__( 'Updates cannot be fetched: no release was found where this site looks for them.', 'blueworx-forge' )
			);
		}

		// GitHub allows a fixed number of anonymous requests an hour from one
		// address, and a busy host can use them up. It passes.
		if ( 403 === $code || 429 === $code ) {
			return self::result(
				'limited',
				__( 'Updates cannot be checked right now: GitHub has had too many requests from this address. It will work again within the hour.', 'blueworx-forge' )
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
