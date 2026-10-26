<?php
/**
 * Putting a synced meeting's hours into the ledger, and keeping them there.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Google;

use Blueworx\Forge\Commerce\Assignments;
use Blueworx\Forge\Commerce\Ledger;
use Blueworx\Forge\Meetings\MeetingHours;
use Blueworx\Forge\Meetings\Occurrence;

/**
 * #606. The standing meetings' hour rules, used as they are.
 *
 * {@see MeetingHours} already says everything about when a meeting holds,
 * spends and gives back hours (MEET-4, MEET-5). What a synced meeting adds is
 * two choices a person makes: which client site it was for — which can change,
 * so hours held on the old site go back first — and whether its time comes
 * off support at all. A meeting whose time does not come off support is asked
 * the same question as a cancelled one, so whatever it held goes back and it
 * never spends anything; spend already made stays, because spend is a floor.
 *
 * Like the standing meetings' sweep, there is no cron: this runs when the app
 * opens and whenever a meeting is settled, and finds nothing to write when the
 * ledger already agrees.
 */
final class Hours {

	/**
	 * What these entries are against, in the ledger's source column.
	 */
	public const SOURCE = 'google-meeting';

	/**
	 * The entries that would bring the ledger to where a meeting stands.
	 *
	 * @param array<string, mixed>             $meeting      Hydrated, or at least id, on, planned_hours, status, client_site_id, off_support.
	 * @param array<int, array<string, mixed>> $entries      Its ledger entries so far, on any site.
	 * @param string                           $today        YYYY-MM-DD.
	 * @param string                           $term_ends_on Last day of the current site's term, or ''.
	 * @return array<int, array{client_site_id: string, event_type: string, hours: float}>
	 */
	public static function plan_for( array $meeting, array $entries, string $today, string $term_ends_on ): array {
		$current = (string) ( $meeting['client_site_id'] ?? '' );
		$by_site = array();

		foreach ( $entries as $entry ) {
			$by_site[ (string) ( $entry['client_site_id'] ?? '' ) ][] = $entry;
		}

		$plan = array();

		// Whatever another site holds goes back first.
		foreach ( $by_site as $site => $lines ) {
			if ( (string) $site === $current ) {
				continue;
			}

			$plan = array_merge( $plan, self::sited( (string) $site, MeetingHours::plan( self::giving_back( $meeting, $lines ), $lines, $today, '', false ) ) );
		}

		if ( '' === $current ) {
			return $plan;
		}

		$lines = $by_site[ $current ] ?? array();
		$asked = empty( $meeting['off_support'] ) ? self::giving_back( $meeting, $lines ) : $meeting;

		return array_merge( $plan, self::sited( $current, MeetingHours::plan( $asked, $lines, $today, $term_ends_on, true ) ) );
	}

	/**
	 * Where a meeting stands, for its stored ledger state.
	 *
	 * @param array<string, mixed> $meeting      The meeting.
	 * @param string               $today        YYYY-MM-DD.
	 * @param string               $term_ends_on Last day of the term, or ''.
	 * @return string
	 */
	public static function state_for( array $meeting, string $today, string $term_ends_on ): string {
		if ( '' === (string) ( $meeting['client_site_id'] ?? '' ) || empty( $meeting['off_support'] ) ) {
			return MeetingHours::RELEASED;
		}

		return MeetingHours::state_of( $meeting, $today, $term_ends_on, true );
	}

	/**
	 * Whether a meeting may still have its client or support choice changed:
	 * not once it has spent a client's hours.
	 *
	 * @param array<string, mixed>             $meeting     The meeting.
	 * @param array<int, array<string, mixed>> $entries     Its ledger entries.
	 * @param string                           $site_id     The site asked for.
	 * @param bool                             $off_support The support choice asked for.
	 * @return bool
	 */
	public static function may_change( array $meeting, array $entries, string $site_id, bool $off_support ): bool {
		if ( MeetingHours::position( $entries )['used'] <= 0 ) {
			return true;
		}

		return (string) ( $meeting['client_site_id'] ?? '' ) === $site_id && ! empty( $meeting['off_support'] ) === $off_support;
	}

	/**
	 * Brings one meeting's ledger into line.
	 *
	 * @param array<string, mixed> $meeting Hydrated.
	 * @param int                  $actor   Who caused this.
	 * @return int How many entries were written.
	 */
	public static function settle_one( array $meeting, int $actor ): int {
		$id = (string) ( $meeting['id'] ?? '' );

		if ( '' === $id || $actor <= 0 ) {
			return 0;
		}

		$today   = gmdate( 'Y-m-d' );
		$site    = (string) ( $meeting['client_site_id'] ?? '' );
		$term    = '' === $site ? '' : (string) ( Assignments::entitlement_on( $site, $today )['term_ends_on'] ?? '' );
		$written = 0;

		foreach ( self::plan_for( $meeting, self::entries_for( $id ), $today, $term ) as $entry ) {
			$appended = Ledger::append(
				array(
					'client_site_id' => $entry['client_site_id'],
					'event_type'     => $entry['event_type'],
					'hours'          => $entry['hours'],
					'source_type'    => self::SOURCE,
					'source_id'      => $id,
					'reason'         => self::name( $meeting ),
					'actor'          => $actor,
				)
			);

			// Refused: the site has not the hours. Tried again next time, as
			// a standing meeting is.
			if ( null === $appended ) {
				return $written;
			}

			++$written;
		}

		Meetings::set_ledger_state( $id, self::state_for( $meeting, $today, $term ) );

		return $written;
	}

	/**
	 * Gives back everything a meeting holds, before it is removed. Spend
	 * stays spent.
	 *
	 * @param array<string, mixed> $meeting Hydrated.
	 */
	public static function release( array $meeting ): void {
		$actor = get_current_user_id();

		if ( $actor <= 0 ) {
			return;
		}

		self::settle_one( array_merge( $meeting, array( 'client_site_id' => '' ) ), $actor );
	}

	/**
	 * Every settled meeting whose hours might need moving.
	 *
	 * @param int $actor Who is looking.
	 * @return int How many entries were written.
	 */
	public static function reconcile( int $actor ): int {
		if ( $actor <= 0 ) {
			return 0;
		}

		$today   = gmdate( 'Y-m-d' );
		$until   = MeetingHours::horizon_end( $today );
		$slots   = Matching::standing_slots( $today, $until );
		$written = 0;

		foreach ( Meetings::worth_asking( $today, $until ) as $meeting ) {
			$written += self::settle_one( self::as_held( $meeting, $slots ), $actor );
		}

		return $written;
	}

	/**
	 * A meeting as its hours are settled: one a standing meeting already
	 * stands for holds nothing, even with a client Forge filled in, or the
	 * client's hours would be set aside twice (#657). Pure.
	 *
	 * @param array<string, mixed>             $meeting Hydrated.
	 * @param array<int, array<string, mixed>> $slots   From {@see Matching::standing_slots()}.
	 * @return array<string, mixed>
	 */
	public static function as_held( array $meeting, array $slots ): array {
		return Matching::skipped( $meeting, $slots ) ? array_merge( $meeting, array( 'client_site_id' => '' ) ) : $meeting;
	}

	/**
	 * One meeting's ledger entries.
	 *
	 * @param string $id The meeting.
	 * @return array<int, array<string, mixed>>
	 */
	public static function entries_for( string $id ): array {
		return Ledger::for_source( self::SOURCE, $id );
	}

	/**
	 * What a ledger line says it was for.
	 *
	 * @param array<string, mixed> $meeting The meeting.
	 * @return string
	 */
	public static function name( array $meeting ): string {
		return sprintf(
			/* translators: 1: the meeting's title, 2: its date. */
			__( '%1$s on %2$s', 'blueworx-forge' ),
			(string) ( $meeting['title'] ?? '' ),
			(string) ( $meeting['on'] ?? '' )
		);
	}

	/**
	 * The meeting asked as though it were called off, with enough hours on
	 * it that the plan does not skip it: whatever it holds goes back, and it
	 * spends nothing new.
	 *
	 * @param array<string, mixed>             $meeting The meeting.
	 * @param array<int, array<string, mixed>> $lines   One site's entries.
	 * @return array<string, mixed>
	 */
	private static function giving_back( array $meeting, array $lines ): array {
		$held = MeetingHours::position( $lines )['reserved'];

		return array_merge(
			$meeting,
			array(
				'status'        => Occurrence::CANCELLED,
				'planned_hours' => max( (float) ( $meeting['planned_hours'] ?? 0 ), $held, 0.01 ),
			)
		);
	}

	/**
	 * Planned entries, each naming its site.
	 *
	 * @param string                                              $site The site.
	 * @param array<int, array{event_type: string, hours: float}> $plan From MeetingHours::plan().
	 * @return array<int, array{client_site_id: string, event_type: string, hours: float}>
	 */
	private static function sited( string $site, array $plan ): array {
		return array_map(
			static fn( array $entry ): array => array(
				'client_site_id' => $site,
				'event_type'     => (string) $entry['event_type'],
				'hours'          => (float) $entry['hours'],
			),
			$plan
		);
	}
}
