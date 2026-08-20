<?php
/**
 * What the studio knows about a client site's connection.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

use Blueworx\Forge\Data\Schema;

/**
 * The Client Site Integration record (#89): which WordPress belongs to which
 * client site, what state its key is in, when it was last heard from, and
 * whether it can send mail.
 *
 * It is the join M1 and #88 left open. `Sites\Registry` knew about keys and not
 * about clients; `Tenancy\ClientSites` knew about clients and not about keys.
 *
 * The key itself is not here (ARCH-6): the registry remains the one place a key
 * is stored and checked, and this record mirrors its *state* so the studio can
 * answer "is that site connected" without reading a credential to find out.
 *
 * No version is quoted on the stamping methods below, deliberately. They record
 * something that happened — a request arrived, a site reported itself — not
 * somebody's edit, and a fact about the past must not be refusable because the
 * row moved while it was being written. `record_version` still increments, so a
 * later human edit of this record can still be refused for being stale.
 */
final class Integrations {

	/**
	 * Id prefix for an integration record.
	 */
	public const PREFIX = 'int';

	/**
	 * No key has been issued for this site.
	 */
	public const KEY_UNISSUED = 'unissued';

	/**
	 * A key exists and may sign.
	 */
	public const KEY_ACTIVE = 'active';

	/**
	 * The key has been cut off.
	 */
	public const KEY_REVOKED = 'revoked';

	/**
	 * The site has not said whether it can send mail.
	 */
	public const MAIL_UNKNOWN = 'unknown';

	/**
	 * It can.
	 */
	public const MAIL_YES = 'yes';

	/**
	 * It cannot.
	 */
	public const MAIL_NO = 'no';

	/**
	 * The integration for a client site, creating it if this is the first time
	 * anybody asked.
	 *
	 * Every site has an integration conceptually — most of them in the state
	 * "nobody has connected this yet" — so a caller should never have to decide
	 * whether to create one.
	 *
	 * @param string $client_site_id Client site id.
	 * @param string $client_id      Owning client id.
	 * @param int    $author         WordPress user id of whoever caused it.
	 * @return array<string, mixed>|null Null when the row could not be written.
	 */
	public static function ensure( string $client_site_id, string $client_id, int $author = 0 ): ?array {
		global $wpdb;

		$existing = self::for_site( $client_site_id );

		if ( null !== $existing ) {
			return $existing;
		}

		$now = bwx_forge_now();

		$row = array(
			'id'               => Ids::create( self::PREFIX ),
			'client_site_id'   => $client_site_id,
			'client_id'        => $client_id,
			'registry_site_id' => '',
			'key_state'        => self::KEY_UNISSUED,
			'mail_capable'     => self::MAIL_UNKNOWN,
			'created_at'       => $now,
			'updated_at'       => $now,
			'created_by'       => $author,
			'record_version'   => 1,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		$wpdb->insert( Schema::integrations_table(), $row );

		/*
		 * Read back rather than trusting the insert. A refused insert is most
		 * likely the unique index doing its job because a second request asked
		 * at the same moment, and that is a success from here: there is exactly
		 * one integration, which is the point of the index. A genuinely failed
		 * write reads back as null and is reported as one.
		 */
		return self::for_site( $client_site_id );
	}

	/**
	 * The integration for a client site, or null when there is not one yet.
	 *
	 * @param string $client_site_id Client site id.
	 * @return array<string, mixed>|null
	 */
	public static function for_site( string $client_site_id ): ?array {
		global $wpdb;

		$table = Schema::integrations_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_site_id = %s", $client_site_id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * The integration a registered site id belongs to.
	 *
	 * This is the lookup the signed-request path uses: the only identity a
	 * signed request proves is the registry's site id.
	 *
	 * @param string $site_id Registry site id.
	 * @return array<string, mixed>|null
	 */
	public static function by_site_id( string $site_id ): ?array {
		global $wpdb;

		if ( '' === $site_id ) {
			return null;
		}

		$table = Schema::integrations_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE registry_site_id = %s", $site_id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Every integration under a client, keyed by client site id.
	 *
	 * Keyed rather than listed so a screen showing a client's sites can look
	 * each one up without a query per site.
	 *
	 * @param string $client_id Client id.
	 * @return array<string, array<string, mixed>>
	 */
	public static function for_client( string $client_id ): array {
		global $wpdb;

		$table = Schema::integrations_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_id = %s", $client_id ), ARRAY_A );

		$found = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$record                             = self::hydrate( $row );
			$found[ $record['client_site_id'] ] = $record;
		}

		return $found;
	}

	/**
	 * Records that a key was issued for this site, and which registry site id it
	 * was issued against.
	 *
	 * @param string $client_site_id Client site id.
	 * @param string $site_id        Registry site id.
	 * @return array<string, mixed>|null
	 */
	public static function note_key_issued( string $client_site_id, string $site_id ): ?array {
		return self::stamp(
			$client_site_id,
			array(
				'registry_site_id' => $site_id,
				'key_state'        => self::KEY_ACTIVE,
				'key_issued_at'    => bwx_forge_now(),
				'key_revoked_at'   => 0,
				// A newly issued key has no history of failure. Leaving the old
				// error in place would show a freshly connected site as broken
				// because of something its predecessor did.
				'last_error_code'  => '',
				'last_error_at'    => 0,
				'last_seen_at'     => 0,
			)
		);
	}

	/**
	 * Records a rotation. The site id does not change: it is the same site with
	 * a new key.
	 *
	 * @param string $client_site_id Client site id.
	 * @return array<string, mixed>|null
	 */
	public static function note_key_rotated( string $client_site_id ): ?array {
		return self::stamp(
			$client_site_id,
			array(
				'key_state'       => self::KEY_ACTIVE,
				'key_rotated_at'  => bwx_forge_now(),
				// Same reasoning as issuing: the failure belonged to the old key.
				'last_error_code' => '',
				'last_error_at'   => 0,
			)
		);
	}

	/**
	 * Records a revocation.
	 *
	 * @param string $client_site_id Client site id.
	 * @return array<string, mixed>|null
	 */
	public static function note_key_revoked( string $client_site_id ): ?array {
		return self::stamp(
			$client_site_id,
			array(
				'key_state'      => self::KEY_REVOKED,
				'key_revoked_at' => bwx_forge_now(),
			)
		);
	}

	/**
	 * Records that a signed request from this site was accepted.
	 *
	 * @param string $site_id Registry site id.
	 * @return bool Whether there was an integration to stamp.
	 */
	public static function note_seen( string $site_id ): bool {
		$record = self::by_site_id( $site_id );

		if ( null === $record ) {
			return false;
		}

		return null !== self::stamp( $record['client_site_id'], array( 'last_seen_at' => bwx_forge_now() ) );
	}

	/**
	 * Records that a request claiming to be this site was refused.
	 *
	 * A site id nobody has registered stamps nothing: there is no record to
	 * write to, and creating one would let an unauthenticated caller make rows
	 * by guessing ids.
	 *
	 * @param string $site_id Registry site id.
	 * @param string $reason  Machine-readable reason.
	 * @return bool Whether there was an integration to stamp.
	 */
	public static function note_error( string $site_id, string $reason ): bool {
		$record = self::by_site_id( $site_id );

		if ( null === $record ) {
			return false;
		}

		$stamped = self::stamp(
			$record['client_site_id'],
			array(
				'last_error_code' => substr( $reason, 0, 64 ),
				'last_error_at'   => bwx_forge_now(),
			)
		);

		return null !== $stamped;
	}

	/**
	 * Records what a site said about itself.
	 *
	 * @param string               $site_id Registry site id.
	 * @param array<string, mixed> $report  Cleaned report values.
	 * @return array<string, mixed>|null
	 */
	public static function note_report( string $site_id, array $report ): ?array {
		$record = self::by_site_id( $site_id );

		if ( null === $record ) {
			return null;
		}

		$changes = array( 'last_report_at' => bwx_forge_now() );

		foreach ( array( 'home_url', 'wp_version', 'php_version', 'plugin_version', 'mail_detail' ) as $field ) {
			if ( array_key_exists( $field, $report ) ) {
				$changes[ $field ] = (string) $report[ $field ];
			}
		}

		if ( array_key_exists( 'mail_capable', $report ) ) {
			$changes['mail_capable']    = (string) $report['mail_capable'];
			$changes['mail_checked_at'] = bwx_forge_now();
		}

		return self::stamp( $record['client_site_id'], $changes );
	}

	/**
	 * Writes a change and moves the record version on.
	 *
	 * @param string               $client_site_id Client site id.
	 * @param array<string, mixed> $changes        Columns to set.
	 * @return array<string, mixed>|null Null when nothing was written.
	 */
	private static function stamp( string $client_site_id, array $changes ): ?array {
		global $wpdb;

		$changes['updated_at'] = bwx_forge_now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$changed = $wpdb->update(
			Schema::integrations_table(),
			$changes,
			array( 'client_site_id' => $client_site_id )
		);

		if ( false === $changed ) {
			return null;
		}

		$table = Schema::integrations_table();

		/*
		 * The version moves in its own statement rather than in the array above,
		 * so it is an increment of whatever is stored rather than of whatever
		 * this process last read. Two stamps arriving together both count.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
				"UPDATE {$table} SET record_version = record_version + 1 WHERE client_site_id = %s",
				$client_site_id
			)
		);

		return self::for_site( $client_site_id );
	}

	/**
	 * Turns a database row into the record the rest of the plugin uses, with the
	 * derived health state on it — nothing should ever have to work that out for
	 * itself.
	 *
	 * @param array<string, mixed> $row Row as stored.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$record = array(
			'id'               => (string) $row['id'],
			'client_site_id'   => (string) $row['client_site_id'],
			'client_id'        => (string) $row['client_id'],
			'registry_site_id' => (string) $row['registry_site_id'],
			'key_state'        => (string) $row['key_state'],
			'key_issued_at'    => (int) $row['key_issued_at'],
			'key_rotated_at'   => (int) $row['key_rotated_at'],
			'key_revoked_at'   => (int) $row['key_revoked_at'],
			'last_seen_at'     => (int) $row['last_seen_at'],
			'last_report_at'   => (int) $row['last_report_at'],
			'last_error_code'  => (string) $row['last_error_code'],
			'last_error_at'    => (int) $row['last_error_at'],
			'mail_capable'     => (string) $row['mail_capable'],
			'mail_checked_at'  => (int) $row['mail_checked_at'],
			'mail_detail'      => (string) $row['mail_detail'],
			'home_url'         => (string) $row['home_url'],
			'wp_version'       => (string) $row['wp_version'],
			'php_version'      => (string) $row['php_version'],
			'plugin_version'   => (string) $row['plugin_version'],
			'created_at'       => (int) $row['created_at'],
			'updated_at'       => (int) $row['updated_at'],
			'created_by'       => (int) $row['created_by'],
			'record_version'   => (int) $row['record_version'],
		);

		$record['health']       = Health::state( $record );
		$record['health_label'] = Health::label( $record['health'] );

		return $record;
	}
}
