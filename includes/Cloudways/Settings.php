<?php
/**
 * The studio's Cloudways account.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Cloudways;

use Blueworx\Forge\Tenancy\Secrets;

/**
 * #621. Entered once by an administrator in Connections: the Cloudways
 * account email and an API key. The key is sealed at rest and never handed
 * back — only its last four characters, so an administrator can tell which
 * one is in.
 */
final class Settings {

	/**
	 * Where the account is kept.
	 */
	public const OPTION = 'bwx_forge_cloudways';

	/**
	 * Where the last check is kept (see Status).
	 */
	public const STATUS = 'bwx_forge_cloudways_status';

	/**
	 * Where the access token is kept, sealed, between checks.
	 */
	public const TOKEN = 'bwx_forge_cloudways_token';

	/**
	 * Saves the account.
	 *
	 * @param string $email The Cloudways account email.
	 * @param string $key   The API key.
	 */
	public static function save( string $email, string $key ): void {
		update_option(
			self::OPTION,
			array(
				'email'        => trim( $email ),
				'key'          => Secrets::seal( trim( $key ) ),
				'hint'         => substr( trim( $key ), -4 ),
				'connected_at' => bwx_forge_now(),
			),
			false
		);

		// A token belongs to the account it was issued for.
		delete_transient( self::TOKEN );
	}

	/**
	 * The account email, or ''.
	 *
	 * @return string
	 */
	public static function email(): string {
		return (string) ( self::stored()['email'] ?? '' );
	}

	/**
	 * The API key, opened. For asking Cloudways for a token only.
	 *
	 * @return string|null
	 */
	public static function key(): ?string {
		$sealed = (string) ( self::stored()['key'] ?? '' );

		return '' === $sealed ? null : Secrets::open( $sealed );
	}

	/**
	 * What an administrator is shown of the key.
	 *
	 * @return string '' when none is saved.
	 */
	public static function key_hint(): string {
		$hint = (string) ( self::stored()['hint'] ?? '' );

		return '' === $hint ? '' : '••••' . $hint;
	}

	/**
	 * Whether Forge can ask Cloudways anything.
	 *
	 * @return bool
	 */
	public static function connected(): bool {
		return '' !== self::email() && null !== self::key();
	}

	/**
	 * When the account was connected.
	 *
	 * @return int
	 */
	public static function connected_at(): int {
		return (int) ( self::stored()['connected_at'] ?? 0 );
	}

	/**
	 * Disconnects: the account, its token and what was last seen through it.
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
		delete_option( self::STATUS );
		delete_transient( self::TOKEN );
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
