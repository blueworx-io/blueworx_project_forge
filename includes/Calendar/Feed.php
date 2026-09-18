<?php
/**
 * Everything on the studio's diary in a window, in one shape.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Calendar;

use Blueworx\Forge\Capacity\Unavailability;
use Blueworx\Forge\Commerce\SureCart\Subscriptions;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Meetings\Diary;
use Blueworx\Forge\Meetings\Series;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Reach;
use Blueworx\Forge\Tenancy\Users;
use Blueworx\Forge\Work\Items;

/**
 * Luke, 2026-09-17: "ensure the following all show in the Calendar and Daily
 * Standup: recurring tasks, calendar dates, meetings, subscriptions, leave
 * dates." Five things kept in five places, read here into one list so the
 * calendar and the standup draw the same day.
 *
 * Every entry is `{ id, kind, date, ends_on, title, detail, people, item_id }`.
 * The reach decides which sites' chores and meetings are seen; dates, renewals
 * and leave are the studio's own and are seen by everyone on it.
 */
final class Feed {

	/**
	 * The kinds, and what each is called on a screen.
	 */
	public const KINDS = array(
		'recurring'    => 'Chore',
		'date'         => 'Date',
		'meeting'      => 'Meeting',
		'subscription' => 'Renewal',
		'leave'        => 'Away',
	);

	/**
	 * The diary for whoever is asking, in a window.
	 *
	 * @param array<string, mixed> $reach The caller's reach.
	 * @param string               $from  YYYY-MM-DD, inclusive.
	 * @param string               $to    YYYY-MM-DD, inclusive.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_reach( array $reach, string $from, string $to ): array {
		if ( Reach::is_nothing( $reach ) || '' === $from || '' === $to || $to < $from ) {
			return array();
		}

		$sites   = Reach::keep_sites( $reach, ClientSites::all( 'active' ), 'id' );
		$people  = Users::all( 'active' );
		$names   = array_column( $people, 'display_name', 'id' );
		$entries = array_merge(
			self::recurring( array_column( $sites, 'id' ), $from, $to ),
			self::dates( $from, $to ),
			self::meetings( $sites, $from, $to, $names ),
			self::subscriptions( $from, $to ),
			self::leave( array_keys( $names ), $from, $to, $names )
		);

		usort(
			$entries,
			static fn( array $a, array $b ): int => array( $a['date'], $a['kind'], $a['title'] ) <=> array( $b['date'], $b['kind'], $b['title'] )
		);

		return $entries;
	}

	/**
	 * Recurring chores due in the window, on the sites in reach.
	 *
	 * @param array<int, string> $site_ids Sites.
	 * @param string             $from     YYYY-MM-DD.
	 * @param string             $to       YYYY-MM-DD.
	 * @return array<int, array<string, mixed>>
	 */
	private static function recurring( array $site_ids, string $from, string $to ): array {
		global $wpdb;

		if ( array() === $site_ids ) {
			return array();
		}

		$table = Schema::work_items_table();
		$slots = implode( ', ', array_fill( 0, count( $site_ids ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name cannot be a placeholder; the site placeholders are counted above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE recurring_id <> '' AND archived = 0 AND client_site_id IN ({$slots}) AND planned_due >= %s AND planned_due <= %s ORDER BY planned_due ASC, title ASC",
				array_merge( array_values( $site_ids ), array( $from, $to ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$out = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$item   = Items::get( (string) $row['id'] );
			$people = null === $item ? array() : (array) $item['assignees'];
			$ticked = null === $item ? 0 : count( (array) $item['ticks'] );

			$out[] = self::entry(
				'recurring',
				(string) $row['id'],
				(string) $row['planned_due'],
				'',
				(string) $row['title'],
				array() === $people ? (string) ( $item['stage_label'] ?? '' ) : sprintf( '%d of %d done', $ticked, count( $people ) ),
				$people,
				(string) $row['id']
			);
		}

		return $out;
	}

	/**
	 * The studio's own dates touching the window.
	 *
	 * @param string $from YYYY-MM-DD.
	 * @param string $to   YYYY-MM-DD.
	 * @return array<int, array<string, mixed>>
	 */
	private static function dates( string $from, string $to ): array {
		$out = array();

		foreach ( Dates::between( $from, $to ) as $date ) {
			$out[] = self::entry(
				'date',
				(string) $date['id'],
				(string) $date['on_date'],
				(string) $date['ends_on'],
				(string) $date['title'],
				(string) $date['kind_label'] . ( '' === $date['note'] ? '' : ' · ' . $date['note'] ),
				Dates::ALL === $date['people'] ? Dates::ALL : (array) $date['people']
			);
		}

		return $out;
	}

	/**
	 * Meetings on the sites in reach.
	 *
	 * @param array<int, array<string, mixed>> $sites Sites.
	 * @param string                           $from  YYYY-MM-DD.
	 * @param string                           $to    YYYY-MM-DD.
	 * @param array<string, string>            $names Person id to name.
	 * @return array<int, array<string, mixed>>
	 */
	private static function meetings( array $sites, string $from, string $to, array $names ): array {
		$out = array();

		foreach ( $sites as $site ) {
			foreach ( Series::for_site( (string) $site['id'] ) as $series ) {
				foreach ( Diary::for_series( $series, $from, $to ) as $meeting ) {
					if ( 'cancelled' === (string) ( $meeting['status'] ?? '' ) ) {
						continue;
					}

					$host = (string) ( $series['host_user_id'] ?? '' );

					$out[] = self::entry(
						'meeting',
						'' !== (string) $meeting['id'] ? (string) $meeting['id'] : (string) $series['id'] . '@' . (string) $meeting['on'],
						(string) $meeting['on'],
						'',
						(string) $series['title'],
						trim( (string) $meeting['at'] . ' · ' . (string) $site['name'] . ( '' === $host ? '' : ' · ' . (string) ( $names[ $host ] ?? '' ) ), ' ·' ),
						'' === $host ? array() : array( $host )
					);
				}
			}
		}

		return $out;
	}

	/**
	 * Subscriptions renewing in the window.
	 *
	 * @param string $from YYYY-MM-DD.
	 * @param string $to   YYYY-MM-DD.
	 * @return array<int, array<string, mixed>>
	 */
	private static function subscriptions( string $from, string $to ): array {
		$out = array();

		foreach ( Subscriptions::all() as $subscription ) {
			$renews = (string) $subscription['renews_on'];

			if ( '' === $renews || $renews < $from || $renews > $to || 'active' !== (string) $subscription['status'] ) {
				continue;
			}

			$out[] = self::entry(
				'subscription',
				(string) $subscription['id'],
				$renews,
				'',
				(string) $subscription['customer_name'],
				(string) $subscription['product_name'],
				array()
			);
		}

		return $out;
	}

	/**
	 * Who is away in the window.
	 *
	 * @param array<int, string>    $user_ids Everyone active.
	 * @param string                $from     YYYY-MM-DD.
	 * @param string                $to       YYYY-MM-DD.
	 * @param array<string, string> $names    Person id to name.
	 * @return array<int, array<string, mixed>>
	 */
	private static function leave( array $user_ids, string $from, string $to, array $names ): array {
		$out = array();

		foreach ( Unavailability::for_people( $user_ids, $from, $to ) as $user_id => $records ) {
			foreach ( $records as $record ) {
				$out[] = self::entry(
					'leave',
					(string) $record['id'],
					(string) $record['starts_on'],
					(string) $record['ends_on'],
					(string) ( $names[ (string) $user_id ] ?? $user_id ),
					ucfirst( str_replace( '-', ' ', (string) $record['kind'] ) ) . ( '' === $record['note'] ? '' : ' · ' . (string) $record['note'] ),
					array( (string) $user_id )
				);
			}
		}

		return $out;
	}

	/**
	 * One entry, in the shape every kind shares.
	 *
	 * @param string                    $kind    Which kind.
	 * @param string                    $id      Its own id.
	 * @param string                    $date    First day.
	 * @param string                    $ends_on Last day, or '' for one day.
	 * @param string                    $title   What it is.
	 * @param string                    $detail  A few more words.
	 * @param array<int, string>|string $people  Who it is for: ids, or 'all'.
	 * @param string                    $item_id The work item behind it, if any.
	 * @return array<string, mixed>
	 */
	private static function entry( string $kind, string $id, string $date, string $ends_on, string $title, string $detail, $people, string $item_id = '' ): array {
		return array(
			'id'      => $kind . ':' . $id,
			'kind'    => $kind,
			'label'   => self::KINDS[ $kind ] ?? $kind,
			'date'    => $date,
			'ends_on' => $ends_on,
			'title'   => $title,
			'detail'  => $detail,
			'people'  => $people,
			'item_id' => $item_id,
		);
	}
}
