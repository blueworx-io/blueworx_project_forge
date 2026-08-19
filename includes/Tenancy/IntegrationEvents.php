<?php
/**
 * Turning authentication outcomes into connection health.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

/**
 * The studio learns whether a site is healthy from the requests that site
 * makes, not from asking it. This subscribes to the two things the signed
 * request path already announces and writes them onto the Integration record.
 *
 * It exists as its own class so `Rest\Signature` stays a pure check with no
 * database in it, and so the health record cannot silently stop being updated
 * because a new endpoint forgot to call something.
 */
final class IntegrationEvents {

	/**
	 * Subscribes. Called from Plugin::boot().
	 */
	public static function boot(): void {
		add_action( 'bwx_forge_site_verified', array( self::class, 'seen' ) );
		add_action( 'bwx_forge_security_event', array( self::class, 'refused' ) );
	}

	/**
	 * A signed request was accepted.
	 *
	 * @param string $site_id Registry site id.
	 */
	public static function seen( string $site_id ): void {
		Integrations::note_seen( $site_id );
	}

	/**
	 * A request claiming to be a site was refused.
	 *
	 * The entry is the security log's, which records refusals for site ids that
	 * were never registered as well as real ones. Integrations::note_error()
	 * writes nothing for those: there is no record to write to, and creating one
	 * would let an unauthenticated caller make rows by guessing ids.
	 *
	 * @param array<string, mixed> $entry Security log entry.
	 */
	public static function refused( array $entry ): void {
		$site_id = (string) ( $entry['site_id'] ?? '' );
		$reason  = (string) ( $entry['reason'] ?? 'refused' );

		if ( '' === $site_id ) {
			return;
		}

		Integrations::note_error( $site_id, $reason );
	}
}
