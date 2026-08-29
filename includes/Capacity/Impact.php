<?php
/**
 * Whether a move would over-book anybody.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Capacity;

use Blueworx\Forge\Tenancy\Users;

/**
 * CAP-E1: the one place that answers "would this over-book anybody?" (#141).
 *
 * Everything that refuses on capacity asks this — the gate at Up Next, the
 * recheck at In Development, and the screen that shows why. That is the point
 * of it being one class rather than three call sites: the gate and the Capacity
 * screen have to agree about the same person in the same week, and three
 * implementations of the same arithmetic do not stay agreed.
 *
 * Two things make it more than a call to Position.
 *
 * **The item is not counted yet.** Allocations::COMMITTING begins at Up Next,
 * so an item on its way there commits nothing and Position would report a world
 * without it. So the item's own allocations are always added on top, and always
 * removed from the existing set first — because an item entering In Development
 * is already at Up Next and already in that set, and counted twice a job that
 * exactly fills a week would refuse itself.
 *
 * **Week by week (CAP-E2).** Any single week that tips somebody over counts. A
 * total across the whole job hides the two-month piece of work that is
 * comfortable on average and leaves somebody with nothing free next week —
 * which is the case worth catching, and the one the Capacity screen already
 * shows in red.
 */
final class Impact {

	/**
	 * Whether a move leaves everybody inside their hours.
	 *
	 * @param array<string, mixed> $impact An assessment.
	 * @return bool
	 */
	public static function clear( array $impact ): bool {
		return array() === (array) ( $impact['over'] ?? array() );
	}

	/**
	 * What this item would do to the people in its seats.
	 *
	 * @param array<string, mixed> $item A hydrated work item.
	 * @return array{over: array<int, array<string, mixed>>, window: array{from: string, to: string}}
	 */
	public static function of( array $item ): array {
		$proposed = Allocations::proposed( $item );

		if ( array() === $proposed ) {
			return self::nothing();
		}

		$from = (string) $proposed[0]['from'];
		$to   = (string) $proposed[0]['to'];

		$user_ids = array_values( array_unique( array_column( $proposed, 'user_id' ) ) );

		return self::named(
			self::assess(
				$proposed,
				Commitments::live( $from, $to ),
				Availability::for_people( $user_ids, $from, $to ),
				$from,
				$to
			)
		);
	}

	/**
	 * The same assessment with each person's name on it.
	 *
	 * Added here rather than left to the screen. A refusal that says an id is
	 * over-booked is a refusal nobody can act on, and every screen that had to
	 * look the name up separately would be one more place to get it wrong.
	 *
	 * @param array<string, mixed> $impact An assessment.
	 * @return array{over: array<int, array<string, mixed>>, window: array{from: string, to: string}}
	 */
	private static function named( array $impact ): array {
		foreach ( $impact['over'] as $index => $entry ) {
			$person = Users::get( (string) $entry['user_id'] );

			$impact['over'][ $index ]['display_name'] = null === $person
				? (string) $entry['user_id']
				: (string) $person['display_name'];
		}

		return $impact;
	}

	/**
	 * The same answer from figures already in hand.
	 *
	 * No database in it, so every rule above can be stated in a test rather
	 * than inferred from a site.
	 *
	 * @param array<int, array<string, mixed>>                $proposed     What this item would commit.
	 * @param array<int, array<string, mixed>>                $existing     Every live allocation, this item's included.
	 * @param array<string, array<int, array<string, mixed>>> $days_by_user Availability::by_day per person.
	 * @param string                                          $from         YYYY-MM-DD, inclusive.
	 * @param string                                          $to           YYYY-MM-DD, inclusive.
	 * @return array{over: array<int, array<string, mixed>>, window: array{from: string, to: string}}
	 */
	public static function assess( array $proposed, array $existing, array $days_by_user, string $from, string $to ): array {
		if ( array() === $proposed || '' === $from || '' === $to ) {
			// No seats filled, or no dates to weigh them over. Both are their
			// own unmet requirement at Up Next, with their own message.
			return self::nothing();
		}

		$item_id = (string) ( $proposed[0]['item_id'] ?? '' );

		/*
		 * This item's own allocations, wherever they already are. Removed and
		 * then added back, so the same code path serves a move into Up Next
		 * (not there yet) and one into In Development (there already).
		 */
		$others = array_values(
			array_filter(
				$existing,
				static function ( array $allocation ) use ( $item_id ): bool {
					return (string) ( $allocation['item_id'] ?? '' ) !== $item_id;
				}
			)
		);

		$committed = Commitments::gather( array_merge( $others, $proposed ), $days_by_user );
		$weeks     = Periods::weeks( $from, $to );
		$over      = array();
		$seen      = array();

		foreach ( $proposed as $allocation ) {
			$user_id = (string) $allocation['user_id'];

			// An item can put the same person in two seats. Their week is over
			// once, not twice, and two rows would read as two problems.
			if ( isset( $seen[ $user_id ] ) ) {
				continue;
			}

			$seen[ $user_id ] = true;
			$days             = (array) ( $days_by_user[ $user_id ] ?? array() );
			$by_day           = (array) ( $committed[ $user_id ]['by_day'] ?? array() );

			foreach ( $weeks as $week ) {
				$position = Position::over( $days, $by_day, $week['from'], $week['to'] );

				if ( Position::OVER !== $position['band'] ) {
					continue;
				}

				$over[] = array(
					'user_id'   => $user_id,
					'week_from' => $week['from'],
					'week_to'   => $week['to'],
					'available' => $position['available'],
					'committed' => $position['committed'],
					'excess'    => round( $position['committed'] - $position['available'], 2 ),
				);
			}
		}

		return array(
			'over'   => $over,
			'window' => array(
				'from' => $from,
				'to'   => $to,
			),
		);
	}

	/**
	 * No conclusion, which is not the same as a pass — it is a question that
	 * could not be asked, and something else is already refusing the move for
	 * the reason it could not.
	 *
	 * @return array{over: array<int, array<string, mixed>>, window: array{from: string, to: string}}
	 */
	private static function nothing(): array {
		return array(
			'over'   => array(),
			'window' => array(
				'from' => '',
				'to'   => '',
			),
		);
	}
}
