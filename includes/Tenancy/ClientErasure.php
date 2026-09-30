<?php
/**
 * Deleting a client and everything attached to it (#458).
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Onboarding\EvidenceStore;
use Blueworx\Forge\Sites\Registry;
use Blueworx\Forge\Work\Items;

/**
 * NOTIF-5 keeps a client that has history: it is deactivated, never deleted.
 * #458 is the one exception, asked for by name — a test client skews every
 * number it touches, and deactivating it leaves its work in the reports.
 *
 * So the delete lives here, in one class with one caller (the administrator's
 * route), rather than as a method on the repositories. Clients, ClientSites,
 * Integrations and Memberships still cannot remove a row, and a test still
 * says so.
 *
 * Every row is found through the client: its own id, its sites, the work on
 * those sites, or the recurring sources on them. Nothing is matched by a name
 * or a date, so nothing belonging to another client can be caught up in it.
 * The client row goes last, so a delete that stops halfway leaves a client
 * that can be deleted again rather than orphans nothing lists.
 */
final class ClientErasure {

	/**
	 * A row tied to the client by the client's own id.
	 */
	public const BY_CLIENT = 'client';

	/**
	 * A row tied to one of the client's sites.
	 */
	public const BY_SITE = 'site';

	/**
	 * A row tied to a piece of the client's work.
	 */
	public const BY_ITEM = 'item';

	/**
	 * A row tied to one of the client's recurring tasks or reminders.
	 */
	public const BY_SOURCE = 'source';

	/**
	 * Largest IN list sent in one query.
	 */
	private const CHUNK = 500;

	/**
	 * What is removed, in the order it is removed: table, column, and what the
	 * column holds. Children before parents; the client itself last.
	 *
	 * @return array<int, array{0: string, 1: string, 2: string}>
	 */
	public static function plan(): array {
		return array(
			array( Schema::slack_events_table(), 'subject_id', self::BY_ITEM ),
			array( Schema::recurring_occurrences_table(), 'recurring_id', self::BY_SOURCE ),
			array( Schema::recurring_table(), 'client_site_id', self::BY_SITE ),
			array( Schema::dependencies_table(), 'client_site_id', self::BY_SITE ),
			array( Schema::work_events_table(), 'client_site_id', self::BY_SITE ),
			array( Schema::gate_records_table(), 'client_site_id', self::BY_SITE ),
			array( Schema::comments_table(), 'client_site_id', self::BY_SITE ),
			array( Schema::submissions_table(), 'client_site_id', self::BY_SITE ),
			array( Schema::work_items_table(), 'client_site_id', self::BY_SITE ),
			array( Schema::meeting_events_table(), 'client_site_id', self::BY_SITE ),
			array( Schema::meeting_occurrences_table(), 'client_site_id', self::BY_SITE ),
			array( Schema::meeting_series_table(), 'client_site_id', self::BY_SITE ),
			array( Schema::onboarding_step_events_table(), 'client_site_id', self::BY_SITE ),
			array( Schema::onboarding_evidence_table(), 'client_site_id', self::BY_SITE ),
			array( Schema::onboarding_steps_table(), 'client_site_id', self::BY_SITE ),
			array( Schema::site_onboarding_table(), 'client_site_id', self::BY_SITE ),
			array( Schema::hour_ledger_table(), 'client_site_id', self::BY_SITE ),
			array( Schema::site_packages_table(), 'client_site_id', self::BY_SITE ),
			array( Schema::notification_events_table(), 'client_site_id', self::BY_SITE ),
			array( Schema::notification_events_table(), 'client_id', self::BY_CLIENT ),
			array( Schema::integrations_table(), 'client_site_id', self::BY_SITE ),
			array( Schema::contacts_table(), 'client_id', self::BY_CLIENT ),
			array( Schema::memberships_table(), 'client_id', self::BY_CLIENT ),
			array( Schema::sites_table(), 'client_id', self::BY_CLIENT ),
			array( Schema::clients_table(), 'id', self::BY_CLIENT ),
		);
	}

	/**
	 * The tables that belong to nobody's client: people, their hours, the
	 * studio's templates, packages, connections and dates. A client's delete
	 * never touches them. Every table is in this list or in plan(), and a test
	 * holds that true when a table is added.
	 *
	 * @return array<int, string>
	 */
	public static function global_tables(): array {
		return array(
			Schema::users_table(),
			Schema::availability_patterns_table(),
			Schema::unavailability_table(),
			Schema::onboarding_templates_table(),
			Schema::onboarding_template_steps_table(),
			Schema::packages_table(),
			Schema::package_versions_table(),
			Schema::connections_table(),
			Schema::subscriptions_table(),
			Schema::slack_people_table(),
			Schema::calendar_dates_table(),
		);
	}

	/**
	 * Why a client cannot be deleted, or '' when it can. Pure.
	 *
	 * @param array<string, mixed> $client    The client.
	 * @param string               $studio_id The studio's own client id.
	 * @return string
	 */
	public static function refusal( array $client, string $studio_id ): string {
		if ( '' !== $studio_id && (string) ( $client['id'] ?? '' ) === $studio_id ) {
			return __( 'The studio\'s own client cannot be deleted.', 'blueworx-forge' );
		}

		return '';
	}

	/**
	 * How much goes with a client, by kind, for the confirmation.
	 *
	 * @param string $client_id Client id.
	 * @return array<string, int>
	 */
	public static function counts( string $client_id ): array {
		return self::tally( $client_id, self::find( $client_id ) );
	}

	/**
	 * The counts, over ids already found.
	 *
	 * @param string                            $client_id Client id.
	 * @param array<string, array<int, string>> $found     What find() answered.
	 * @return array<string, int>
	 */
	private static function tally( string $client_id, array $found ): array {
		$sites = $found['sites'];

		return array(
			'sites'       => count( $sites ),
			'tasks'       => count( $found['items'] ),
			'requests'    => self::count( Schema::submissions_table(), 'client_site_id', $sites ),
			'meetings'    => self::count( Schema::meeting_series_table(), 'client_site_id', $sites ),
			'recurring'   => self::count( Schema::recurring_table(), 'client_site_id', $sites, "kind <> 'reminder'" ),
			'reminders'   => self::count( Schema::recurring_table(), 'client_site_id', $sites, "kind = 'reminder'" ),
			'onboarding'  => self::count( Schema::onboarding_steps_table(), 'client_site_id', $sites ),
			'time'        => self::count( Schema::hour_ledger_table(), 'client_site_id', $sites ),
			'alerts'      => self::count( Schema::notification_events_table(), 'client_site_id', $sites )
				+ self::count( Schema::notification_events_table(), 'client_id', array( $client_id ), "client_site_id = ''" ),
			'connections' => self::count( Schema::integrations_table(), 'client_site_id', $sites ),
			'people'      => count( $found['people'] ),
		);
	}

	/**
	 * Deletes a client and everything attached to it. Not undoable.
	 *
	 * Files go before rows, because the rows are what say where the files
	 * are: an item's images, a checklist's evidence, and each site's signing
	 * key. The client's own people go last, and only somebody who was on
	 * this client alone: anybody who also works with another client stays.
	 *
	 * @param string $client_id Client id.
	 * @return array<string, int> What went, as counts() would have said.
	 */
	public static function erase( string $client_id ): array {
		global $wpdb;

		$found  = self::find( $client_id );
		$counts = self::tally( $client_id, $found );

		self::remove_files( $found['sites'] );

		$keys = array(
			self::BY_CLIENT => array( $client_id ),
			self::BY_SITE   => $found['sites'],
			self::BY_ITEM   => $found['items'],
			self::BY_SOURCE => $found['sources'],
		);

		foreach ( self::plan() as $step ) {
			list( $table, $column, $by ) = $step;

			foreach ( array_chunk( $keys[ $by ], self::CHUNK ) as $ids ) {
				$slots = implode( ', ', array_fill( 0, count( $ids ), '%s' ) );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Own tables and columns from plan(), never from input; the id placeholders are counted above.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$column} IN ({$slots})", $ids ) );
			}
		}

		foreach ( $found['people'] as $user_id ) {
			if ( array() === Memberships::for_user( $user_id, null ) ) {
				Users::delete( $user_id );
			}
		}

		return $counts;
	}

	/**
	 * The ids everything else is found through.
	 *
	 * @param string $client_id Client id.
	 * @return array{sites: array<int, string>, items: array<int, string>, sources: array<int, string>, people: array<int, string>}
	 */
	private static function find( string $client_id ): array {
		$sites  = array_map( 'strval', array_column( ClientSites::for_client( $client_id, null ), 'id' ) );
		$people = array();

		// The client's own people: a client-side role here, and nowhere else.
		foreach ( Memberships::for_client( $client_id, null ) as $membership ) {
			if ( ! Roles::is_client_side( (string) $membership['role'] ) ) {
				continue;
			}

			$user_id   = (string) $membership['user_id'];
			$elsewhere = array_filter(
				Memberships::for_user( $user_id, null ),
				static fn( array $one ): bool => $client_id !== (string) $one['client_id']
			);

			if ( array() === $elsewhere ) {
				$people[ $user_id ] = $user_id;
			}
		}

		return array(
			'sites'   => $sites,
			'items'   => self::ids( Schema::work_items_table(), $sites ),
			'sources' => self::ids( Schema::recurring_table(), $sites ),
			'people'  => array_values( $people ),
		);
	}

	/**
	 * The ids in one table on these sites.
	 *
	 * @param string             $table Own table.
	 * @param array<int, string> $sites Site ids.
	 * @return array<int, string>
	 */
	private static function ids( string $table, array $sites ): array {
		global $wpdb;

		$ids = array();

		foreach ( array_chunk( $sites, self::CHUNK ) as $chunk ) {
			$slots = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Own table; the site placeholders are counted above.
			$found = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE client_site_id IN ({$slots})", $chunk ) );
			$ids   = array_merge( $ids, array_map( 'strval', is_array( $found ) ? $found : array() ) );
		}

		return $ids;
	}

	/**
	 * How many rows in one table match these ids.
	 *
	 * @param string             $table  Own table.
	 * @param string             $column Own column.
	 * @param array<int, string> $ids    Ids.
	 * @param string             $also   A fixed extra condition, never input.
	 * @return int
	 */
	private static function count( string $table, string $column, array $ids, string $also = '' ): int {
		global $wpdb;

		$total = 0;
		$extra = '' === $also ? '' : " AND {$also}";

		foreach ( array_chunk( $ids, self::CHUNK ) as $chunk ) {
			$slots = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Own table and column, and a fixed condition from this class; the id placeholders are counted above.
			$total += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$column} IN ({$slots}){$extra}", $chunk ) );
		}

		return $total;
	}

	/**
	 * Removes what the rows point at outside the database: the images on the
	 * client's work, the evidence on its checklists, and its sites' keys.
	 *
	 * @param array<int, string> $sites Site ids.
	 */
	private static function remove_files( array $sites ): void {
		global $wpdb;

		foreach ( $sites as $site_id ) {
			foreach ( Items::for_site( $site_id, array( 'include_archived' => true ) ) as $item ) {
				foreach ( (array) ( $item['images'] ?? array() ) as $image ) {
					wp_delete_attachment( (int) $image['id'], true );
				}
			}

			$integration = Integrations::for_site( $site_id );

			if ( null !== $integration && '' !== (string) $integration['registry_site_id'] ) {
				Registry::forget( (string) $integration['registry_site_id'] );
			}

			$table = Schema::onboarding_evidence_table();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
			$stored = $wpdb->get_col( $wpdb->prepare( "SELECT stored_name FROM {$table} WHERE client_site_id = %s", $site_id ) );
			$dir    = EvidenceStore::absolute_dir( $site_id );

			foreach ( is_array( $stored ) ? $stored : array() as $name ) {
				// The stored name is ours, never the uploader's, but a path is
				// still only ever a file inside the site's own folder.
				wp_delete_file( $dir . '/' . basename( (string) $name ) );
			}

			foreach ( array_keys( EvidenceStore::protection_files() ) as $name ) {
				wp_delete_file( $dir . '/' . $name );
			}

			if ( is_dir( $dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem is not initialised on a REST request; a folder something else has written into is left.
				@rmdir( $dir );
			}
		}
	}
}
