<?php
/**
 * The network's key: what the studio signs its calls to the network with.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Network;

/**
 * #621. Made on the network, by a network administrator, on the Forge network
 * screen in Network Admin; shown once there, and entered once in the studio's
 * Connections. A `BWX_FORGE_NETWORK_KEY` constant in wp-config.php wins over
 * the stored one, for a network that would rather keep it out of the
 * database.
 *
 * It is a shared HMAC key, so it has to be kept as issued — the same reason
 * the studio keeps each client site's key (see the studio's Sites\Registry).
 * It is 256 bits from the CSPRNG and can be replaced at any time, which
 * cuts the studio off until the new one is entered.
 */
final class Key {

	/**
	 * Network-wide option the key is kept in.
	 */
	public const OPTION = 'bwx_forge_network_key';

	/**
	 * The key, or '' when none is set.
	 *
	 * @return string
	 */
	public static function get(): string {
		if ( defined( 'BWX_FORGE_NETWORK_KEY' ) && '' !== (string) BWX_FORGE_NETWORK_KEY ) {
			return (string) BWX_FORGE_NETWORK_KEY;
		}

		return (string) get_site_option( self::OPTION, '' );
	}

	/**
	 * Whether wp-config.php fixes the key.
	 *
	 * @return bool
	 */
	public static function fixed(): bool {
		return defined( 'BWX_FORGE_NETWORK_KEY' ) && '' !== (string) BWX_FORGE_NETWORK_KEY;
	}

	/**
	 * Makes a new key and keeps it, replacing any before it.
	 *
	 * @return string The new key, to be shown once.
	 */
	public static function generate(): string {
		$key = bin2hex( random_bytes( 32 ) );

		update_site_option( self::OPTION, $key );

		return $key;
	}
}
