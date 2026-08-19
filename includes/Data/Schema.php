<?php
/**
 * The plugin's own tables.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Data;

/**
 * Forge holds canonical records (ARCH-2), so it owns tables rather than option
 * blobs: scoping, the hour ledger and every later report need columns, indexes
 * and a WHERE clause.
 *
 * The version below is the schema's, not the plugin's. It is bumped whenever a
 * definition here changes, and that bump is what makes an existing site rebuild
 * its tables — activation alone would not, because a plugin updated in place
 * never runs it.
 */
final class Schema {

	/**
	 * The schema's own version. Bump on any change to definitions().
	 */
	public const VERSION = 1;

	/**
	 * Option holding the version a site has actually built.
	 */
	public const OPTION = 'bwx_forge_schema_version';

	/**
	 * The clients table's full name.
	 *
	 * @return string
	 */
	public static function clients_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_clients';
	}

	/**
	 * The client sites table's full name.
	 *
	 * @return string
	 */
	public static function sites_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_client_sites';
	}

	/**
	 * Every table this plugin owns, as dbDelta-shaped CREATE statements.
	 *
	 * Its formatting is fussy in ways that are silent when broken: dbDelta wants
	 * two spaces after PRIMARY KEY, one field per line, no backticks around field
	 * names. Changing the whitespace here changes whether an upgrade happens at
	 * all.
	 *
	 * @return array<string, string> Table name to statement.
	 */
	public static function definitions(): array {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();

		$clients = self::clients_table();
		$sites   = self::sites_table();

		return array(
			$clients => "CREATE TABLE {$clients} (
	id varchar(32) NOT NULL,
	display_name varchar(191) NOT NULL,
	legal_name varchar(191) NOT NULL DEFAULT '',
	status varchar(20) NOT NULL DEFAULT 'active',
	timezone varchar(64) NOT NULL DEFAULT 'UTC',
	email_domains text NOT NULL,
	created_at bigint(20) unsigned NOT NULL DEFAULT 0,
	updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	record_version int(11) unsigned NOT NULL DEFAULT 1,
	PRIMARY KEY  (id),
	KEY status (status)
) {$collate};",
			$sites   => "CREATE TABLE {$sites} (
	id varchar(32) NOT NULL,
	client_id varchar(32) NOT NULL,
	name varchar(191) NOT NULL,
	url varchar(255) NOT NULL DEFAULT '',
	status varchar(20) NOT NULL DEFAULT 'active',
	created_at bigint(20) unsigned NOT NULL DEFAULT 0,
	updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	record_version int(11) unsigned NOT NULL DEFAULT 1,
	PRIMARY KEY  (id),
	KEY client_id (client_id),
	KEY status (status)
) {$collate};",
		);
	}

	/**
	 * Whether a site's built schema is behind the code's.
	 *
	 * @param int|null $installed The version a site has built, or null if none.
	 * @return bool
	 */
	public static function needs_upgrade( ?int $installed ): bool {
		return null === $installed || $installed < self::VERSION;
	}

	/**
	 * Builds or updates the tables when the site is behind.
	 *
	 * Safe to call on every request: the ordinary case is one option read.
	 */
	public static function maybe_upgrade(): void {
		$installed = get_option( self::OPTION, null );
		$installed = null === $installed ? null : (int) $installed;

		if ( ! self::needs_upgrade( $installed ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::definitions() as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::OPTION, self::VERSION );
	}
}
