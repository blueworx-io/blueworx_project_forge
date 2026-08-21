<?php
/**
 * What a parent reads as, given the work beneath it.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

/**
 * WORK-2, and #101: a parent reflects the work beneath it and cannot be talked
 * up by hand.
 *
 * **Nothing here is stored.** Progress, state and dates are computed from the
 * children each time they are read, so there is no column for anybody to write
 * and no edit path to refuse. That is a stronger guarantee than refusing the
 * write would be: a refusal can be forgotten on one endpoint, and a value that
 * does not exist cannot be set by any of them.
 *
 * The rule that decides everything else here is which children count. Work that
 * ended without being done — cancelled, rejected, a duplicate, deferred — is not
 * work anybody is still waiting for, and neither is work that has been archived.
 * Counting them would leave a parent held open forever by a child somebody
 * deliberately stopped, which is the obvious implementation and the wrong one.
 */
final class Derived {

	/**
	 * Nothing beneath it.
	 *
	 * Deliberately distinct from "not started" (#101 asks for this by name): one
	 * needs breaking down and the other needs doing, and a screen that shows
	 * them the same way sends somebody to do the wrong thing.
	 */
	public const EMPTY_PARENT = 'empty';

	/**
	 * Children, none of them under way.
	 */
	public const NOT_STARTED = 'not-started';

	/**
	 * At least one child past the beginning, and not all of them finished.
	 */
	public const IN_PROGRESS = 'in-progress';

	/**
	 * Every child that still counts is finished.
	 */
	public const COMPLETED = 'completed';

	/**
	 * The derived values, by the names they are returned under.
	 *
	 * Held as a list so the test that proves none of them is writable can walk
	 * it, rather than naming them again and drifting.
	 */
	public const NAMES = array( 'progress', 'derived_state', 'derived_start', 'derived_due' );

	/**
	 * The stage from which a child counts as under way. Anything before it is
	 * work nobody has begun.
	 */
	private const UNDER_WAY_FROM = 'documentation-period';

	/**
	 * The stage from which a child counts as finished.
	 */
	private const FINISHED_FROM = 'completed';

	/**
	 * What a parent reads as.
	 *
	 * @param array<int, array<string, mixed>> $children The work beneath it.
	 * @return array{progress: int, state: string, start: string, due: string}
	 */
	public static function from( array $children ): array {
		$counted = self::counted( $children );

		if ( array() === $counted ) {
			return array(
				'progress' => 0,
				'state'    => self::EMPTY_PARENT,
				'start'    => '',
				'due'      => '',
			);
		}

		$finished = 0;
		$begun    = 0;
		$starts   = array();
		$dues     = array();

		foreach ( $counted as $child ) {
			$stage = (string) ( $child['stage'] ?? '' );

			if ( self::is_finished( $stage ) ) {
				++$finished;
			}

			if ( Stages::position( $stage ) >= Stages::position( self::UNDER_WAY_FROM ) ) {
				++$begun;
			}

			$start = (string) ( $child['planned_start'] ?? '' );
			$due   = (string) ( $child['planned_due'] ?? '' );

			// An undated child contributes nothing rather than an empty string,
			// which would sort before every real date and make the parent start
			// at the beginning of time.
			if ( '' !== $start ) {
				$starts[] = $start;
			}

			if ( '' !== $due ) {
				$dues[] = $due;
			}
		}

		$total = count( $counted );

		return array(
			'progress' => (int) floor( ( $finished / $total ) * 100 ),
			'state'    => self::state( $total, $finished, $begun ),
			'start'    => array() === $starts ? '' : min( $starts ),
			'due'      => array() === $dues ? '' : max( $dues ),
		);
	}

	/**
	 * Whether a parent may be completed: only when everything beneath it is.
	 *
	 * A parent with no children is not held by this rule. It is an item in its
	 * own right, and the rule is about children it does not have.
	 *
	 * @param array<int, array<string, mixed>> $children The work beneath it.
	 * @return bool
	 */
	public static function may_complete( array $children ): bool {
		foreach ( self::counted( $children ) as $child ) {
			if ( ! self::is_finished( (string) ( $child['stage'] ?? '' ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The derived values under the names the API returns them by.
	 *
	 * @param array<int, array<string, mixed>> $children The work beneath it.
	 * @return array<string, mixed>
	 */
	public static function fields( array $children ): array {
		$derived = self::from( $children );

		return array(
			'progress'      => $derived['progress'],
			'derived_state' => $derived['state'],
			'derived_start' => $derived['start'],
			'derived_due'   => $derived['due'],
		);
	}

	/**
	 * The children that still count.
	 *
	 * @param array<int, array<string, mixed>> $children Every child.
	 * @return array<int, array<string, mixed>>
	 */
	private static function counted( array $children ): array {
		$counted = array();

		foreach ( $children as $child ) {
			if ( '' !== (string) ( $child['terminal_outcome'] ?? '' ) ) {
				continue;
			}

			if ( ! empty( $child['archived'] ) ) {
				continue;
			}

			$counted[] = $child;
		}

		return $counted;
	}

	/**
	 * Whether a stage means the work is done.
	 *
	 * @param string $stage The child's stage.
	 * @return bool
	 */
	private static function is_finished( string $stage ): bool {
		return Stages::exists( $stage )
			&& Stages::BLOCKED !== $stage
			&& Stages::position( $stage ) >= Stages::position( self::FINISHED_FROM );
	}

	/**
	 * Which state a tally adds up to.
	 *
	 * @param int $total    Children that count.
	 * @param int $finished How many are done.
	 * @param int $begun    How many are past the beginning.
	 * @return string
	 */
	private static function state( int $total, int $finished, int $begun ): string {
		if ( $finished === $total ) {
			return self::COMPLETED;
		}

		return 0 === $begun ? self::NOT_STARTED : self::IN_PROGRESS;
	}
}
