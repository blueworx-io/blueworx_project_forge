<?php
/**
 * What meetings from Google take out of people's weeks.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Google;

use Blueworx\Forge\Capacity\Allocations;
use Blueworx\Forge\Meetings\Load as MeetingLoad;
use Blueworx\Forge\Meetings\Occurrence;
use Blueworx\Forge\Tenancy\Studio;

/**
 * #606. A synced meeting, in the same shape {@see MeetingLoad} gives a
 * standing one, so capacity counts it without learning what Google is.
 *
 * It counts from the moment it arrives, before anybody settles it — under
 * the studio's own client until it has one. Each person whose calendar holds
 * it and who has not declined carries its hours; the client, once settled, is
 * charged once however many people were in the room.
 */
final class Load {

	/**
	 * The commitments a set of synced meetings makes.
	 *
	 * @param array<int, array<string, mixed>> $meetings      Hydrated.
	 * @param array<int, array<string, mixed>> $slots         Standing meetings, from {@see Matching::standing_slots()}.
	 * @param string                           $studio_client The studio's client id.
	 * @param string                           $studio_site   The studio's site id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function allocations( array $meetings, array $slots, string $studio_client, string $studio_site ): array {
		$out = array();

		foreach ( $meetings as $meeting ) {
			$status = (string) ( $meeting['status'] ?? Occurrence::SCHEDULED );
			$hours  = round( (float) ( $meeting['planned_hours'] ?? 0 ), 2 );

			if ( ! empty( $meeting['all_day'] ) || $hours <= 0 || Occurrence::CANCELLED === $status || Occurrence::NO_SHOW === $status ) {
				continue;
			}

			if ( Matching::skipped( $meeting, $slots ) ) {
				continue;
			}

			$site   = (string) ( $meeting['client_site_id'] ?? '' );
			$client = '' === $site ? $studio_client : (string) ( $meeting['client_id'] ?? '' );
			$site   = '' === $site ? $studio_site : $site;
			$done   = Occurrence::HELD === $status ? array( 'status' => Allocations::DONE ) : array();

			foreach ( (array) ( $meeting['counting'] ?? array() ) as $who ) {
				$out[] = $done + array(
					'item_id'        => (string) $meeting['id'],
					'title'          => (string) $meeting['title'],
					'client_id'      => $client,
					'client_site_id' => $site,
					'role'           => MeetingLoad::ROLE,
					'user_id'        => (string) $who,
					'covering'       => '',
					'hours'          => $hours,
					'from'           => (string) $meeting['on'],
					'to'             => (string) $meeting['on'],
				);
			}
		}

		return $out;
	}

	/**
	 * Every synced meeting's commitment in a window.
	 *
	 * @param string $from YYYY-MM-DD, inclusive.
	 * @param string $to   YYYY-MM-DD, inclusive.
	 * @return array<int, array<string, mixed>>
	 */
	public static function across( string $from, string $to ): array {
		$meetings = Meetings::between( $from, $to );

		if ( array() === $meetings ) {
			return array();
		}

		return self::allocations( $meetings, Matching::standing_slots( $from, $to ), Studio::client_id(), Studio::site_id() );
	}
}
