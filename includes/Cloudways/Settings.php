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
 * #621. Entered once by an administrator in Connections: a Cloudways access
 * token (#645 — Cloudways retired the email and API key on 15 October 2026).
 * The token is sealed at rest and never handed back — only its last four
 * characters, so an administrator can tell which one is in.
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
	 * Saves the access token.
	 *
	 * @param string $token The Cloudways access token.
	 */
	public static function save( string $token ): void {
		update_option(
			self::OPTION,
			array(
				'token'        => Secrets::seal( trim( $token ) ),
				'hint'         => substr( trim( $token ), -4 ),
				'connected_at' => bwx_forge_now(),
			),
			false
		);
	}

	/**
	 * The access token, opened. For asking Cloudways only.
	 *
	 * A legacy email and API key saved before #645 has no token, so it reads
	 * as not connected rather than as a key Cloudways will refuse.
	 *
	 * @return string|null
	 */
	public static function token(): ?string {
		$sealed = (string) ( self::stored()['token'] ?? '' );

		return '' === $sealed ? null : Secrets::open( $sealed );
	}

	/**
	 * What an administrator is shown of the token.
	 *
	 * @return string '' when none is saved.
	 */
	public static function token_hint(): string {
		$hint = (string) ( self::stored()['hint'] ?? '' );

		return '' === $hint || ! self::connected() ? '' : '••••' . $hint;
	}

	/**
	 * Whether Forge can ask Cloudways anything.
	 *
	 * @return bool
	 */
	public static function connected(): bool {
		return null !== self::token();
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
	 * Disconnects: the account and what was last seen through it.
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
		delete_option( self::STATUS );
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
