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
	public const VERSION = 15;

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
	 * The client contacts table's full name.
	 *
	 * @return string
	 */
	public static function contacts_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_client_contacts';
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
	 * The work dependencies table's full name.
	 *
	 * @return string
	 */
	public static function dependencies_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_work_dependencies';
	}

	/**
	 * The request submissions table's full name.
	 *
	 * @return string
	 */
	public static function submissions_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_submissions';
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
	 * The availability patterns table's full name.
	 *
	 * @return string
	 */
	public static function availability_patterns_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_availability_patterns';
	}

	/**
	 * The unavailability table's full name.
	 *
	 * @return string
	 */
	public static function unavailability_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_unavailability';
	}

	/**
	 * The onboarding template versions table's full name.
	 *
	 * Global, and the only table here that is. A template is the studio's, not
	 * a client's: every site is assigned one of these and none of them owns it.
	 * So there is deliberately no client or site column, and anything reading
	 * this table is crossing the tenant boundary knowingly rather than by
	 * accident.
	 *
	 * @return string
	 */
	public static function onboarding_templates_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_onboarding_templates';
	}

	/**
	 * The onboarding template steps table's full name.
	 *
	 * @return string
	 */
	public static function onboarding_template_steps_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_onboarding_template_steps';
	}

	/**
	 * The site onboarding table's full name.
	 *
	 * @return string
	 */
	public static function site_onboarding_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_site_onboarding';
	}

	/**
	 * The onboarding steps table's full name.
	 *
	 * @return string
	 */
	public static function onboarding_steps_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_onboarding_steps';
	}

	/**
	 * The onboarding step events table's full name.
	 *
	 * @return string
	 */
	public static function onboarding_step_events_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_onboarding_step_events';
	}

	/**
	 * The onboarding evidence table's full name.
	 *
	 * @return string
	 */
	public static function onboarding_evidence_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_onboarding_evidence';
	}

	/**
	 * The notification events table's full name.
	 *
	 * @return string
	 */
	public static function notification_events_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bwx_forge_notification_events';
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
		$contacts     = self::contacts_table();
		$dependencies = self::dependencies_table();
		$submissions  = self::submissions_table();
		$patterns     = self::availability_patterns_table();
		$unavailable  = self::unavailability_table();

		$templates       = self::onboarding_templates_table();
		$template_steps  = self::onboarding_template_steps_table();
		$site_onboarding = self::site_onboarding_table();
		$steps           = self::onboarding_steps_table();
		$step_events     = self::onboarding_step_events_table();
		$evidence        = self::onboarding_evidence_table();

		$notifications = self::notification_events_table();

		return array(
			$clients         => "CREATE TABLE {$clients} (
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
			$sites           => "CREATE TABLE {$sites} (
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
			$integrations    => "CREATE TABLE {$integrations} (
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
			$users           => "CREATE TABLE {$users} (
	id varchar(32) NOT NULL,
	email varchar(191) NOT NULL,
	display_name varchar(191) NOT NULL,
	status varchar(20) NOT NULL DEFAULT 'active',
	grants varchar(191) NOT NULL DEFAULT '',
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
			 * #103. One row per "this work waits for that work".
			 *
			 * A row rather than a column on the item, because an item can wait
			 * on any number of things and a column would hold one. The site is
			 * carried so the tenant boundary applies without a join: a
			 * dependency across two sites would make an item reachable from two
			 * tenants at once, which is exactly what ARCH-3 exists to prevent.
			 *
			 * The unique index is the honest kind of duplicate prevention —
			 * saying the same thing twice is not two dependencies.
			 */
			$dependencies    => "CREATE TABLE {$dependencies} (
	id varchar(32) NOT NULL,
	item_id varchar(32) NOT NULL,
	depends_on_id varchar(32) NOT NULL,
	client_site_id varchar(32) NOT NULL,
	created_at bigint(20) unsigned NOT NULL DEFAULT 0,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	UNIQUE KEY item_depends_on (item_id,depends_on_id),
	KEY depends_on_id (depends_on_id),
	KEY client_site_id (client_site_id)
) {$collate};",

			/*
			 * #95. Who our current contact is for a client, as a row with a
			 * start and an end rather than a column on the client.
			 *
			 * A column would answer "who is it now" and nothing else. The
			 * requirement is that history is not lost when it changes, so each
			 * assignment is appended and the current contact is the latest row.
			 * Nothing here is ever updated, which is why there is no version and
			 * no updated_at: this is a record of what happened, not a record of
			 * how things are.
			 *
			 * An empty user_id is a real answer — the contact left and nobody
			 * has been named yet. That state has to be storable, or a client
			 * with no contact is indistinguishable from a client whose contact
			 * was never set, and #95 asks for one of those to be flagged.
			 */
			$contacts        => "CREATE TABLE {$contacts} (
	id varchar(32) NOT NULL,
	client_id varchar(32) NOT NULL,
	user_id varchar(32) NOT NULL DEFAULT '',
	started_at bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at bigint(20) unsigned NOT NULL DEFAULT 0,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	KEY client_started (client_id,started_at),
	KEY user_id (user_id)
) {$collate};",

			/*
			 * The join, and the only place an access role lives. An empty
			 * client_site_id means every site under the client; a named one
			 * means that site alone. The unique index across the three is what
			 * stops one person holding two roles in one place, which #91 would
			 * then have to choose between.
			 */
			$memberships     => "CREATE TABLE {$memberships} (
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
			$work_items      => "CREATE TABLE {$work_items} (
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
	capacity_override_used tinyint(1) NOT NULL DEFAULT 0,
	capacity_override_reason varchar(191) NOT NULL DEFAULT '',
	commercial_class varchar(20) NOT NULL DEFAULT 'unclassified',
	delivered_by_forge tinyint(1) NOT NULL DEFAULT 0,
	priority varchar(20) NOT NULL DEFAULT '',
	planned_start varchar(10) NOT NULL DEFAULT '',
	planned_due varchar(10) NOT NULL DEFAULT '',
	review_target varchar(10) NOT NULL DEFAULT '',
	release_target varchar(10) NOT NULL DEFAULT '',
	remaining_estimate decimal(8,2) NOT NULL DEFAULT 0,
	hours_primary decimal(8,2) NOT NULL DEFAULT 0,
	hours_review decimal(8,2) NOT NULL DEFAULT 0,
	hours_delivery decimal(8,2) NOT NULL DEFAULT 0,
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
			 * What a client asked for, kept as they asked it (REQ-1, #129).
			 *
			 * Separate from work items rather than an early stage of one,
			 * because a question has no commercial life. Work has packages,
			 * hours and gates; "could we have a booking form" has none of
			 * those, and a client with no support package may still ask.
			 *
			 * The submitted text is never updated. converted_item_id is the
			 * two-way link to whatever the request became, and is indexed
			 * because the client's own status view reads it the other way
			 * round — from the work back to what was asked.
			 */
			$submissions     => "CREATE TABLE {$submissions} (
	id varchar(32) NOT NULL,
	client_site_id varchar(32) NOT NULL,
	client_id varchar(32) NOT NULL,
	type varchar(20) NOT NULL DEFAULT 'request',
	title varchar(191) NOT NULL,
	description text NOT NULL,
	desired_outcome text NOT NULL,
	evidence text NOT NULL,
	submitted_by varchar(191) NOT NULL DEFAULT '',
	intake_state varchar(20) NOT NULL DEFAULT 'received',
	response text NOT NULL,
	converted_item_id varchar(32) NOT NULL DEFAULT '',
	created_at bigint(20) unsigned NOT NULL DEFAULT 0,
	updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	record_version int(11) unsigned NOT NULL DEFAULT 1,
	PRIMARY KEY  (id),
	KEY client_site_id (client_site_id),
	KEY client_id (client_id),
	KEY intake_state (intake_state),
	KEY converted_item_id (converted_item_id)
) {$collate};",

			/*
			 * Append-only. #99 expands this into the full changelog; it exists
			 * now because #106 promises a move is recorded atomically, and that
			 * is not true without somewhere for the record to go.
			 */
			$work_events     => "CREATE TABLE {$work_events} (
	id varchar(32) NOT NULL,
	item_id varchar(32) NOT NULL,
	client_site_id varchar(32) NOT NULL,
	action varchar(40) NOT NULL,
	from_stage varchar(32) NOT NULL DEFAULT '',
	to_stage varchar(32) NOT NULL DEFAULT '',
	gate varchar(40) NOT NULL DEFAULT '',
	outcome varchar(20) NOT NULL DEFAULT '',
	via varchar(20) NOT NULL DEFAULT '',
	field varchar(64) NOT NULL DEFAULT '',
	previous_value varchar(191) NOT NULL DEFAULT '',
	new_value varchar(191) NOT NULL DEFAULT '',
	source_interface varchar(20) NOT NULL DEFAULT '',
	timezone varchar(64) NOT NULL DEFAULT '',
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
			$gate_records    => "CREATE TABLE {$gate_records} (
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
			 *
			 * `author_site` is how a contribution from a client's own site is
			 * attributed (#133). It is not a second way of saying who wrote
			 * something — `author` is a WordPress user on *our* install, and a
			 * person typing on their own site has no account here. So the two
			 * are different facts and both are stored: exactly one of them is
			 * set on any row, and which one says which side of the connection
			 * the words came from. Folding the client's site id into `author`
			 * would have meant a number that means a user id on some rows and
			 * something else on others.
			 *
			 * `answers` links a client's reply to the question it answers, so
			 * "has anybody come back on this" is a query rather than a person
			 * reading a thread (AUTH-2's information requests).
			 */
			$comments        => "CREATE TABLE {$comments} (
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
	author_site varchar(32) NOT NULL DEFAULT '',
	answers varchar(32) NOT NULL DEFAULT '',
	created_at bigint(20) unsigned NOT NULL,
	PRIMARY KEY  (id),
	KEY item_id (item_id),
	KEY client_site_id (client_site_id),
	KEY visibility (visibility),
	KEY answers (answers)
) {$collate};",

			/*
			 * #136, CAP-1. What somebody's working week is, from a date.
			 *
			 * Effective-dated rather than a single editable row, because hours
			 * change and history must not change with them. Somebody who went
			 * to four days in March was full time in February, and a capacity
			 * figure for February that quietly recalculates itself the moment
			 * their hours are edited is worse than no figure — it disagrees
			 * with what was decided at the time and nothing says why.
			 *
			 * A row is a weekly pattern rather than seven rows, because a week
			 * is the unit people actually state their hours in, and the seven
			 * days are always set together.
			 *
			 * Append-only, and there is no unique index on the person and date.
			 * Correcting hours writes another row rather than editing the one
			 * that was wrong: a table whose entire purpose is that history does
			 * not change is the last place to erase what was previously
			 * believed. The latest row wins when two share an effective date,
			 * which is what makes a correction a correction.
			 */
			$patterns        => "CREATE TABLE {$patterns} (
	id varchar(32) NOT NULL,
	user_id varchar(32) NOT NULL,
	effective_from varchar(10) NOT NULL,
	hours_mon decimal(5,2) NOT NULL DEFAULT 0,
	hours_tue decimal(5,2) NOT NULL DEFAULT 0,
	hours_wed decimal(5,2) NOT NULL DEFAULT 0,
	hours_thu decimal(5,2) NOT NULL DEFAULT 0,
	hours_fri decimal(5,2) NOT NULL DEFAULT 0,
	hours_sat decimal(5,2) NOT NULL DEFAULT 0,
	hours_sun decimal(5,2) NOT NULL DEFAULT 0,
	note varchar(191) NOT NULL DEFAULT '',
	created_at bigint(20) unsigned NOT NULL DEFAULT 0,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	KEY user_effective (user_id, effective_from)
) {$collate};",

			/*
			 * #136, CAP-1. Dated time somebody is not available for.
			 *
			 * Leave, and anything else that takes a day out. Whole days: the
			 * decision says dated records, and a half day is expressible as an
			 * hours change if it ever needs to be. Both ends are inclusive,
			 * because "off from the 3rd to the 7th" is five days to everyone
			 * who says it.
			 *
			 * No unique index. Two records covering the same day is not a
			 * mistake to prevent — it is somebody on leave during a shutdown —
			 * and the calculation takes a day out once however many records
			 * cover it.
			 */
			$unavailable     => "CREATE TABLE {$unavailable} (
	id varchar(32) NOT NULL,
	user_id varchar(32) NOT NULL,
	starts_on varchar(10) NOT NULL,
	ends_on varchar(10) NOT NULL,
	kind varchar(20) NOT NULL DEFAULT 'leave',
	note varchar(191) NOT NULL DEFAULT '',
	created_at bigint(20) unsigned NOT NULL DEFAULT 0,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	KEY user_dates (user_id, starts_on, ends_on)
) {$collate};",

			/*
			 * #159, ONB-1. One version of the launch checklist.
			 *
			 * A version is draft or published, and **published is for ever**.
			 * That is the whole reason a site's assignment (#160) is worth
			 * anything: a client's checklist points at a version, and a version
			 * that could be edited underneath them would make the pointer a
			 * lie. Editing a published version opens a draft copy, which
			 * becomes the next version when it is published.
			 */
			$templates       => "CREATE TABLE {$templates} (
	id varchar(32) NOT NULL,
	version smallint(5) unsigned NOT NULL DEFAULT 0,
	name varchar(191) NOT NULL DEFAULT '',
	status varchar(20) NOT NULL DEFAULT 'draft',
	published_at bigint(20) unsigned NOT NULL DEFAULT 0,
	published_by bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at bigint(20) unsigned NOT NULL DEFAULT 0,
	updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	record_version bigint(20) unsigned NOT NULL DEFAULT 1,
	PRIMARY KEY  (id),
	KEY status_version (status, version)
) {$collate};",

			/*
			 * #159. One step, as the template defines it.
			 *
			 * `depends_on` names other template steps in the same version,
			 * comma separated. A join table would be tidier and would buy
			 * nothing: a version cannot change once published, so these
			 * references can never come to dangle, and nothing ever queries
			 * backwards from a dependency to the steps that named it.
			 */
			$template_steps  => "CREATE TABLE {$template_steps} (
	id varchar(32) NOT NULL,
	template_id varchar(32) NOT NULL,
	section varchar(20) NOT NULL DEFAULT 'foundations',
	category varchar(100) NOT NULL DEFAULT '',
	title varchar(191) NOT NULL DEFAULT '',
	description text NOT NULL,
	owner_side varchar(10) NOT NULL DEFAULT 'client',
	optional tinyint(1) NOT NULL DEFAULT 0,
	launch_critical tinyint(1) NOT NULL DEFAULT 0,
	allows_not_applicable tinyint(1) NOT NULL DEFAULT 0,
	depends_on text NOT NULL,
	position smallint(5) unsigned NOT NULL DEFAULT 0,
	created_at bigint(20) unsigned NOT NULL DEFAULT 0,
	updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	record_version bigint(20) unsigned NOT NULL DEFAULT 1,
	PRIMARY KEY  (id),
	KEY template_position (template_id, position)
) {$collate};",

			/*
			 * #160. Which version a site was given, and when.
			 *
			 * The version is recorded here as well as being copied into the
			 * steps, so "which checklist did this client actually get" is still
			 * answerable long after their own steps have diverged from it.
			 *
			 * One per site, enforced by the index rather than by whoever calls:
			 * a second assignment would silently give a client two checklists
			 * and no way to say which one counts.
			 */
			$site_onboarding => "CREATE TABLE {$site_onboarding} (
	id varchar(32) NOT NULL,
	client_site_id varchar(32) NOT NULL,
	client_id varchar(32) NOT NULL,
	template_id varchar(32) NOT NULL,
	template_version smallint(5) unsigned NOT NULL DEFAULT 0,
	assigned_at bigint(20) unsigned NOT NULL DEFAULT 0,
	assigned_by bigint(20) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	UNIQUE KEY one_per_site (client_site_id)
) {$collate};",

			/*
			 * #161, ONB-3. A live step on a client's checklist.
			 *
			 * **There is no credential column, and that absence is the
			 * enforcement.** ONB-3 says Forge stores who the provider is, which
			 * account, what access was asked for and whether it was verified —
			 * never the secret itself. A rule written in a controller can be
			 * forgotten by the next caller; a column that does not exist cannot
			 * be written to by anybody.
			 *
			 * No `overdue` column either. It is worked out from `due_on`
			 * against today by Onboarding\Statuses, because a stored one needs
			 * a nightly sweep and is wrong in between.
			 */
			$steps           => "CREATE TABLE {$steps} (
	id varchar(32) NOT NULL,
	site_onboarding_id varchar(32) NOT NULL,
	client_site_id varchar(32) NOT NULL,
	template_step_id varchar(32) NOT NULL,
	section varchar(20) NOT NULL DEFAULT 'foundations',
	title varchar(191) NOT NULL DEFAULT '',
	status varchar(20) NOT NULL DEFAULT 'not-started',
	owner_side varchar(10) NOT NULL DEFAULT 'client',
	owner_id varchar(32) NOT NULL DEFAULT '',
	reviewer_id varchar(32) NOT NULL DEFAULT '',
	launch_critical tinyint(1) NOT NULL DEFAULT 0,
	optional tinyint(1) NOT NULL DEFAULT 0,
	allows_not_applicable tinyint(1) NOT NULL DEFAULT 0,
	due_on varchar(10) NOT NULL DEFAULT '',
	response text NOT NULL,
	provider varchar(191) NOT NULL DEFAULT '',
	account_identifier varchar(191) NOT NULL DEFAULT '',
	account_owner varchar(191) NOT NULL DEFAULT '',
	access_role varchar(100) NOT NULL DEFAULT '',
	invitation_status varchar(20) NOT NULL DEFAULT '',
	verification_outcome varchar(20) NOT NULL DEFAULT '',
	position smallint(5) unsigned NOT NULL DEFAULT 0,
	created_at bigint(20) unsigned NOT NULL DEFAULT 0,
	updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	record_version bigint(20) unsigned NOT NULL DEFAULT 1,
	PRIMARY KEY  (id),
	KEY site_position (client_site_id, position),
	KEY site_status (client_site_id, status)
) {$collate};",

			/*
			 * #161. What happened to a step, and who did it.
			 *
			 * Its own table rather than the work changelog: an onboarding step
			 * is not a work item, has no cycle or review attempt, and putting
			 * it in the work events table would leave an `item_id` that
			 * sometimes means a work item and sometimes does not — which every
			 * reader of that table would then have to know about.
			 *
			 * Nothing here is ever edited or deleted. A correction is a further
			 * entry, as everywhere else in this product.
			 */
			$step_events     => "CREATE TABLE {$step_events} (
	id varchar(32) NOT NULL,
	step_id varchar(32) NOT NULL,
	client_site_id varchar(32) NOT NULL,
	action varchar(30) NOT NULL DEFAULT '',
	from_status varchar(20) NOT NULL DEFAULT '',
	to_status varchar(20) NOT NULL DEFAULT '',
	reason text NOT NULL,
	actor bigint(20) unsigned NOT NULL DEFAULT 0,
	actor_site varchar(32) NOT NULL DEFAULT '',
	source_interface varchar(20) NOT NULL DEFAULT '',
	occurred_at bigint(20) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	KEY step_time (step_id, occurred_at)
) {$collate};",

			/*
			 * #168. A file attached to an onboarding step.
			 *
			 * `client_site_id` is on the row rather than reached through the
			 * step, and every read names it. Tenant isolation is then a WHERE
			 * clause instead of a check somebody has to remember to write, and
			 * a caller holding only an id gets nothing back.
			 *
			 * `stored_name` is the only thing that touches the filesystem, and
			 * the uploader never chooses it. `original_name` is a label shown
			 * beside a download and is read as a path by nothing.
			 *
			 * Append-only. Replacing evidence writes another row and leaves the
			 * first, which is what makes the submission history worth reading.
			 * `retention_until` records when a documented manual deletion
			 * becomes permitted (NOTIF-5) — nothing acts on it automatically,
			 * because a purge running through records with audit history is the
			 * foot-gun that decision exists to refuse.
			 */
			$evidence        => "CREATE TABLE {$evidence} (
	id varchar(32) NOT NULL,
	step_id varchar(32) NOT NULL,
	client_site_id varchar(32) NOT NULL,
	client_id varchar(32) NOT NULL DEFAULT '',
	original_name varchar(191) NOT NULL DEFAULT '',
	stored_name varchar(191) NOT NULL DEFAULT '',
	mime_type varchar(100) NOT NULL DEFAULT '',
	size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
	checksum varchar(64) NOT NULL DEFAULT '',
	uploaded_by bigint(20) unsigned NOT NULL DEFAULT 0,
	uploaded_site varchar(32) NOT NULL DEFAULT '',
	source_interface varchar(20) NOT NULL DEFAULT '',
	uploaded_at bigint(20) unsigned NOT NULL DEFAULT 0,
	retention_until bigint(20) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	KEY step_site (step_id, client_site_id),
	KEY site_time (client_site_id, uploaded_at)
) {$collate};",

			$notifications   => "CREATE TABLE {$notifications} (
	id varchar(32) NOT NULL,
	event_kind varchar(40) NOT NULL,
	subject_type varchar(20) NOT NULL,
	subject_id varchar(32) NOT NULL,
	client_id varchar(32) NOT NULL DEFAULT '',
	client_site_id varchar(32) NOT NULL DEFAULT '',
	occurrence int(11) unsigned NOT NULL DEFAULT 1,
	outcome varchar(20) NOT NULL DEFAULT 'raised',
	raised_at bigint(20) unsigned NOT NULL DEFAULT 0,
	settled_at bigint(20) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	KEY subject (subject_type,subject_id),
	KEY outcome_kind (outcome,event_kind)
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
