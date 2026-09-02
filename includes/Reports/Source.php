<?php
/**
 * Gathering what the delivery numbers are worked out from.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Reports;

use Blueworx\Forge\Capacity\Position;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Onboarding\Steps;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Reach;
use Blueworx\Forge\Tenancy\Users;

/**
 * #176. The reading half: {@see Delivery} decides, this fetches.
 *
 * The split is the point of both files. Delivery is pure and can be argued with
 * in a test against a known log; this knows about tables and knows nothing
 * about what any of it means. When the two are one class, "why does the report
 * say eleven days" can only be answered by running it against a database.
 *
 * **Reach is applied first and on its own.** Reports span clients by design —
 * that is what a delivery report is — so the scoping is the only thing standing
 * between one person's numbers and every client's work. It narrows the sites
 * first, and everything else is fetched for those sites; never one combined
 * pass where a mistake in the arithmetic could widen what is visible rather
 * than narrow it. The same order Standup\Board uses, for the same reason.
 *
 * **Two queries, whatever the window and however many clients.** One for the
 * work and one for the log, both across every site in reach at once. A read
 * that costs a query per client is fine on the two a developer has and unusable
 * on forty, and nothing says so until then — which is not hypothetical here:
 * this fetched per site until tests/e2e/performance measured it (#183) and
 * found a hundred and sixteen queries where there should have been two.
 */
final class Source {

	/**
	 * Every report, for whoever is asking, over one window.
	 *
	 * @param array<string, mixed> $reach The caller's reach.
	 * @param int                  $from  Window start, a timestamp.
	 * @param int                  $to    Window end, a timestamp.
	 * @return array<string, mixed>
	 */
	public static function for_reach( array $reach, int $from, int $to ): array {
		if ( Reach::is_nothing( $reach ) ) {
			return Delivery::compute( array(), array(), $from, $to );
		}

		$sites    = Reach::keep_sites( $reach, ClientSites::all( 'active' ), 'id' );
		$site_ids = array_column( $sites, 'id' );

		if ( array() === $site_ids ) {
			return Delivery::compute( array(), array(), $from, $to );
		}

		$items  = self::items( $site_ids );
		$events = self::events( $site_ids, $from, $to );

		/*
		 * #261. The operational six, merged into the same answer rather than
		 * given a route of their own. One read, one scope check, one window —
		 * two endpoints would mean two chances to get the tenant boundary
		 * wrong, and a screen that has to reconcile two windows before it can
		 * draw anything.
		 */
		return array_merge(
			Delivery::compute( $items, $events, $from, $to ),
			Operations::compute(
				array(
					'items'         => $items,
					'events'        => $events,
					'capacity'      => self::capacity( $from, $to ),
					'ledger'        => self::ledger( $site_ids ),
					'onboarding'    => Steps::for_sites( $site_ids ),
					'submissions'   => self::submissions( $site_ids ),
					'notifications' => self::notifications( $site_ids, $from, $to ),
				)
			)
		);
	}

	/**
	 * Everybody's position over the window.
	 *
	 * **Deliberately not scoped to the caller's reach**, and this is the one
	 * read here that is not. A person cannot look free on one client while
	 * committed on another, so the utilisation figure is only true across
	 * tenants — the same reason Capacity\Commitments spans them. What is
	 * reported is a total and a count, never whose time it is or which client
	 * it is for, so nothing crosses the boundary that a figure could be traced
	 * back through.
	 *
	 * @param int $from Window start, a timestamp.
	 * @param int $to   Window end, a timestamp.
	 * @return array<int, array<string, mixed>>
	 */
	private static function capacity( int $from, int $to ): array {
		$people = array_column( Users::all( 'active' ), 'id' );

		if ( array() === $people ) {
			return array();
		}

		return array_values(
			Position::for_people( $people, gmdate( 'Y-m-d', $from ), gmdate( 'Y-m-d', $to ) )
		);
	}

	/**
	 * The hour ledger for the sites in reach, in one query.
	 *
	 * Every entry, not a window: what a client has been granted is usually
	 * outside any window somebody is reporting on, and a "hours granted" figure
	 * that only counted this quarter's allocations would be nonsense.
	 *
	 * @param array<int, string> $site_ids Sites in reach.
	 * @return array<int, array<string, mixed>>
	 */
	private static function ledger( array $site_ids ): array {
		global $wpdb;

		$table = Schema::hour_ledger_table();
		$slots = implode( ', ', array_fill( 0, count( $site_ids ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Own table, and the table name cannot be a placeholder; the site placeholders are built above from the values themselves and every value is still prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT event_type, hours FROM {$table} WHERE client_site_id IN ({$slots})", $site_ids ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * What clients have asked for, in one query.
	 *
	 * @param array<int, string> $site_ids Sites in reach.
	 * @return array<int, array<string, mixed>>
	 */
	private static function submissions( array $site_ids ): array {
		global $wpdb;

		$table = Schema::submissions_table();
		$slots = implode( ', ', array_fill( 0, count( $site_ids ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Own table, and the table name cannot be a placeholder; the site placeholders are built above from the values themselves and every value is still prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT type, intake_state FROM {$table} WHERE client_site_id IN ({$slots})", $site_ids ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * What became of the notifications raised in the window, in one query.
	 *
	 * Windowed, unlike the ledger: whether email is arriving is a question
	 * about now, and a share dragged down by a broken week last year would keep
	 * a screen red long after it was fixed.
	 *
	 * @param array<int, string> $site_ids Sites in reach.
	 * @param int                $from     Window start, a timestamp.
	 * @param int                $to       Window end, a timestamp.
	 * @return array<int, array<string, mixed>>
	 */
	private static function notifications( array $site_ids, int $from, int $to ): array {
		global $wpdb;

		$table  = Schema::notification_events_table();
		$slots  = implode( ', ', array_fill( 0, count( $site_ids ), '%s' ) );
		$values = array_merge( $site_ids, array( $from, $to ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Own table, and the table name cannot be a placeholder; the site placeholders are built above from the values themselves and every value is still prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT outcome FROM {$table} WHERE client_site_id IN ({$slots}) AND raised_at >= %d AND raised_at <= %d", $values ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The work on those sites, in one query.
	 *
	 * One query rather than one per site, which is the whole point of #183: a
	 * read that costs a query per client is fine on the two a developer has and
	 * unusable on forty, and nothing says so until then. tests/e2e/performance
	 * holds this to a budget, and caught exactly that here.
	 *
	 * Everything, including released work. Standup drops released work because
	 * none of it can ever need attention; a delivery report is largely *about*
	 * the released work, so the same shortcut would leave the throughput at
	 * nothing.
	 *
	 * Only the columns the reports read. A report over a quarter can span
	 * thousands of rows and none of it needs the prose.
	 *
	 * @param array<int, string> $site_ids Sites in reach.
	 * @return array<int, array<string, mixed>>
	 */
	private static function items( array $site_ids ): array {
		global $wpdb;

		$table        = Schema::work_items_table();
		$placeholders = implode( ', ', array_fill( 0, count( $site_ids ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Own table, and the table name cannot be a placeholder; the site placeholders are built above from the values themselves and every value is still prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, stage, planned_due, title FROM {$table} WHERE client_site_id IN ({$placeholders}) AND archived = '0'",
				$site_ids
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The changelog for those sites, oldest first.
	 *
	 * Reaching back before the window rather than starting at it, because a stay
	 * that began earlier has to be measured from somewhere. Delivery clips those
	 * to the window; what it cannot do is invent an arrival it was never handed.
	 *
	 * The reach-back is bounded so a report on a year-old site does not read the
	 * whole log to find one arrival.
	 *
	 * @param array<int, string> $site_ids Sites in reach.
	 * @param int                $from     Window start.
	 * @param int                $to       Window end.
	 * @return array<int, array<string, mixed>>
	 */
	private static function events( array $site_ids, int $from, int $to ): array {
		global $wpdb;

		$table = Schema::work_events_table();

		$placeholders = implode( ', ', array_fill( 0, count( $site_ids ), '%s' ) );
		$arguments    = array_merge( $site_ids, array( $from - self::REACH_BACK, $to ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Own table, and the table name cannot be a placeholder; the site placeholders are built above from the values themselves and every value is still prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT item_id, action, from_stage, to_stage, occurred_at FROM {$table} WHERE client_site_id IN ({$placeholders}) AND occurred_at >= %d AND occurred_at <= %d ORDER BY occurred_at ASC, id ASC",
				$arguments
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array_map(
			static function ( array $row ): array {
				return array(
					'item_id'     => (string) $row['item_id'],
					'action'      => (string) $row['action'],
					'from_stage'  => (string) $row['from_stage'],
					'to_stage'    => (string) $row['to_stage'],
					'occurred_at' => (int) $row['occurred_at'],
				);
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * How far before the window the log is read.
	 *
	 * A quarter. Long enough that an ordinary stay spanning the start of the
	 * window is measured from its real arrival, short enough that a report on a
	 * site with years of history does not read all of it. Work that arrived
	 * somewhere longer ago than this is clipped to the window start, which is
	 * what Delivery would have done to it anyway.
	 */
	private const REACH_BACK = 7776000;
}
