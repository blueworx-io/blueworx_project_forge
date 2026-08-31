<?php
/**
 * What has happened lately that this person is entitled to know about.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Signals;

use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Reach;
use Blueworx\Forge\Work\Events;
use Blueworx\Forge\Work\Items;
use Blueworx\Forge\Work\Submissions;

/**
 * #175. Nothing is stored, and that is what makes the scoping honest.
 *
 * The criterion is "each event notifies exactly the users entitled to see the
 * underlying record", and the word doing the work is *entitled* — a present
 * tense. Anything fanned out to recipients at the moment it happened freezes
 * that entitlement: somebody added to a client on Friday would never see
 * Thursday, and somebody removed would keep seeing everything they were sent.
 * Both are wrong and neither shows up until it matters.
 *
 * So a signal is not a record. The list is worked out each time from the
 * records the reader may see *now*, and the only thing kept per person is how
 * far they had got ({@see Seen}). Lose reach over a client and its signals
 * vanish from your list, including yesterday's, because they were never yours
 * — they were only ever the record's, shown to whoever could read it.
 *
 * **Reach is applied first and on its own,** the same order Standup and the
 * request queue use. The scoping is the only thing between one person's list
 * and every client's work, so it filters the records, and everything else runs
 * over what survived.
 */
final class Inbox {

	/**
	 * How far back a signal is still a signal, in seconds.
	 *
	 * A fortnight. Long enough that a week off does not lose anything, short
	 * enough that the list is about now — a signal from March is a history
	 * entry, and the item's own history is where somebody would look for it.
	 */
	public const WINDOW_SECONDS = 14 * 86400;

	/**
	 * How many at most.
	 *
	 * A ceiling rather than a page. Nobody works down a hundred of these; past
	 * that point the honest thing is to say there were more rather than to
	 * offer to load them, and the board is where somebody goes instead.
	 */
	public const LIMIT = 100;

	/**
	 * The signals for whoever is asking, newest first.
	 *
	 * @param array<string, mixed> $reach   The caller's reach.
	 * @param int                  $user_id Who is asking.
	 * @param int                  $now     Unix time.
	 * @param int                  $seen_at When this person last looked.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_reach( array $reach, int $user_id, int $now, int $seen_at = 0 ): array {
		if ( Reach::is_nothing( $reach ) ) {
			return array();
		}

		$since  = $now - self::WINDOW_SECONDS;
		$sites  = Reach::keep_sites( $reach, ClientSites::all( null ), 'id' );
		$signal = array_merge(
			self::from_work( array_column( $sites, 'id' ), $user_id, $since ),
			self::from_requests( $reach, $since )
		);

		usort(
			$signal,
			static fn( array $a, array $b ): int => array( (int) $b['at'], (string) $b['id'] ) <=> array( (int) $a['at'], (string) $a['id'] )
		);

		$signal = array_slice( $signal, 0, self::LIMIT );

		foreach ( $signal as $at => $one ) {
			$signal[ $at ]['unread'] = (int) $one['at'] > $seen_at;
		}

		return $signal;
	}

	/**
	 * How many of those this person has not seen.
	 *
	 * Counted over the answer rather than asked of the database separately, so
	 * the number on the bell and the rows behind it can never disagree — a
	 * count from its own query is a count that is right until the two run a
	 * second apart.
	 *
	 * @param array<int, array<string, mixed>> $signals The signals.
	 * @return int
	 */
	public static function unread( array $signals ): int {
		return count( array_filter( $signals, static fn( array $one ): bool => (bool) $one['unread'] ) );
	}

	/**
	 * Things that happened to work.
	 *
	 * @param array<int, string> $site_ids Sites in reach.
	 * @param int                $user_id  Who is asking.
	 * @param int                $since    Unix time; nothing older.
	 * @return array<int, array<string, mixed>>
	 */
	private static function from_work( array $site_ids, int $user_id, int $since ): array {
		$events = Events::recent_for_sites( $site_ids, Kinds::WORTH_SAYING, $since, self::LIMIT );

		/*
		 * Your own doing is not news to you. Left out here rather than dimmed on
		 * the screen, because a list where most rows are things you did an hour
		 * ago is a list you stop scanning — and the one row that was somebody
		 * else goes past with the rest.
		 */
		$events = array_values(
			array_filter( $events, static fn( array $one ): bool => (int) $one['actor'] !== $user_id )
		);

		$items = Items::summaries_for( array_column( $events, 'item_id' ) );
		$rows  = array();

		foreach ( $events as $event ) {
			$item = $items[ (string) $event['item_id'] ] ?? array();

			// An event whose item has gone is not shown. It cannot be opened, it
			// cannot be named, and a row saying only that something happened is
			// worse than no row.
			if ( array() === $item ) {
				continue;
			}

			$rows[] = array(
				'id'             => (string) $event['id'],
				'kind'           => Kinds::WORK,
				'action'         => (string) $event['action'],
				'subject_id'     => (string) $event['item_id'],
				'title'          => (string) $item['title'],
				'client_id'      => (string) $item['client_id'],
				'client_site_id' => (string) $item['client_site_id'],
				'at'             => (int) $event['occurred_at'],
				'actor'          => (int) $event['actor'],

				// What the rule chose to carry, and nothing worked out here. A
				// screen that decided for itself what "moved" meant would be a
				// second reading of the workflow.
				'detail'         => self::detail( $event ),
				'governance'     => Kinds::is_governance( (string) $event['action'] ),
			);
		}

		return $rows;
	}

	/**
	 * Clients asking us for things.
	 *
	 * Here rather than left to the request queue because a request arriving is
	 * the one signal in the product that starts a clock somebody else is
	 * watching — the client's. The queue is where it gets answered; this is what
	 * stops it sitting there unnoticed until they ring.
	 *
	 * @param array<string, mixed> $reach The caller's reach.
	 * @param int                  $since Unix time; nothing older.
	 * @return array<int, array<string, mixed>>
	 */
	private static function from_requests( array $reach, int $since ): array {
		$rows = array();

		foreach ( Reach::keep_sites( $reach, Submissions::all() ) as $submission ) {
			if ( (int) $submission['created_at'] < $since ) {
				continue;
			}

			$rows[] = array(
				'id'             => 'sub:' . (string) $submission['id'],
				'kind'           => Kinds::REQUEST,
				'action'         => 'requested',
				'subject_id'     => (string) $submission['id'],
				'title'          => (string) $submission['title'],
				'client_id'      => (string) $submission['client_id'],
				'client_site_id' => (string) $submission['client_site_id'],
				'at'             => (int) $submission['created_at'],

				/*
				 * Nobody in the studio, which is the point of the field here.
				 * A request comes from the client's side, so there is no user id
				 * to leave it out of somebody's own list — and it should never
				 * be left out of anybody's.
				 */
				'actor'          => 0,
				'detail'         => (string) $submission['submitted_by'],
				'governance'     => false,
			);
		}

		return $rows;
	}

	/**
	 * The one useful sentence about a work event.
	 *
	 * @param array<string, mixed> $event The event.
	 * @return string
	 */
	private static function detail( array $event ): string {
		$reason = (string) $event['reason'];

		if ( '' !== $reason ) {
			return $reason;
		}

		$to = (string) $event['to_stage'];

		return '' === $to ? '' : $to;
	}
}
