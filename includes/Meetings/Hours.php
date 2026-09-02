<?php
/**
 * Putting a meeting's hours into the ledger, and keeping them there.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Meetings;

use Blueworx\Forge\Commerce\Assignments;
use Blueworx\Forge\Commerce\Ledger;

/**
 * #154, MEET-4 and MEET-5. The writing half of {@see MeetingHours}.
 *
 * Every rule is in the pure class. What is here is the reads it needs, the
 * entries it produces, and the one decision that could not be made without a
 * database: **which meetings are worth asking about at all.**
 *
 * That question has two halves, and missing either one loses a client's hours:
 *
 * - Everything coming up inside the reservation horizon, so hours are held for
 *   what is actually about to happen.
 * - Everything still *holding* hours, wherever it sits in time. A meeting that
 *   has been and gone unheld keeps its reservation until something writes the
 *   release, and a sweep that only looked forwards would never find it. That is
 *   how a client ends up with hours committed to a meeting last March.
 *
 * **There is no scheduled job here.** The studio plugin runs no cron, and a
 * balance that depends on one having fired is wrong for exactly as long as it
 * did not. So this runs when somebody looks or acts, and it is idempotent — a
 * site whose ledger already agrees with its meetings produces no entries at all.
 */
final class Hours {

	/**
	 * Brings a site's meeting hours into line with its meetings.
	 *
	 * @param string $client_site_id The site.
	 * @param int    $actor          Who is looking, or acting.
	 * @return int How many entries were written.
	 */
	public static function reconcile_site( string $client_site_id, int $actor ): int {
		if ( '' === $client_site_id || $actor <= 0 ) {
			return 0;
		}

		$today   = gmdate( 'Y-m-d' );
		$term    = Assignments::entitlement_on( $client_site_id, $today );
		$written = 0;

		foreach ( self::worth_asking( $client_site_id, $today ) as $found ) {
			$written += self::settle( $found['series'], $found['meeting'], (string) $term['term_ends_on'], $today, $actor );
		}

		return $written;
	}

	/**
	 * Brings one meeting into line, materialising its row if it needs one.
	 *
	 * @param array<string, mixed> $series       The series it belongs to.
	 * @param array<string, mixed> $meeting      One merged occurrence.
	 * @param string               $term_ends_on Last day of the active term, or ''.
	 * @param string               $today        YYYY-MM-DD.
	 * @param int                  $actor        Who caused this.
	 * @return int How many entries were written.
	 */
	public static function settle( array $series, array $meeting, string $term_ends_on, string $today, int $actor ): int {
		/*
		 * A stopped series holds nothing. Its meetings are not going to happen,
		 * so whatever they were holding goes back — which is why the series'
		 * state travels with the meeting rather than being assumed.
		 */
		$running = Series::ACTIVE === (string) ( $series['state'] ?? '' );
		$state   = MeetingHours::state_of( $meeting, $today, $term_ends_on, $running );
		$id      = (string) ( $meeting['id'] ?? '' );

		/*
		 * A meeting only earns a row when it costs something. A forecast writes
		 * nothing and needs nothing written about it, so a client with a weekly
		 * meeting for the next three years does not get a hundred and fifty
		 * rows the day they sign.
		 */
		if ( '' === $id ) {
			if ( MeetingHours::RESERVED !== $state ) {
				return 0;
			}

			$stored = Diary::materialise( $series, $meeting, $actor );

			if ( null === $stored ) {
				return 0;
			}

			$id      = (string) $stored['id'];
			$meeting = array_merge( $meeting, array( 'id' => $id ) );
		}

		$plan    = MeetingHours::plan( $meeting, self::entries_for( $id ), $today, $term_ends_on, $running );
		$written = 0;

		foreach ( $plan as $entry ) {
			$appended = Ledger::append(
				array(
					'client_site_id' => (string) ( $series['client_site_id'] ?? '' ),
					'event_type'     => (string) $entry['event_type'],
					'hours'          => (float) $entry['hours'],
					'source_type'    => MeetingHours::SOURCE,
					'source_id'      => $id,
					'actor'          => $actor,
				)
			);

			if ( null === $appended ) {
				/*
				 * Refused, which for a meeting means the site has not the hours
				 * to hold it. MEET-5 says an insufficient balance is raised
				 * rather than overdrawn silently, and the meeting stays where it
				 * is with its ledger state unchanged — so the next look tries
				 * again, and the shortfall shows up as a meeting that never
				 * managed to reserve.
				 */
				return $written;
			}

			++$written;
		}

		Diary::set_ledger_state( $id, $state );

		return $written;
	}

	/**
	 * Every meeting on a site whose hours might need moving.
	 *
	 * @param string $client_site_id The site.
	 * @param string $today          YYYY-MM-DD.
	 * @return array<int, array{series: array<string, mixed>, meeting: array<string, mixed>}>
	 */
	private static function worth_asking( string $client_site_id, string $today ): array {
		$series_by_id = array();

		foreach ( Series::for_site( $client_site_id ) as $series ) {
			$series_by_id[ (string) $series['id'] ] = $series;
		}

		$found = array();
		$seen  = array();

		// What is coming up, from the rules and their exceptions together.
		foreach ( $series_by_id as $series ) {
			foreach ( Diary::for_series( $series, $today, MeetingHours::horizon_end( $today ) ) as $meeting ) {
				$key          = (string) $series['id'] . '|' . (string) ( $meeting['excepted_from'] ?? $meeting['on'] );
				$seen[ $key ] = true;
				$found[]      = array(
					'series'  => $series,
					'meeting' => $meeting,
				);
			}
		}

		// And anything still holding hours, wherever it is. An ended series'
		// meetings are in here too: ending a series must not strand the hours
		// its remaining meetings were holding.
		foreach ( Diary::holding( $client_site_id ) as $meeting ) {
			$series_id = (string) $meeting['series_id'];
			$key       = $series_id . '|' . (string) $meeting['excepted_from'];

			if ( isset( $seen[ $key ] ) || ! isset( $series_by_id[ $series_id ] ) ) {
				continue;
			}

			$found[] = array(
				'series'  => $series_by_id[ $series_id ],
				'meeting' => $meeting,
			);
		}

		return $found;
	}

	/**
	 * One meeting's ledger entries.
	 *
	 * @param string $occurrence_id The occurrence.
	 * @return array<int, array<string, mixed>>
	 */
	private static function entries_for( string $occurrence_id ): array {
		return Ledger::for_source( MeetingHours::SOURCE, $occurrence_id );
	}
}
