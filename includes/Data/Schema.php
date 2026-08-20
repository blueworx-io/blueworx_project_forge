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
	public const VERSION = 6;

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
	 * The users table's full name.
	 *
	 * @return string
	 */
	public static function users_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_users';
	}

	/**
	 * The memberships table's full name.
	 *
	 * @return string
	 */
	public static function memberships_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_memberships';
	}

	/**
	 * The work items table's full name.
	 *
	 * @return string
	 */
	public static function work_items_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_work_items';
	}

	/**
	 * The work events table's full name.
	 *
	 * @return string
	 */
	public static function work_events_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_work_events';
	}

	/**
	 * The gate records table's full name.
	 *
	 * @return string
	 */
	public static function gate_records_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_gate_records';
	}

	/**
	 * The comments table's full name.
	 *
	 * @return string
	 */
	public static function comments_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_comments';
	}

	/**
	 * Every table this plugin owns, as dbDelta-shaped CREATE statements.
	 *
	 * Its formatting is fussy in ways that are silent when broken: dbDelta wants
	 * two spaces after PRIMARY KEY, one field per line, no backticks around field
	 * names. Changing the whitespace here changes whether an upgrade happens at
	 * all.
	 *
	 * **A column added to an existing table needs a default, or it has to be
	 * nullable.** dbDelta adds it with ALTER TABLE, and a NOT NULL column with
	 * no default cannot be added to a table that already has rows — the ALTER
	 * fails, everything else in the upgrade succeeds, and the site is recorded
	 * as current with one column missing. That is why `detail` below is NULL
	 * rather than NOT NULL: a `text` column cannot carry a default on MySQL 5.7,
	 * so nullable is the only way to add one later. Found by adding it and
	 * watching every write to that table fail.
	 *
	 * @return array<string, string> Table name to statement.
	 */
	public static function definitions(): array {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();

		$clients      = self::clients_table();
		$sites        = self::sites_table();
		$integrations = self::integrations_table();
		$users        = self::users_table();
		$memberships  = self::memberships_table();
		$work_items   = self::work_items_table();
		$work_events  = self::work_events_table();
		$gate_records = self::gate_records_table();
		$comments     = self::comments_table();

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

			/*
			 * One person, one row, whatever number of clients they work with
			 * (AUTH-6). The unique index on the address is what makes that true
			 * rather than intended — without it, the second invitation to
			 * somebody who already has an account quietly creates a second
			 * person, and capacity counts them twice for ever after.
			 */
			$users        => "CREATE TABLE {$users} (
	id varchar(32) NOT NULL,
	email varchar(191) NOT NULL,
	display_name varchar(191) NOT NULL,
	status varchar(20) NOT NULL DEFAULT 'active',
	wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at bigint(20) unsigned NOT NULL DEFAULT 0,
	updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	record_version int(11) unsigned NOT NULL DEFAULT 1,
	PRIMARY KEY  (id),
	UNIQUE KEY email (email),
	KEY wp_user_id (wp_user_id),
	KEY status (status)
) {$collate};",

			/*
			 * The join, and the only place an access role lives. An empty
			 * client_site_id means every site under the client; a named one
			 * means that site alone. The unique index across the three is what
			 * stops one person holding two roles in one place, which #91 would
			 * then have to choose between.
			 */
			$memberships  => "CREATE TABLE {$memberships} (
	id varchar(32) NOT NULL,
	user_id varchar(32) NOT NULL,
	client_id varchar(32) NOT NULL,
	client_site_id varchar(32) NOT NULL DEFAULT '',
	role varchar(32) NOT NULL,
	grants varchar(191) NOT NULL DEFAULT '',
	status varchar(20) NOT NULL DEFAULT 'active',
	created_at bigint(20) unsigned NOT NULL DEFAULT 0,
	updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	record_version int(11) unsigned NOT NULL DEFAULT 1,
	PRIMARY KEY  (id),
	UNIQUE KEY user_client_site (user_id,client_id,client_site_id),
	KEY client_id (client_id),
	KEY client_site_id (client_site_id),
	KEY status (status)
) {$collate};",

			/*
			 * One table for all four rungs of WORK-1, because the hierarchy is a
			 * parent reference and the rung is a column — which is what lets a
			 * level be skipped and lets a Bug hang anywhere or nowhere. Five
			 * tables would make "skip a level" a schema change.
			 *
			 * `stage` is written only by the transition service. Nothing else
			 * has any business setting it, and Work\Validate refuses an edit
			 * that names it.
			 */
			$work_items   => "CREATE TABLE {$work_items} (
	id varchar(32) NOT NULL,
	client_site_id varchar(32) NOT NULL,
	client_id varchar(32) NOT NULL,
	parent_id varchar(32) NOT NULL DEFAULT '',
	level varchar(20) NOT NULL,
	work_type varchar(20) NOT NULL,
	title varchar(191) NOT NULL,
	problem text NOT NULL,
	scope text NOT NULL,
	non_goals text NOT NULL,
	requirements text NOT NULL,
	acceptance_criteria text NOT NULL,
	references_text text NOT NULL,
	stage varchar(32) NOT NULL DEFAULT 'future-idea',
	prior_stage varchar(32) NOT NULL DEFAULT '',
	blocked_at bigint(20) unsigned NOT NULL DEFAULT 0,
	blocked_elapsed bigint(20) unsigned NOT NULL DEFAULT 0,
	terminal_outcome varchar(20) NOT NULL DEFAULT '',
	duplicate_of varchar(32) NOT NULL DEFAULT '',
	archived tinyint(1) NOT NULL DEFAULT 0,
	review_attempt int(11) unsigned NOT NULL DEFAULT 1,
	primary_user_id varchar(32) NOT NULL DEFAULT '',
	reviewer_id varchar(32) NOT NULL DEFAULT '',
	deliverer_id varchar(32) NOT NULL DEFAULT '',
	reviewer_substitute_id varchar(32) NOT NULL DEFAULT '',
	deliverer_substitute_id varchar(32) NOT NULL DEFAULT '',
	cycle int(11) unsigned NOT NULL DEFAULT 1,
	self_reviewed tinyint(1) NOT NULL DEFAULT 0,
	override_used tinyint(1) NOT NULL DEFAULT 0,
	override_reason varchar(191) NOT NULL DEFAULT '',
	commercial_class varchar(20) NOT NULL DEFAULT 'unclassified',
	delivered_by_forge tinyint(1) NOT NULL DEFAULT 0,
	priority varchar(20) NOT NULL DEFAULT '',
	planned_start varchar(10) NOT NULL DEFAULT '',
	planned_due varchar(10) NOT NULL DEFAULT '',
	review_target varchar(10) NOT NULL DEFAULT '',
	release_target varchar(10) NOT NULL DEFAULT '',
	remaining_estimate decimal(8,2) NOT NULL DEFAULT 0,
	release_method varchar(20) NOT NULL DEFAULT '',
	release_destination varchar(191) NOT NULL DEFAULT '',
	created_at bigint(20) unsigned NOT NULL DEFAULT 0,
	updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	record_version int(11) unsigned NOT NULL DEFAULT 1,
	PRIMARY KEY  (id),
	KEY client_site_id (client_site_id),
	KEY client_id (client_id),
	KEY parent_id (parent_id),
	KEY stage (stage),
	KEY level (level),
	KEY archived (archived),
	KEY terminal_outcome (terminal_outcome)
) {$collate};",

			/*
			 * Append-only. #99 expands this into the full changelog; it exists
			 * now because #106 promises a move is recorded atomically, and that
			 * is not true without somewhere for the record to go.
			 */
			$work_events  => "CREATE TABLE {$work_events} (
	id varchar(32) NOT NULL,
	item_id varchar(32) NOT NULL,
	client_site_id varchar(32) NOT NULL,
	action varchar(40) NOT NULL,
	from_stage varchar(32) NOT NULL DEFAULT '',
	to_stage varchar(32) NOT NULL DEFAULT '',
	gate varchar(40) NOT NULL DEFAULT '',
	outcome varchar(20) NOT NULL DEFAULT '',
	via varchar(20) NOT NULL DEFAULT '',
	reason varchar(191) NOT NULL DEFAULT '',
	detail text NULL,
	cycle int(11) unsigned NOT NULL DEFAULT 1,
	attempt int(11) unsigned NOT NULL DEFAULT 1,
	actor bigint(20) unsigned NOT NULL DEFAULT 0,
	occurred_at bigint(20) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	KEY item_id (item_id),
	KEY client_site_id (client_site_id),
	KEY occurred_at (occurred_at)
) {$collate};",

			/*
			 * One row per gate requirement somebody has satisfied (#105). The
			 * actor and the completion time have no default, for the reason
			 * Work\GateRecords refuses to write without them: a completion with
			 * nobody's name on it proves nothing afterwards, which is the only
			 * time anybody reads one.
			 *
			 * Scoped by cycle and attempt rather than replaced. A failed review
			 * starts a new attempt and the earlier one stays exactly where it
			 * is — #108 requires it preserved, and deleting the old records to
			 * "reset" the gate is the obvious implementation and the wrong one.
			 */
			$gate_records => "CREATE TABLE {$gate_records} (
	id varchar(32) NOT NULL,
	item_id varchar(32) NOT NULL,
	client_site_id varchar(32) NOT NULL,
	gate varchar(40) NOT NULL,
	requirement varchar(60) NOT NULL,
	value text NOT NULL,
	evidence text NOT NULL,
	cycle int(11) unsigned NOT NULL DEFAULT 1,
	attempt int(11) unsigned NOT NULL DEFAULT 1,
	actor bigint(20) unsigned NOT NULL,
	completed_at bigint(20) unsigned NOT NULL,
	PRIMARY KEY  (id),
	KEY item_id (item_id),
	KEY client_site_id (client_site_id),
	KEY requirement (requirement)
) {$collate};",

			/*
			 * Discussion and evidence on a work item (#100).
			 *
			 * `visibility` is this table's entire security surface. An internal
			 * note and a client-visible comment are the same shape and differ by
			 * one column, so every read filters on it and no caller is handed a
			 * query it could widen.
			 */
			$comments     => "CREATE TABLE {$comments} (
	id varchar(32) NOT NULL,
	item_id varchar(32) NOT NULL,
	client_site_id varchar(32) NOT NULL,
	client_id varchar(32) NOT NULL,
	kind varchar(20) NOT NULL DEFAULT 'comment',
	visibility varchar(20) NOT NULL DEFAULT 'internal',
	body text NOT NULL,
	url varchar(255) NOT NULL DEFAULT '',
	author bigint(20) unsigned NOT NULL,
	author_name varchar(191) NOT NULL DEFAULT '',
	created_at bigint(20) unsigned NOT NULL,
	PRIMARY KEY  (id),
	KEY item_id (item_id),
	KEY client_site_id (client_site_id),
	KEY visibility (visibility)
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

		foreach ( self::definitions() as $table => $ignored ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for "does this table exist".
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			if ( $table !== $found ) {
				return false;
			}
		}

		return true;
	}
}
