<?php
/**
 * The one and only chance to see a newly issued key.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

/**
 * Carries a freshly issued key from the action that made it to the screen that
 * shows it, once.
 *
 * A key is issued during a form POST, and a form POST redirects — otherwise a
 * refresh registers the site again. The key therefore has to survive one
 * redirect, and it must not do so in the URL, where it would sit in the
 * browser's history, in the server's access log and in any referrer header the
 * next page sends.
 *
 * So it is held for the issuing administrator alone, for a few minutes, and is
 * taken rather than read: whoever sees it first is the only one who sees it.
 * After that the answer to "what was the key" is to rotate it.
 */
final class IssuedKey {

	/**
	 * How long an unseen key waits before it expires.
	 */
	public const TTL = 300;

	/**
	 * Holds a key for the administrator who issued it.
	 *
	 * @param int    $user_id The issuing administrator.
	 * @param string $site_id The site the key belongs to.
	 * @param string $key     The key itself.
	 */
	public static function remember( int $user_id, string $site_id, string $key ): void {
		set_transient(
			self::name( $user_id ),
			array(
				'site_id' => $site_id,
				'key'     => $key,
			),
			self::TTL
		);
	}

	/**
	 * Takes the held key, leaving nothing behind.
	 *
	 * @param int $user_id The administrator asking.
	 * @return array{site_id: string, key: string}|null
	 */
	public static function take( int $user_id ): ?array {
		$held = get_transient( self::name( $user_id ) );

		delete_transient( self::name( $user_id ) );

		if ( ! is_array( $held ) || ! isset( $held['site_id'], $held['key'] ) ) {
			return null;
		}

		return array(
			'site_id' => (string) $held['site_id'],
			'key'     => (string) $held['key'],
		);
	}

	/**
	 * The transient name for one administrator.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private static function name( int $user_id ): string {
		return 'bwx_forge_issued_key_' . $user_id;
	}
}
