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
	public const VERSION = 2;

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
	 * The site integrations table's full name.
	 *
	 * @return string
	 */
	public static function integrations_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_site_integrations';
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

		$clients      = self::clients_table();
		$sites        = self::sites_table();
		$integrations = self::integrations_table();

		return array(
			$clients      => "CREATE TABLE {$clients} (
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
			$sites        => "CREATE TABLE {$sites} (
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
			/*
			 * The signing key is deliberately absent. ARCH-6 keeps it in
			 * Sites\Registry, issued once and never read back; this table is
			 * what the studio knows *about* the connection, not the credential
			 * itself.
			 *
			 * `registry_site_id` is that awkward name for a reason. WordPress
			 * core declares a global column-name-to-format map in
			 * wp-includes/load.php, and `site_id` is in it as '%d' — so a
			 * column called site_id has every $wpdb->insert() and ->update()
			 * cast to an integer whatever its declared type, storing 0 and
			 * reporting success. Do not rename it back.
			 */
			$integrations => "CREATE TABLE {$integrations} (
	id varchar(32) NOT NULL,
	client_site_id varchar(32) NOT NULL,
	client_id varchar(32) NOT NULL,
	registry_site_id varchar(32) NOT NULL DEFAULT '',
	key_state varchar(20) NOT NULL DEFAULT 'unissued',
	key_issued_at bigint(20) unsigned NOT NULL DEFAULT 0,
	key_rotated_at bigint(20) unsigned NOT NULL DEFAULT 0,
	key_revoked_at bigint(20) unsigned NOT NULL DEFAULT 0,
	last_seen_at bigint(20) unsigned NOT NULL DEFAULT 0,
	last_report_at bigint(20) unsigned NOT NULL DEFAULT 0,
	last_error_code varchar(64) NOT NULL DEFAULT '',
	last_error_at bigint(20) unsigned NOT NULL DEFAULT 0,
	mail_capable varchar(20) NOT NULL DEFAULT 'unknown',
	mail_checked_at bigint(20) unsigned NOT NULL DEFAULT 0,
	mail_detail varchar(191) NOT NULL DEFAULT '',
	home_url varchar(255) NOT NULL DEFAULT '',
	wp_version varchar(32) NOT NULL DEFAULT '',
	php_version varchar(32) NOT NULL DEFAULT '',
	plugin_version varchar(32) NOT NULL DEFAULT '',
	created_at bigint(20) unsigned NOT NULL DEFAULT 0,
	updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	record_version int(11) unsigned NOT NULL DEFAULT 1,
	PRIMARY KEY  (id),
	UNIQUE KEY client_site_id (client_site_id),
	KEY client_id (client_id),
	KEY registry_site_id (registry_site_id)
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

		// A failed CREATE must not be remembered as a success: that is what
		// leaves a site marked current and never retrying.
		if ( self::tables_exist() ) {
			update_option( self::OPTION, self::VERSION );
		}
	}

	/**
	 * Whether every table this plugin owns is actually present.
	 *
	 * @return bool
	 */
	private static function tables_exist(): bool {
		global $wpdb;

		foreach ( array( self::clients_table(), self::sites_table(), self::integrations_table() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for "does this table exist".
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			if ( $table !== $found ) {
				return false;
			}
		}

		return true;
	}
}
