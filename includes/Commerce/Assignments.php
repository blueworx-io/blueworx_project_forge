<?php
/**
 * Putting a site on a package, and taking it off again.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Commerce;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Tenancy\Ids;

/**
 * #146. A site's commercial position, written as a run of dated periods.
 *
 * Every change closes the period that was running and opens the next one. That
 * is the only writing pattern here, and it is what makes the acceptance
 * criterion true: a client's entitlement on any past date is whichever period
 * covers it, so reconstructing it is a lookup rather than a replay.
 *
 * **Assigning grants hours through the ledger, never around it.** The
 * allocation is a ledger entry like any other (#148), for exactly the figure
 * the preview showed (#147) — so the number somebody agreed to and the number
 * the balance moved by are the same number, and there is no second path by
 * which a site's hours can change.
 *
 * **Nothing here voids a balance.** Cancelling, suspending and lapsing all end
 * a period and leave the ledger alone, because COMM-4 freezes remaining hours
 * pending renewal rather than taking them away. Hours a client paid for are
 * theirs whatever their package is doing; taking them back is a decision
 * somebody makes explicitly, with a reason, as an adjustment.
 */
final class Assignments {

	/**
	 * Id prefix for a period.
	 */
	public const PREFIX = 'spk';

	/**
	 * Puts a site on a package from a date.
	 *
	 * Any period running on that date is closed the day before, so the two
	 * never overlap — a site on two packages at once is a site whose balance
	 * belongs to neither.
	 *
	 * @param array<string, mixed> $values client_site_id, client_id,
	 *                                     package_version_id, starts_on, and
	 *                                     optionally ends_on, hours_granted,
	 *                                     price_charged, currency, prorated,
	 *                                     note.
	 * @param int                  $actor  Who assigned it.
	 * @param string               $because One of {@see Support::BEGINNINGS}.
	 * @return array<string, mixed>|null Null when it could not be written.
	 */
	public static function assign( array $values, int $actor, string $because = Support::ASSIGNED ): ?array {
		global $wpdb;

		$site    = (string) ( $values['client_site_id'] ?? '' );
		$version = Packages::version( (string) ( $values['package_version_id'] ?? '' ) );
		$from    = (string) ( $values['starts_on'] ?? '' );

		if ( '' === $site || null === $version || '' === $from || $actor <= 0 ) {
			return null;
		}

		if ( ! in_array( $because, Support::BEGINNINGS, true ) ) {
			return null;
		}

		// Whatever was running stops the day before this starts. Done first, so
		// a failure here cannot leave a site holding two packages.
		self::close_open_on( $site, $from, self::ending_for( $because ) );

		$term_end = ProRata::term_end( $from, (int) $version['validity_months'] );
		$ends_on  = (string) ( $values['ends_on'] ?? '' );
		$prorated = ! empty( $values['prorated'] );

		$now = bwx_forge_now();
		$row = array(
			'id'                 => Ids::create( self::PREFIX ),
			'client_site_id'     => $site,
			'client_id'          => (string) ( $values['client_id'] ?? '' ),
			'package_version_id' => (string) $version['id'],

			/*
			 * A start date in the future is scheduled rather than active, and
			 * the row is never rewritten when the date arrives — see
			 * Support::state_on(), which reads the date rather than trusting a
			 * column somebody has to remember to update.
			 */
			'state'              => $from > gmdate( 'Y-m-d', $now ) ? Support::SCHEDULED : Support::ACTIVE,
			'starts_on'          => $from,
			'ends_on'            => '' !== $ends_on ? $ends_on : $term_end,
			'term_ends_on'       => $term_end,
			'began_because'      => $because,
			'ended_because'      => '',

			/*
			 * The pro-rata result for this period, copied in. A full year's
			 * figures live on the package version and cannot answer for a part
			 * year, and working it out again at read time would be a second
			 * calculation — which is exactly what #147 exists to prevent.
			 */
			'hours_granted'      => round( (float) ( $values['hours_granted'] ?? $version['hours'] ), 2 ),
			'price_charged'      => (int) ( $values['price_charged'] ?? $version['price'] ),
			'currency'           => (string) $version['currency'],
			'prorated'           => $prorated ? 1 : 0,
			'note'               => mb_substr( trim( (string) ( $values['note'] ?? '' ) ), 0, 191 ),
			'created_at'         => $now,
			'updated_at'         => $now,
			'created_by'         => $actor,
			'record_version'     => 1,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		if ( ! $wpdb->insert( Schema::site_packages_table(), $row, Formats::for_row( $row ) ) ) {
			return null;
		}

		/*
		 * The hours, granted the only way hours are ever granted. If this fails
		 * the period is removed again: a client on a package with no hours
		 * behind it looks entitled and can spend nothing, which is worse than
		 * not being assigned at all and much harder to notice.
		 */
		$granted = Ledger::append(
			array(
				'client_site_id' => $site,
				'event_type'     => Entries::ALLOCATION,
				'hours'          => (float) $row['hours_granted'],
				'source_type'    => 'assignment',
				'source_id'      => (string) $row['id'],
				'actor'          => $actor,
				'occurred_at'    => (int) strtotime( $from . ' 00:00:00 UTC' ),
			)
		);

		if ( null === $granted ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
			$wpdb->delete( Schema::site_packages_table(), array( 'id' => $row['id'] ), array( '%s' ) );

			return null;
		}

		return self::get( (string) $row['id'] );
	}

	/**
	 * Stops a site's cover from a date, without touching its hours.
	 *
	 * @param string $client_site_id The site.
	 * @param string $from           YYYY-MM-DD, the first day not covered.
	 * @param int    $actor          Who suspended it.
	 * @param string $note           Why.
	 * @return array<string, mixed>|null The suspended period, or null.
	 */
	public static function suspend( string $client_site_id, string $from, int $actor, string $note = '' ): ?array {
		$running = self::current( $client_site_id );

		if ( null === $running || Support::SUSPENDED === (string) $running['state'] ) {
			return null;
		}

		self::close_open_on( $client_site_id, $from, Support::SUSPENDING );

		return self::open(
			$running,
			array(
				'state'         => Support::SUSPENDED,
				'starts_on'     => $from,
				'began_because' => Support::SUSPENDING,
				'note'          => $note,

				/*
				 * No second allocation. The hours were granted once when the
				 * package was assigned, and a suspension is about cover rather
				 * than about the balance — COMM-4 freezes what is left rather
				 * than voiding it.
				 */
				'hours_granted' => 0,
				'price_charged' => 0,
			),
			$actor
		);
	}

	/**
	 * Puts a suspended site back on cover from a date.
	 *
	 * @param string $client_site_id The site.
	 * @param string $from           YYYY-MM-DD, the first day covered again.
	 * @param int    $actor          Who resumed it.
	 * @return array<string, mixed>|null The resumed period, or null.
	 */
	public static function resume( string $client_site_id, string $from, int $actor ): ?array {
		$suspended = self::current( $client_site_id );

		if ( null === $suspended || Support::SUSPENDED !== (string) $suspended['state'] ) {
			return null;
		}

		self::close_open_on( $client_site_id, $from, Support::RESUMED );

		return self::open(
			$suspended,
			array(
				'state'         => Support::ACTIVE,
				'starts_on'     => $from,
				'began_because' => Support::RESUMED,
				'hours_granted' => 0,
				'price_charged' => 0,
			),
			$actor
		);
	}

	/**
	 * Ends a site's cover for good from a date.
	 *
	 * The hours are left alone, deliberately. Cancelling is about what happens
	 * next; hours already paid for are the client's, and taking them back is a
	 * separate decision somebody makes with a reason attached.
	 *
	 * @param string $client_site_id The site.
	 * @param string $from           YYYY-MM-DD, the first day not covered.
	 * @param int    $actor          Who cancelled it.
	 * @param string $because        One of {@see Support::ENDINGS}.
	 * @return bool
	 */
	public static function cancel( string $client_site_id, string $from, int $actor, string $because = Support::CANCELLED ): bool {
		if ( ! in_array( $because, Support::ENDINGS, true ) ) {
			return false;
		}

		return self::close_open_on( $client_site_id, $from, $because ) > 0;
	}

	/* ------------------------------------------------------------ reading */

	/**
	 * One period.
	 *
	 * @param string $id The period.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		global $wpdb;

		$table = Schema::site_packages_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Every period a site has had, oldest first.
	 *
	 * @param string $client_site_id The site.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_site( string $client_site_id ): array {
		global $wpdb;

		$table = Schema::site_packages_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_site_id = %s ORDER BY starts_on ASC, created_at ASC", $client_site_id ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * The period running now, if there is one.
	 *
	 * @param string $client_site_id The site.
	 * @return array<string, mixed>|null
	 */
	public static function current( string $client_site_id ): ?array {
		$today  = gmdate( 'Y-m-d', bwx_forge_now() );
		$period = Support::period_on( self::for_site( $client_site_id ), $today );

		return array() === $period ? null : $period;
	}

	/**
	 * What a site was entitled to on a date (#146's criterion).
	 *
	 * @param string $client_site_id The site.
	 * @param string $date           YYYY-MM-DD.
	 * @return array<string, mixed>
	 */
	public static function entitlement_on( string $client_site_id, string $date ): array {
		return Support::entitlement_on( self::for_site( $client_site_id ), $date );
	}

	/* ------------------------------------------------------------ private */

	/**
	 * Opens a period carrying the running one's package forward.
	 *
	 * @param array<string, mixed> $from   The period being continued.
	 * @param array<string, mixed> $values What is different about the new one.
	 * @param int                  $actor  Who did it.
	 * @return array<string, mixed>|null
	 */
	private static function open( array $from, array $values, int $actor ): ?array {
		global $wpdb;

		$now = bwx_forge_now();
		$row = array_merge(
			array(
				'id'                 => Ids::create( self::PREFIX ),
				'client_site_id'     => (string) $from['client_site_id'],
				'client_id'          => (string) $from['client_id'],
				'package_version_id' => (string) $from['package_version_id'],
				'state'              => Support::ACTIVE,
				'starts_on'          => '',
				'ends_on'            => (string) $from['ends_on'],
				'term_ends_on'       => (string) $from['term_ends_on'],
				'began_because'      => Support::STARTED,
				'ended_because'      => '',
				'hours_granted'      => 0.0,
				'price_charged'      => 0,
				'currency'           => (string) $from['currency'],
				'prorated'           => 0,
				'note'               => '',
				'created_at'         => $now,
				'updated_at'         => $now,
				'created_by'         => $actor,
				'record_version'     => 1,
			),
			$values
		);

		$row['note'] = mb_substr( trim( (string) $row['note'] ), 0, 191 );

		// An end date already in the past would open a period that covers
		// nothing. Left open instead, which is what continuing a term means.
		if ( '' !== (string) $row['ends_on'] && (string) $row['ends_on'] < (string) $row['starts_on'] ) {
			$row['ends_on'] = '';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$written = $wpdb->insert( Schema::site_packages_table(), $row, Formats::for_row( $row ) );

		return $written ? self::get( (string) $row['id'] ) : null;
	}

	/**
	 * Closes whatever is running the day before a date.
	 *
	 * The day before, so the closing period's last covered day and the new
	 * one's first are consecutive rather than the same. Two periods covering
	 * one day is the bug that makes "what was true then" ambiguous.
	 *
	 * @param string $client_site_id The site.
	 * @param string $from           YYYY-MM-DD, when the next period starts.
	 * @param string $because        One of {@see Support::ENDINGS}.
	 * @return int How many were closed.
	 */
	private static function close_open_on( string $client_site_id, string $from, string $because ): int {
		global $wpdb;

		$closed = 0;
		$before = gmdate( 'Y-m-d', (int) strtotime( $from . ' 00:00:00 UTC' ) - DAY_IN_SECONDS );

		foreach ( self::for_site( $client_site_id ) as $period ) {
			$ends = (string) $period['ends_on'];

			// Already over before this starts, so there is nothing to close.
			if ( '' !== $ends && $ends < $from ) {
				continue;
			}

			$changes = array(
				'ends_on'        => $before,
				'ended_because'  => in_array( $because, Support::ENDINGS, true ) ? $because : Support::REPLACED,
				'updated_at'     => bwx_forge_now(),
				'record_version' => (int) $period['record_version'] + 1,
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
			$updated = $wpdb->update( Schema::site_packages_table(), $changes, array( 'id' => $period['id'] ), Formats::for_row( $changes ), array( '%s' ) );

			$closed += false === $updated ? 0 : 1;
		}

		return $closed;
	}

	/**
	 * The ending that matches a beginning.
	 *
	 * @param string $because Why the new period begins.
	 * @return string
	 */
	private static function ending_for( string $because ): string {
		switch ( $because ) {
			case Support::RENEWED:
				return Support::RENEWED;
			case Support::RESUMED:
				return Support::RESUMED;
			default:
				return Support::REPLACED;
		}
	}

	/**
	 * A stored row, with its numbers as numbers.
	 *
	 * @param array<string, mixed> $row As the database returned it.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		return array(
			'id'                 => (string) $row['id'],
			'client_site_id'     => (string) $row['client_site_id'],
			'client_id'          => (string) $row['client_id'],
			'package_version_id' => (string) $row['package_version_id'],
			'state'              => (string) $row['state'],
			'starts_on'          => (string) $row['starts_on'],
			'ends_on'            => (string) $row['ends_on'],
			'term_ends_on'       => (string) $row['term_ends_on'],
			'began_because'      => (string) $row['began_because'],
			'ended_because'      => (string) $row['ended_because'],
			'hours_granted'      => (float) $row['hours_granted'],
			'price_charged'      => (int) $row['price_charged'],
			'currency'           => (string) $row['currency'],
			'prorated'           => (bool) $row['prorated'],
			'note'               => (string) $row['note'],
			'created_at'         => (int) $row['created_at'],
			'updated_at'         => (int) $row['updated_at'],
			'created_by'         => (int) $row['created_by'],
			'record_version'     => (int) $row['record_version'],
		);
	}
}
