<?php
/**
 * Every hour a site has had, and every hour it has spent.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Commerce;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Tenancy\Ids;

/**
 * #148, COMM-3: **one append-only ledger that every hour passes through.**
 *
 * There is one write here and it only ever adds a row. There is no update, no
 * delete, and no stored balance — and the absence of all three is the feature
 * rather than a gap to fill in later. A balance is the sum of its entries,
 * worked out when somebody asks, so there is no figure anywhere that the
 * entries do not add up to and no way to reach one.
 *
 * That matters because this is the record the studio and the client are both
 * reading when they disagree about a bill. A stored total is right until
 * something goes wrong with it, and the moment it is wrong there is nothing to
 * check it against. A sum is right or the entries are wrong, and the entries
 * are all anybody has to look at.
 *
 * **A correction is another entry.** Charged the wrong site, took too many
 * hours, agreed to write some off: each of those is an adjustment with a reason
 * on it, appended. Nothing that has been written is ever changed, which is why
 * "what did this client's balance look like on the fourteenth of March" has an
 * answer — see {@see self::balance_at()}.
 *
 * Scoped to the site (ARCH-3). A client with three sites has three balances,
 * and no work on one can quietly draw on another's hours.
 */
final class Ledger {

	/**
	 * Id prefix for a ledger entry.
	 */
	public const PREFIX = 'hle';

	/**
	 * Appends one entry, if it is one that may be written.
	 *
	 * The sign comes from the event type rather than from the caller
	 * ({@see Entries::signed()}), so a reservation cannot credit a site because
	 * somebody passed a negative, and an allocation cannot debit one.
	 *
	 * @param array<string, mixed> $entry    client_site_id, event_type, hours,
	 *                                       source_type, source_id, actor, and
	 *                                       optionally reason and expires_at.
	 * @param bool                 $override Whether a Primary administrator has
	 *                                       allowed the balance to go negative
	 *                                       (COMM-3). Recorded by the reason the
	 *                                       entry must then carry.
	 * @return array<string, mixed>|null Null when it was refused or not written.
	 */
	public static function append( array $entry, bool $override = false ): ?array {
		global $wpdb;

		$site = (string) ( $entry['client_site_id'] ?? '' );
		$type = (string) ( $entry['event_type'] ?? '' );

		if ( '' === $site || ! Entries::exists( $type ) ) {
			return null;
		}

		$candidate = array(
			'event_type' => $type,
			'hours'      => Entries::signed( $type, (float) ( $entry['hours'] ?? 0 ) ),
			'source_id'  => (string) ( $entry['source_id'] ?? '' ),
			'actor'      => (int) ( $entry['actor'] ?? 0 ),
			'reason'     => (string) ( $entry['reason'] ?? '' ),
		);

		if ( '' !== Entries::refuse( $candidate, self::balance( $site ), $override ) ) {
			return null;
		}

		$now = bwx_forge_now();
		$row = array(
			'id'             => Ids::create( self::PREFIX ),
			'client_site_id' => $site,
			'event_type'     => $type,
			'hours'          => (float) $candidate['hours'],
			'source_type'    => (string) ( $entry['source_type'] ?? '' ),
			'source_id'      => (string) $candidate['source_id'],
			'reason'         => mb_substr( trim( (string) $candidate['reason'] ), 0, Entries::MAX_REASON ),

			// Top-ups alone (COMM-4). Anything else carrying an expiry would
			// make the consumption order answer a question nobody asked of it.
			'expires_at'     => Entries::TOP_UP === $type ? (int) ( $entry['expires_at'] ?? 0 ) : 0,
			'actor'          => (int) $candidate['actor'],

			/*
			 * When it happened, which a caller may set — a reconciliation
			 * entered on Monday for something that happened on Friday belongs
			 * on Friday, or the Friday balance is wrong for ever.
			 */
			'occurred_at'    => (int) ( $entry['occurred_at'] ?? 0 ) > 0 ? (int) $entry['occurred_at'] : $now,

			// When the row was written, which nobody may set. The pair is how a
			// backdated entry stays honest: it says both when it counts from
			// and when somebody actually typed it.
			'created_at'     => $now,
			'created_by'     => (int) $candidate['actor'],
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		$written = $wpdb->insert( Schema::hour_ledger_table(), $row, Formats::for_row( $row ) );

		return $written ? self::hydrate( $row ) : null;
	}

	/**
	 * What a site has left.
	 *
	 * Summed in the database rather than in PHP, because a mature site has
	 * thousands of entries and drawing them all across to add them up is the
	 * cost of every gate check for the life of the product.
	 *
	 * @param string $client_site_id The site.
	 * @return float
	 */
	public static function balance( string $client_site_id ): float {
		global $wpdb;

		$table = Schema::hour_ledger_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$total = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(hours) FROM {$table} WHERE client_site_id = %s", $client_site_id ) );

		return round( (float) $total, 2 );
	}

	/**
	 * What a site had left on a given day.
	 *
	 * The reason nothing here is ever edited. A balance somebody disputes is
	 * usually a balance from a month ago, and the only way to answer for it is
	 * to add up what had happened by then.
	 *
	 * @param string $client_site_id The site.
	 * @param int    $at             Unix time; nothing after it counts.
	 * @return float
	 */
	public static function balance_at( string $client_site_id, int $at ): float {
		global $wpdb;

		$table = Schema::hour_ledger_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$total = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(hours) FROM {$table} WHERE client_site_id = %s AND occurred_at <= %d", $client_site_id, $at ) );

		return round( (float) $total, 2 );
	}

	/**
	 * A site's entries, oldest first — the order they happened in.
	 *
	 * @param string $client_site_id The site.
	 * @param int    $limit          How many at most.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_site( string $client_site_id, int $limit = 500 ): array {
		global $wpdb;

		$table = Schema::hour_ledger_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE client_site_id = %s ORDER BY occurred_at ASC, id ASC LIMIT %d",
				$client_site_id,
				max( 1, min( 2000, $limit ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Everything one thing has put through the ledger.
	 *
	 * How a work item's reservation is found when it needs releasing, and how
	 * "did we ever charge for this" is answered.
	 *
	 * @param string $source_type What kind of thing.
	 * @param string $source_id   Which one.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_source( string $source_type, string $source_id ): array {
		global $wpdb;

		$table = Schema::hour_ledger_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_type = %s AND source_id = %s ORDER BY occurred_at ASC, id ASC", $source_type, $source_id ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * The credits a site has, in the order they should be drawn down (COMM-4).
	 *
	 * The reading half of {@see Entries::consumption_order()}. What is left of
	 * each is #149's arithmetic; this says which comes first.
	 *
	 * @param string $client_site_id The site.
	 * @return array<int, array<string, mixed>>
	 */
	public static function credits( string $client_site_id ): array {
		$credits = array_values(
			array_filter(
				self::for_site( $client_site_id ),
				static fn( array $entry ): bool => in_array( (string) $entry['event_type'], Entries::ADDS, true )
			)
		);

		return Entries::consumption_order( $credits );
	}

	/**
	 * A stored row, with its numbers as numbers.
	 *
	 * @param array<string, mixed> $row As the database returned it.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		return array(
			'id'             => (string) $row['id'],
			'client_site_id' => (string) $row['client_site_id'],
			'event_type'     => (string) $row['event_type'],
			'hours'          => (float) $row['hours'],
			'source_type'    => (string) $row['source_type'],
			'source_id'      => (string) $row['source_id'],
			'reason'         => (string) $row['reason'],
			'expires_at'     => (int) $row['expires_at'],
			'actor'          => (int) $row['actor'],
			'occurred_at'    => (int) $row['occurred_at'],
			'created_at'     => (int) $row['created_at'],
			'created_by'     => (int) $row['created_by'],
		);
	}
}
