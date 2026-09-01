<?php
/**
 * Which things that happen are worth telling somebody in the product.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Signals;

use Blueworx\Forge\Work\Events;

/**
 * #175. The in-product signals that do not warrant an email.
 *
 * Two lists, and the second is the important one. Everything Forge records is a
 * candidate; almost none of it should reach a person. A signal list that
 * carries every field somebody edited is a list nobody reads, and once nobody
 * reads it the genuinely useful signal — your work came back, somebody
 * overrode a gate on your item — arrives in the same silence as the noise.
 *
 * So this file is mostly about what is *left out*, and each omission has a
 * reason written next to it. An action absent from {@see self::WORTH_SAYING} is
 * absent deliberately, and adding one is a decision about somebody's attention
 * rather than a completeness fix.
 *
 * Pure. It knows nothing about who is asking or what they may see — that is
 * {@see Inbox}'s job, and keeping the two apart is what lets "should this be a
 * signal at all" be argued with in a test.
 */
final class Kinds {

	/**
	 * Something happened to a piece of work.
	 */
	public const WORK = 'work_item';

	/**
	 * A client asked us for something.
	 */
	public const REQUEST = 'submission';

	/**
	 * The work events that are worth a person's attention.
	 *
	 * What is missing, and why:
	 *
	 * - `edited` and `corrected`. By volume these are most of the history, and
	 *   a signal saying somebody changed a date is a signal about a field
	 *   rather than about the work. The item's own history has them.
	 * - `created`. New work appearing is what the board is for, and a studio
	 *   creating fifty items in a planning session would bury everything else.
	 * - `notified`. That is the email machinery reporting on itself. When one
	 *   fails to go it is a real problem and it is already on the daily list
	 *   (#174); repeating it here would put it in front of the same person
	 *   twice.
	 * - `dependency-removed`. Adding a dependency is somebody deciding this
	 *   work now waits on something; removing one is usually tidying up the
	 *   first decision, and only one of the two changes what anybody has to do.
	 *
	 * @var array<int, string>
	 */
	public const WORTH_SAYING = array(
		Events::MOVED,
		Events::RETURNED,
		Events::BLOCKED,
		Events::UNBLOCKED,
		Events::ENDED,
		Events::ARCHIVED,
		Events::REOPENED,
		Events::OVERRIDDEN,
		Events::OVER_ALLOCATED,
		Events::CONVERTED,
		Events::DEPENDENCY_ADDED,
	);

	/**
	 * The two that are governance rather than progress.
	 *
	 * Kept as their own list because they are the reason this feature is not
	 * simply "recent activity". Somebody going through a gate or past a
	 * capacity limit is a thing the studio agreed would be visible when it
	 * happened (WF-5, CAP-4), and a screen that let them sit at the bottom of a
	 * long list would be quietly undoing that.
	 *
	 * @var array<int, string>
	 */
	public const GOVERNANCE = array(
		Events::OVERRIDDEN,
		Events::OVER_ALLOCATED,
	);

	/**
	 * Whether one work event is worth saying at all.
	 *
	 * @param string $action The event's action.
	 * @return bool
	 */
	public static function says( string $action ): bool {
		return in_array( $action, self::WORTH_SAYING, true );
	}

	/**
	 * Whether it is one of the two the studio agreed would be visible.
	 *
	 * @param string $action The event's action.
	 * @return bool
	 */
	public static function is_governance( string $action ): bool {
		return in_array( $action, self::GOVERNANCE, true );
	}

	/**
	 * How urgent it looks, for the rail down the side of a row.
	 *
	 * Three levels, the same three the daily list uses, so a person reading
	 * both does not have to learn two colour schemes.
	 *
	 * @param string $action The event's action.
	 * @return string
	 */
	public static function tone( string $action ): string {
		if ( self::is_governance( $action ) ) {
			return 'stopped';
		}

		return in_array( $action, array( Events::RETURNED, Events::BLOCKED ), true ) ? 'late' : 'waiting';
	}

	/**
	 * How it reads, said the way somebody would say it.
	 *
	 * Not the action's name. "over-allocated" is what the engine calls it;
	 * "Somebody was committed past their hours" is what a person is looking at.
	 *
	 * @param string $action The event's action.
	 * @return string
	 */
	public static function word( string $action ): string {
		switch ( $action ) {
			case Events::MOVED:
				return __( 'Moved on', 'blueworx-forge' );
			case Events::RETURNED:
				return __( 'Sent back', 'blueworx-forge' );
			case Events::BLOCKED:
				return __( 'Blocked', 'blueworx-forge' );
			case Events::UNBLOCKED:
				return __( 'Unblocked', 'blueworx-forge' );
			case Events::ENDED:
				return __( 'Ended', 'blueworx-forge' );
			case Events::ARCHIVED:
				return __( 'Archived', 'blueworx-forge' );
			case Events::REOPENED:
				return __( 'Reopened', 'blueworx-forge' );
			case Events::OVERRIDDEN:
				return __( 'A gate was overridden', 'blueworx-forge' );
			case Events::OVER_ALLOCATED:
				return __( 'Somebody was committed past their hours', 'blueworx-forge' );
			case Events::CONVERTED:
				return __( 'Turned into work', 'blueworx-forge' );
			case Events::DEPENDENCY_ADDED:
				return __( 'Now waits on something else', 'blueworx-forge' );
			default:
				return $action;
		}
	}
}
