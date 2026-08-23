<?php
/**
 * What the landing view picks out of a client's work.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * The two questions a client's dashboard answers (#127): what is coming, and
 * what has gone wrong.
 *
 * These are judgements rather than markup, so they live apart from the screen
 * that draws them. "Overdue" especially is a rule somebody will want to argue
 * with one day, and arguing with a tested function is far easier than arguing
 * with a condition buried inside a printf.
 *
 * Both lists are deliberately small. A dashboard that lists everything is the
 * board with worse layout; what earns a place here is what somebody would want
 * to know before deciding whether to look at the board at all.
 */
final class Digest {

	/**
	 * How many items either list shows.
	 */
	public const LIMIT = 5;

	/**
	 * The stages where the work is finished.
	 *
	 * Finished work is neither coming nor late. A dashboard that keeps
	 * reporting a job delivered three weeks after its date is one people learn
	 * to stop reading.
	 *
	 * @var array<int, string>
	 */
	private const FINISHED = array( 'completed', 'released' );

	/**
	 * The stage where nothing will happen until somebody acts.
	 */
	private const BLOCKED = 'blocked';

	/**
	 * What is coming, soonest first.
	 *
	 * @param array<int, array<string, mixed>> $items Board items.
	 * @param string                           $today YYYY-MM-DD.
	 * @return array<int, array<string, mixed>>
	 */
	public static function upcoming( array $items, string $today ): array {
		$coming = array();

		foreach ( $items as $item ) {
			$due = (string) ( $item['planned_due'] ?? '' );

			if ( '' === $due || self::is_finished( $item ) ) {
				continue;
			}

			// Due today still counts. A due date is a day, not a moment, and
			// dropping it at midnight takes today's work off the list at the
			// exact moment somebody is looking for it.
			if ( $due >= $today ) {
				$coming[] = $item;
			}
		}

		usort(
			$coming,
			static fn( array $a, array $b ): int => strcmp(
				(string) $a['planned_due'],
				(string) $b['planned_due']
			)
		);

		return array_slice( $coming, 0, self::LIMIT );
	}

	/**
	 * What has gone wrong, and why in words.
	 *
	 * One entry per item, never one per reason: a client counting problems
	 * should be counting things, not the number of ways one thing is unwell.
	 * Blocked outranks overdue because it is the more actionable of the two —
	 * a blocked item is waiting on somebody, and a late one may simply be late.
	 *
	 * @param array<int, array<string, mixed>> $items Board items.
	 * @param string                           $today YYYY-MM-DD.
	 * @return array<int, array<string, mixed>>
	 */
	public static function attention( array $items, string $today ): array {
		$wanting = array();

		foreach ( $items as $item ) {
			if ( self::is_finished( $item ) ) {
				continue;
			}

			$due    = (string) ( $item['planned_due'] ?? '' );
			$reason = '';

			if ( self::BLOCKED === (string) ( $item['stage'] ?? '' ) ) {
				$reason = 'blocked';
			} elseif ( '' !== $due && $due < $today ) {
				$reason = 'overdue';
			}

			if ( '' !== $reason ) {
				$wanting[] = array(
					'reason' => $reason,
					'item'   => $item,
				);
			}
		}

		return array_slice( $wanting, 0, self::LIMIT );
	}

	/**
	 * Whether the work is done.
	 *
	 * @param array<string, mixed> $item A board item.
	 * @return bool
	 */
	private static function is_finished( array $item ): bool {
		return in_array( (string) ( $item['stage'] ?? '' ), self::FINISHED, true );
	}
}
