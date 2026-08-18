<?php
/**
 * The register of client sites and their keys.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Sites;

/**
 * ARCH-6. The studio issues a per-site key at registration; keys are rotatable
 * and revocable from the studio; use of a revoked key is a security event.
 *
 * Registration is a manual studio action — sites are installed and connected by
 * us. There is deliberately no method here that lets a site enrol itself, and
 * the REST routes that reach this class are gated to the studio administrator
 * capability, matching "register or revoke a client site key" in
 * docs/architecture/permission-matrix.md.
 *
 * **On storing the key.** Signed requests are HMAC, so both ends must hold the
 * same secret and the studio cannot store only a hash the way it would a
 * password. The key is therefore stored as issued. It is 256 bits of
 * `random_bytes`, it is shown exactly once at registration and never returned
 * again by any read method, and it is rotatable and revocable — which is what
 * makes a leak survivable rather than fatal. Milestone 2 moves this to the
 * Client Site Integration record, where it can be encrypted at rest.
 */
final class Registry {

	/**
	 * Option holding every registered site.
	 */
	public const OPTION = 'bwx_forge_client_sites';

	/**
	 * A site that may sign requests.
	 */
	public const STATUS_ACTIVE = 'active';

	/**
	 * A site that has been cut off. The record stays; the key stops working.
	 */
	public const STATUS_REVOKED = 'revoked';

	/**
	 * Registers a client site and issues its first key.
	 *
	 * The key is in the return value and nowhere else — no read method hands it
	 * back, so it is shown once and then only the client site has it.
	 *
	 * @param string $name Human-readable site name.
	 * @param string $url  The site's URL.
	 * @return array{site_id: string, key: string, name: string, url: string}
	 */
	public static function register( string $name, string $url ): array {
		$sites   = self::sites();
		$site_id = self::new_site_id();
		$key     = self::new_key();

		$sites[ $site_id ] = array(
			'name'       => sanitize_text_field( $name ),
			'url'        => esc_url_raw( $url ),
			'key'        => $key,
			'status'     => self::STATUS_ACTIVE,
			'created_at' => bwx_forge_now(),
			'rotated_at' => 0,
			'revoked_at' => 0,
		);

		self::save( $sites );

		return array(
			'site_id' => $site_id,
			'key'     => $key,
			'name'    => $sites[ $site_id ]['name'],
			'url'     => $sites[ $site_id ]['url'],
		);
	}

	/**
	 * A site's record without its key.
	 *
	 * @param string $site_id Site id.
	 * @return array<string, mixed>|null Null when no such site was registered.
	 */
	public static function get( string $site_id ): ?array {
		$sites = self::sites();

		if ( ! isset( $sites[ $site_id ] ) ) {
			return null;
		}

		$record = $sites[ $site_id ];
		unset( $record['key'] );

		return $record;
	}

	/**
	 * Every registered site, keyed by id, without keys.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$listed = array();

		foreach ( self::sites() as $site_id => $record ) {
			unset( $record['key'] );
			$listed[ $site_id ] = $record;
		}

		return $listed;
	}

	/**
	 * The signing key for a site, or null when there is not a usable one.
	 *
	 * Revoked sites return null rather than their old key: revocation has to be
	 * enforced here, at the one place the key is read, or every caller would have
	 * to remember to check the status as well.
	 *
	 * @param string $site_id Site id.
	 * @return string|null
	 */
	public static function key_for( string $site_id ): ?string {
		$sites = self::sites();

		if ( ! isset( $sites[ $site_id ] ) ) {
			return null;
		}

		if ( self::STATUS_ACTIVE !== $sites[ $site_id ]['status'] ) {
			return null;
		}

		return (string) $sites[ $site_id ]['key'];
	}

	/**
	 * Whether a site is registered at all, whatever its status.
	 *
	 * @param string $site_id Site id.
	 * @return bool
	 */
	public static function exists( string $site_id ): bool {
		return isset( self::sites()[ $site_id ] );
	}

	/**
	 * Whether a registered site has been revoked.
	 *
	 * @param string $site_id Site id.
	 * @return bool
	 */
	public static function is_revoked( string $site_id ): bool {
		$sites = self::sites();

		return isset( $sites[ $site_id ] ) && self::STATUS_REVOKED === $sites[ $site_id ]['status'];
	}

	/**
	 * Issues a new key, invalidating the old one immediately.
	 *
	 * @param string $site_id Site id.
	 * @return string|null The new key, or null when the site is unknown or revoked.
	 */
	public static function rotate( string $site_id ): ?string {
		$sites = self::sites();

		if ( ! isset( $sites[ $site_id ] ) ) {
			return null;
		}

		// Rotating a revoked site would quietly reinstate it. Bringing a site
		// back is a registration decision, not a key operation.
		if ( self::STATUS_ACTIVE !== $sites[ $site_id ]['status'] ) {
			return null;
		}

		$key = self::new_key();

		$sites[ $site_id ]['key']        = $key;
		$sites[ $site_id ]['rotated_at'] = bwx_forge_now();

		self::save( $sites );

		return $key;
	}

	/**
	 * Cuts a site off. The record stays so its history remains readable.
	 *
	 * @param string $site_id Site id.
	 * @return bool False when there was no such site.
	 */
	public static function revoke( string $site_id ): bool {
		$sites = self::sites();

		if ( ! isset( $sites[ $site_id ] ) ) {
			return false;
		}

		$sites[ $site_id ]['status']     = self::STATUS_REVOKED;
		$sites[ $site_id ]['revoked_at'] = bwx_forge_now();
		// The key is cleared as well as the status. Revocation should survive
		// somebody later "fixing" a status check.
		$sites[ $site_id ]['key'] = '';

		self::save( $sites );

		return true;
	}

	/**
	 * Every site record, including keys. Private: no caller outside this class
	 * has a reason to hold a list of every key at once.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function sites(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Persists the register.
	 *
	 * @param array<string, array<string, mixed>> $sites Sites.
	 */
	private static function save( array $sites ): void {
		update_option( self::OPTION, $sites );
	}

	/**
	 * A new site id. Random rather than sequential: a site id appears in request
	 * headers and logs, and a sequential one would advertise how many clients
	 * exist and let a caller guess the next.
	 *
	 * @return string
	 */
	private static function new_site_id(): string {
		return 'site_' . bin2hex( random_bytes( 8 ) );
	}

	/**
	 * A new signing key: 256 bits from the CSPRNG, as hex.
	 *
	 * Hex rather than base64 so the key is safe in a header, a URL and a
	 * wp-config.php line without anybody having to think about escaping it.
	 *
	 * @return string
	 */
	private static function new_key(): string {
		return bin2hex( random_bytes( 32 ) );
	}
}
