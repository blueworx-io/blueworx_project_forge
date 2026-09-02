<?php
/**
 * What meetings take out of people's weeks.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Meetings;

/**
 * #155, MEET-1. A meeting, expressed as an ordinary capacity commitment.
 *
 * **A standing meeting is the easiest commitment in the studio to lose.** It
 * never appears on a board and nobody moves it through a workflow, so it is
 * exactly the kind of time that makes a week which looked comfortable turn out
 * not to be. Two hours a fortnight is a working day a quarter, and a capacity
 * figure that leaves it out is confidently wrong in the one direction that
 * costs: it says there is room.
 *
 * So a meeting is turned into the same shape work makes, and everything
 * downstream — the capacity screen, the gate at Up Next, the answer a client
 * gets about whether there is room — counts it without knowing what a meeting
 * is. A different shape here would mean teaching all three.
 *
 * **The host, and only the host.** MEET-1 gives the schedule to the site's
 * Point of Contact, and they are the one person on a meeting Forge holds a
 * record for. Attendees are a note rather than accounts, and inventing capacity
 * effects for people we have no record of is worse than counting nobody: the
 * figures would be wrong and there would be no way to find out whose.
 */
final class Load {

	/**
	 * What a meeting commitment is called where work says "primary".
	 */
	public const ROLE = 'meeting';

	/**
	 * The commitments a set of meetings makes.
	 *
	 * @param array<int, array<string, mixed>> $meetings       Merged occurrences.
	 * @param string                           $host_user_id   Who hosts them.
	 * @param string                           $client_site_id The site they belong to.
	 * @param string                           $client_id      The client.
	 * @return array<int, array<string, mixed>>
	 */
	public static function allocations( array $meetings, string $host_user_id, string $client_site_id, string $client_id ): array {
		if ( '' === $host_user_id ) {
			// An allocation against nobody would either be dropped downstream
			// or, worse, counted against whichever person an empty id matches.
			return array();
		}

		$out = array();

		foreach ( $meetings as $meeting ) {
			if ( ! self::takes_time( $meeting ) ) {
				continue;
			}

			$on = (string) $meeting['on'];

			$out[] = array(
				'item_id'        => (string) ( $meeting['id'] ?? '' ),
				'title'          => (string) ( $meeting['title'] ?? __( 'Support meeting', 'blueworx-forge' ) ),
				'client_id'      => $client_id,
				'client_site_id' => $client_site_id,
				'role'           => self::ROLE,
				'user_id'        => $host_user_id,
				'covering'       => '',
				'hours'          => round( (float) $meeting['planned_hours'], 2 ),

				/*
				 * One day, not a span. Work spreads across its planned dates
				 * because it is done over them; a meeting happens once, and
				 * spreading two hours across a fortnight would hide the
				 * afternoon it actually takes.
				 */
				'from'           => $on,
				'to'             => $on,
			);
		}

		return $out;
	}

	/**
	 * Every meeting commitment in a window, across every client.
	 *
	 * Two queries, whatever the number of series: the running series, and every
	 * stored exception touching the window. The expansion itself is arithmetic,
	 * so the cost of asking does not grow with how long clients have been with
	 * us — only with how many standing meetings there are.
	 *
	 * @param string $from YYYY-MM-DD, inclusive.
	 * @param string $to   YYYY-MM-DD, inclusive.
	 * @return array<int, array<string, mixed>>
	 */
	public static function across( string $from, string $to ): array {
		$series = Series::running_between( $from, $to );

		if ( array() === $series ) {
			return array();
		}

		$stored = Diary::stored_between( $from, $to );
		$out    = array();

		foreach ( $series as $one ) {
			$id       = (string) $one['id'];
			$meetings = Occurrence::merge(
				Series::occurrences( $one, $from, $to ),
				$stored[ $id ] ?? array(),
				$from,
				$to
			);

			$out = array_merge(
				$out,
				self::allocations(
					array_map(
						static function ( array $meeting ) use ( $one ): array {
							$meeting['title'] = (string) $one['title'];

							return $meeting;
						},
						$meetings
					),
					(string) $one['host_user_id'],
					(string) $one['client_site_id'],
					(string) $one['client_id']
				)
			);
		}

		return $out;
	}

	/**
	 * Whether a meeting takes anybody's time.
	 *
	 * A held meeting still does: it happened, and leaving it out would make the
	 * week it was in look freer in hindsight than it was — which is the week
	 * somebody is reporting on.
	 *
	 * @param array<string, mixed> $meeting One merged occurrence.
	 * @return bool
	 */
	private static function takes_time( array $meeting ): bool {
		$status = (string) ( $meeting['status'] ?? Occurrence::SCHEDULED );

		if ( Occurrence::CANCELLED === $status || Occurrence::NO_SHOW === $status ) {
			return false;
		}

		return '' !== (string) ( $meeting['on'] ?? '' ) && round( (float) ( $meeting['planned_hours'] ?? 0 ), 2 ) > 0;
	}
}
