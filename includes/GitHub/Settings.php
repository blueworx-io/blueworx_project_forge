<?php
/**
 * The studio's GitHub connection.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\GitHub;

use Blueworx\Forge\Tenancy\Secrets;

/**
 * #680. Entered once by an administrator: a GitHub token, the organisation to
 * watch, and the secret GitHub signs its messages with. The token and secret
 * are sealed at rest and never handed back — only the last four characters of
 * the token, so an administrator can tell which one is in.
 */
final class Settings {

	/**
	 * Where the connection is kept.
	 */
	public const OPTION = 'bwx_forge_github';

	/**
	 * Where the last check is kept.
	 */
	public const STATUS = 'bwx_forge_github_status';

	/**
	 * Where the organisation's repository list is cached.
	 */
	public const REPOS = 'bwx_forge_github_repos';

	/**
	 * Saves the connection.
	 *
	 * @param string $token  The GitHub token.
	 * @param string $org    The organisation Forge watches.
	 * @param string $secret The secret GitHub signs its messages with.
	 * @param string $login  Who the token belongs to.
	 * @param int    $by     The WordPress user connecting it.
	 */
	public static function save( string $token, string $org, string $secret, string $login, int $by ): void {
		$token = trim( $token );

		update_option(
			self::OPTION,
			array(
				'token'        => Secrets::seal( $token ),
				'hint'         => substr( $token, -4 ),
				'secret'       => Secrets::seal( trim( $secret ) ),
				'org'          => strtolower( trim( $org ) ),
				'login'        => trim( $login ),
				'connected_at' => bwx_forge_now(),
				'connected_by' => $by,
				'heard'        => (int) ( self::stored()['heard'] ?? 0 ),
			),
			false
		);
	}

	/**
	 * The token, opened. For asking GitHub only.
	 *
	 * @return string|null
	 */
	public static function token(): ?string {
		return self::open( 'token' );
	}

	/**
	 * The secret GitHub signs its messages with, opened.
	 *
	 * @return string|null
	 */
	public static function secret(): ?string {
		return self::open( 'secret' );
	}

	/**
	 * The organisation Forge watches.
	 *
	 * @return string
	 */
	public static function org(): string {
		return (string) ( self::stored()['org'] ?? '' );
	}

	/**
	 * Who the token belongs to.
	 *
	 * @return string
	 */
	public static function login(): string {
		return (string) ( self::stored()['login'] ?? '' );
	}

	/**
	 * Whether Forge can ask, and listen to, GitHub.
	 *
	 * @return bool
	 */
	public static function connected(): bool {
		return null !== self::token() && null !== self::secret() && '' !== self::org();
	}

	/**
	 * What an administrator is shown of the token.
	 *
	 * @return string '' when not connected.
	 */
	public static function token_hint(): string {
		$hint = (string) ( self::stored()['hint'] ?? '' );

		return '' === $hint || ! self::connected() ? '' : '••••' . $hint;
	}

	/**
	 * When it was connected.
	 *
	 * @return int
	 */
	public static function connected_at(): int {
		return (int) ( self::stored()['connected_at'] ?? 0 );
	}

	/**
	 * The WordPress user who connected it. Work brought in is recorded under them.
	 *
	 * @return int
	 */
	public static function actor(): int {
		return (int) ( self::stored()['connected_by'] ?? 0 );
	}

	/**
	 * Notes when the last signed message arrived.
	 *
	 * @param int $at Unix time.
	 */
	public static function heard( int $at ): void {
		$stored = self::stored();

		if ( array() === $stored ) {
			return;
		}

		$stored['heard'] = $at;

		update_option( self::OPTION, $stored, false );
	}

	/**
	 * When the last signed message arrived.
	 *
	 * @return int 0 when none has.
	 */
	public static function last_heard(): int {
		return (int) ( self::stored()['heard'] ?? 0 );
	}

	/**
	 * Disconnects: the connection, what was last seen through it, and the list.
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
		delete_option( self::STATUS );
		delete_transient( self::REPOS );
	}

	/**
	 * A sealed value, opened.
	 *
	 * @param string $key Which one.
	 * @return string|null
	 */
	private static function open( string $key ): ?string {
		$sealed = (string) ( self::stored()[ $key ] ?? '' );

		return '' === $sealed ? null : Secrets::open( $sealed );
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
