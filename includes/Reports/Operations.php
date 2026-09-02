<?php
/**
 * The numbers about running the studio, as against delivering work.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Reports;

use Blueworx\Forge\Commerce\Entries;
use Blueworx\Forge\Onboarding\Progress;
use Blueworx\Forge\Work\Events;

/**
 * #261. The six reports #176 listed and did not build.
 *
 * Pure, like {@see Delivery} and for the same reason: everything is handed in,
 * so each figure can be argued with in a test rather than against a site, and
 * {@see Source} stays the only thing that reads.
 *
 * **Nothing here is stored either.** A report is the records, counted, now —
 * which is what makes "each reconciles to the records behind it" true by
 * construction rather than something a test has to keep proving. The moment one
 * of these became a running total kept alongside the records, it would start
 * drifting from them and nobody would find out except by acting on the wrong
 * one.
 *
 * Six different questions, and they are deliberately not one dashboard object:
 * whether people are over-booked, how often we overrode our own rules, where
 * clients' hours went, which sites are stuck getting live, what happens to what
 * clients ask for, and whether our email is arriving.
 */
final class Operations {

	/**
	 * Every operational report, from the records behind them.
	 *
	 * @param array<string, mixed> $data allocations, availability, items,
	 *                                   events, ledger, onboarding,
	 *                                   submissions, notifications.
	 * @return array<string, mixed>
	 */
	public static function compute( array $data ): array {
		return array(
			'capacity_utilisation' => self::capacity( (array) ( $data['capacity'] ?? array() ) ),
			'overrides'            => self::overrides( (array) ( $data['items'] ?? array() ), (array) ( $data['events'] ?? array() ) ),
			'hours'                => self::hours( (array) ( $data['ledger'] ?? array() ) ),
			'onboarding_readiness' => self::onboarding( (array) ( $data['onboarding'] ?? array() ) ),
			'request_funnel'       => self::funnel( (array) ( $data['submissions'] ?? array() ) ),
			'email_delivery'       => self::email( (array) ( $data['notifications'] ?? array() ) ),
		);
	}

	// ---- Whether people have room ----------------------------------------

	/**
	 * How much of everybody's time is spoken for.
	 *
	 * A share as well as the hours, because "forty hours committed" means
	 * nothing without knowing whether the week holds thirty-five or fifty. The
	 * count of people over their hours is the figure anybody actually acts on.
	 *
	 * @param array<int, array<string, mixed>> $people Each with committed and available.
	 * @return array<string, mixed>
	 */
	private static function capacity( array $people ): array {
		$committed = 0.0;
		$available = 0.0;
		$over      = 0;

		foreach ( $people as $person ) {
			$has  = round( (float) ( $person['available'] ?? 0 ), 2 );
			$owes = round( (float) ( $person['committed'] ?? 0 ), 2 );

			$committed += $owes;
			$available += $has;

			/*
			 * Somebody with no hours set up is not over-booked, they are not
			 * set up. Counting them would put every new person on the list the
			 * day they are created, and a list that is always wrong is a list
			 * nobody looks at.
			 */
			if ( $has > 0 && $owes > $has ) {
				++$over;
			}
		}

		return array(
			'people'    => count( $people ),
			'committed' => round( $committed, 2 ),
			'available' => round( $available, 2 ),
			'share'     => $available > 0 ? round( $committed / $available, 3 ) : null,
			'over'      => $over,
		);
	}

	// ---- How often we overrode ourselves ---------------------------------

	/**
	 * How often the rules were gone past, and on what.
	 *
	 * Two different overrides that get confused constantly. A workflow override
	 * is a gate somebody went round; a capacity override is a week somebody
	 * agreed to overfill. CAP-E3 keeps them as separate marks precisely because
	 * they answer different questions, and a report that added them together
	 * would undo that.
	 *
	 * @param array<int, array<string, mixed>> $items  Work in reach.
	 * @param array<int, array<string, mixed>> $events The changelog for it.
	 * @return array<string, mixed>
	 */
	private static function overrides( array $items, array $events ): array {
		$workflow = 0;
		$capacity = 0;

		foreach ( $items as $item ) {
			if ( ! empty( $item['override_used'] ) ) {
				++$workflow;
			}

			if ( ! empty( $item['capacity_override_used'] ) ) {
				++$capacity;
			}
		}

		$occasions = 0;

		foreach ( $events as $event ) {
			if ( Events::OVER_ALLOCATED === (string) ( $event['action'] ?? '' ) ) {
				++$occasions;
			}
		}

		return array(
			'workflow'  => $workflow,
			'capacity'  => $capacity,

			/*
			 * The number of *times*, not the number of items. One job pushed
			 * through three times is three decisions somebody made, and the
			 * item count would show it as one.
			 */
			'occasions' => $occasions,
			'items'     => count( $items ),
		);
	}

	// ---- Where clients' hours went ---------------------------------------

	/**
	 * What has happened to the hours clients have bought.
	 *
	 * Work and meetings kept apart, because "we spent forty hours" is a
	 * different conversation from "thirty of those were meetings". Held hours
	 * are separate again: they are committed and not yet spent, and adding them
	 * to spend would say a client has used what they have only set aside.
	 *
	 * @param array<int, array<string, mixed>> $entries The ledger in reach.
	 * @return array<string, mixed>
	 */
	private static function hours( array $entries ): array {
		$totals = array(
			'granted'      => 0.0,
			'work_used'    => 0.0,
			'meeting_used' => 0.0,
			'work_held'    => 0.0,
			'meeting_held' => 0.0,
			'adjusted'     => 0.0,
		);

		foreach ( $entries as $entry ) {
			$hours = abs( (float) ( $entry['hours'] ?? 0 ) );

			switch ( (string) ( $entry['event_type'] ?? '' ) ) {
				case Entries::ALLOCATION:
				case Entries::TOP_UP:
					$totals['granted'] += $hours;
					break;

				case Entries::WORK_USAGE:
					$totals['work_used'] += $hours;
					break;

				case Entries::MEETING_USAGE:
					$totals['meeting_used'] += $hours;
					break;

				case Entries::WORK_RESERVATION:
					$totals['work_held'] += $hours;
					break;

				case Entries::WORK_RELEASE:
					$totals['work_held'] -= $hours;
					break;

				case Entries::MEETING_RESERVATION:
					$totals['meeting_held'] += $hours;
					break;

				case Entries::MEETING_RELEASE:
					$totals['meeting_held'] -= $hours;
					break;

				case Entries::ADJUSTMENT:
					// Signed, because an adjustment is the one that goes both
					// ways and the direction is the whole point of reporting it.
					$totals['adjusted'] += (float) $entry['hours'];
					break;
			}
		}

		foreach ( $totals as $key => $value ) {
			$totals[ $key ] = round( $value, 2 );
		}

		$totals['spent'] = round( $totals['work_used'] + $totals['meeting_used'], 2 );
		$totals['held']  = round( $totals['work_held'] + $totals['meeting_held'], 2 );

		return $totals;
	}

	// ---- Which sites are stuck getting live ------------------------------

	/**
	 * How far along each site's onboarding is.
	 *
	 * The count of sites yet to go live is the number somebody acts on; the
	 * median share is what says whether they are nearly there or have not
	 * started.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $by_site Steps, keyed by site.
	 * @return array<string, mixed>
	 */
	private static function onboarding( array $by_site ): array {
		$shares = array();
		$ready  = 0;

		foreach ( $by_site as $steps ) {
			$progress = Progress::of( $steps );

			$shares[] = (float) ( $progress['completion'] ?? 0 );

			/*
			 * Launch-ready is Progress's own answer, not "completion is one".
			 * ONB-1 counts completion over required steps and readiness over
			 * launch-critical ones, and a site can be at a hundred per cent
			 * with a critical step outstanding. Reading it off the percentage
			 * would declare that site ready.
			 */
			if ( ! empty( $progress['launch_ready'] ) ) {
				++$ready;
			}
		}

		sort( $shares );

		return array(
			'sites'     => count( $shares ),
			'ready'     => $ready,
			'not_ready' => count( $shares ) - $ready,
			'median'    => self::median( $shares ),
		);
	}

	// ---- What happens to what clients ask for ----------------------------

	/**
	 * The request funnel: what came in, and what became of it.
	 *
	 * Every state present whether or not anything is in it, for the reason the
	 * stage distribution says: a state that disappears when it empties reads as
	 * "not applicable" rather than "none", and the two look identical.
	 *
	 * @param array<int, array<string, mixed>> $submissions Requests in reach.
	 * @return array<string, mixed>
	 */
	private static function funnel( array $submissions ): array {
		$states = array(
			'received'  => 0,
			'in-review' => 0,
			'accepted'  => 0,
			'declined'  => 0,
			'converted' => 0,
		);

		$kinds = array();

		foreach ( $submissions as $submission ) {
			$state = (string) ( $submission['intake_state'] ?? '' );
			$kind  = (string) ( $submission['type'] ?? '' );

			if ( array_key_exists( $state, $states ) ) {
				++$states[ $state ];
			}

			$kinds[ $kind ] = ( $kinds[ $kind ] ?? 0 ) + 1;
		}

		ksort( $kinds );

		return array(
			'total'  => count( $submissions ),
			'states' => $states,
			'kinds'  => $kinds,
		);
	}

	// ---- Whether our email is arriving -----------------------------------

	/**
	 * What became of the notifications we raised.
	 *
	 * The share that arrived is the figure worth watching, and it is null
	 * rather than one when nothing was sent — a hundred per cent of nothing is
	 * the most reassuring wrong number a screen can show.
	 *
	 * @param array<int, array<string, mixed>> $events Notification events in reach.
	 * @return array<string, mixed>
	 */
	private static function email( array $events ): array {
		$outcomes = array();

		foreach ( $events as $event ) {
			$outcome              = (string) ( $event['outcome'] ?? '' );
			$outcomes[ $outcome ] = ( $outcomes[ $outcome ] ?? 0 ) + 1;
		}

		ksort( $outcomes );

		$total     = count( $events );
		$delivered = (int) ( $outcomes['sent'] ?? 0 );

		return array(
			'total'     => $total,
			'outcomes'  => $outcomes,
			'delivered' => $delivered,
			'failed'    => (int) ( $outcomes['failed'] ?? 0 ),
			'share'     => $total > 0 ? round( $delivered / $total, 3 ) : null,
		);
	}

	/**
	 * The middle of a sorted list, or null when there is nothing in it.
	 *
	 * @param array<int, float> $sorted Values, ascending.
	 * @return float|null
	 */
	private static function median( array $sorted ): ?float {
		$count = count( $sorted );

		if ( 0 === $count ) {
			return null;
		}

		$middle = intdiv( $count, 2 );

		if ( 0 !== $count % 2 ) {
			return round( $sorted[ $middle ], 3 );
		}

		return round( ( $sorted[ $middle - 1 ] + $sorted[ $middle ] ) / 2, 3 );
	}
}
