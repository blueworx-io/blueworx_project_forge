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
 * #680. Entered once by an administrator: a GitHub token. The account it
 * belongs to is the owner whose repos Forge lists, and Forge makes the secret
 * GitHub signs its messages with itself. The token and secret are sealed at
 * rest and never handed back — only the last four characters of the token,
 * so an administrator can tell which one is in.
 */
final class Settings {

	/**
	 * Where the connection is kept.
	 */
	public const OPTION = 'bwx_forge_github';

	/**
	 * Where the last thing that went wrong is kept.
	 */
	public const STATUS = 'bwx_forge_github_status';

	/**
	 * When the last signed message arrived. Kept apart from the connection,
	 * so a delivery never rewrites the sealed settings.
	 */
	public const HEARD = 'bwx_forge_github_heard';

	/**
	 * Where the account's repository list is cached.
	 */
	public const REPOS = 'bwx_forge_github_repos';

	/**
	 * #681. Set for a short while after GitHub would not give the list, so
	 * opening or saving a task does not wait on GitHub again each time.
	 */
	public const REPOS_DOWN = 'bwx_forge_github_repos_down';

	/**
	 * Saves the connection, and clears what last went wrong.
	 *
	 * @param string $token  The GitHub token.
	 * @param string $secret The secret GitHub signs its messages with.
	 * @param string $login  Who the token belongs to: the account whose repos are listed.
	 * @param int    $by     The WordPress user connecting it.
	 */
	public static function save( string $token, string $secret, string $login, int $by ): void {
		$token = trim( $token );

		update_option(
			self::OPTION,
			array(
				'token'        => Secrets::seal( $token ),
				'hint'         => substr( $token, -4 ),
				'secret'       => Secrets::seal( trim( $secret ) ),
				'owner'        => strtolower( trim( $login ) ),
				'login'        => trim( $login ),
				'connected_at' => bwx_forge_now(),
				'connected_by' => $by,
			),
			false
		);

		delete_option( self::STATUS );
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
	 * The account whose repos Forge lists, lower case.
	 *
	 * @return string
	 */
	public static function owner(): string {
		$stored = self::stored();

		// Saved as org before the account turned out to be a person's.
		return (string) ( $stored['owner'] ?? $stored['org'] ?? '' );
	}

	/**
	 * Who the token belongs to, as GitHub spells it.
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
		return null !== self::token() && null !== self::secret() && '' !== self::owner();
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
		update_option( self::HEARD, $at, false );
	}

	/**
	 * When the last signed message arrived.
	 *
	 * @return int 0 when none has.
	 */
	public static function last_heard(): int {
		return (int) get_option( self::HEARD, 0 );
	}

	/**
	 * Records what last went wrong, in words an administrator can act on.
	 *
	 * @param string $what What happened.
	 */
	public static function failed( string $what ): void {
		update_option(
			self::STATUS,
			array(
				'at'   => bwx_forge_now(),
				'what' => $what,
			),
			false
		);
	}

	/**
	 * What last went wrong, if anything has since the last good connect.
	 *
	 * @return array{at: int, what: string}
	 */
	public static function problem(): array {
		$status = get_option( self::STATUS, array() );
		$status = is_array( $status ) ? $status : array();

		return array(
			'at'   => (int) ( $status['at'] ?? 0 ),
			'what' => (string) ( $status['what'] ?? '' ),
		);
	}

	/**
	 * Disconnects: the connection, what was last seen through it, and the list.
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
		delete_option( self::STATUS );
		delete_option( self::HEARD );
		delete_transient( self::REPOS );
		delete_transient( self::REPOS_DOWN );
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
