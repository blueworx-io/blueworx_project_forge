<?php
/**
 * The twelve stages.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

/**
 * The stage registry (#104), from docs/architecture/workflow-state-machine.md.
 *
 * **There is no way to change this set, and that is the feature.** No add, no
 * rename, no reorder, no delete, no filter, no admin screen, no REST route.
 * Everything else in the product is written against these twelve: the gates
 * name them, the transition table maps them, the board draws a column per
 * stage, and every report groups by them. A stage set somebody can edit is one
 * that can disagree with the code reading it, and the failure shows up as work
 * sitting in a column nothing knows how to move it out of.
 *
 * Not even a filter. A filterable list is an editable list with extra steps.
 *
 * Two of the twelve are not ordinary (WF-1). Bug Tracking is **conditional** —
 * entered only from Triage and only for a bug. Blocked is an **exception** —
 * enterable from any active stage, storing where it came from so it can be
 * restored. The kinds live here beside the list, because every later rule
 * switches on them.
 */
final class Stages {

	/**
	 * An ordinary stage on the forward path.
	 */
	public const LINEAR = 'linear';

	/**
	 * Entered only when a condition holds.
	 */
	public const CONDITIONAL = 'conditional';

	/**
	 * Not on the path at all; entered and left from wherever the item was.
	 */
	public const EXCEPTION = 'exception';

	/**
	 * Where every item starts, and the only stage it may be created in.
	 */
	public const FIRST = 'future-idea';

	/**
	 * The conditional one. Named because three other classes have to test for
	 * it, and a literal repeated four times is a typo waiting to be permitted.
	 */
	public const BUG_TRACKING = 'bug-tracking';

	/**
	 * The exception one.
	 */
	public const BLOCKED = 'blocked';

	/**
	 * The twelve, in the order the state machine gives them.
	 */
	public const ALL = array(
		'future-idea',
		'triage',
		'bug-tracking',
		'documentation-period',
		'technical-audit',
		'design-process',
		'blocked',
		'up-next',
		'in-development',
		'in-review',
		'completed',
		'released',
	);

	/**
	 * Which of the twelve are not ordinary. Everything absent from here is
	 * linear, so a new stage cannot be quietly added as an exception.
	 */
	private const KINDS = array(
		'bug-tracking' => self::CONDITIONAL,
		'blocked'      => self::EXCEPTION,
	);

	/**
	 * Whether a string is a stage at all.
	 *
	 * @param string $stage Candidate.
	 * @return bool
	 */
	public static function exists( string $stage ): bool {
		return in_array( $stage, self::ALL, true );
	}

	/**
	 * Where a stage sits in the order.
	 *
	 * @param string $stage Stage.
	 * @return int -1 when it is not a stage.
	 */
	public static function position( string $stage ): int {
		$position = array_search( $stage, self::ALL, true );

		return false === $position ? -1 : (int) $position;
	}

	/**
	 * What kind of stage this is.
	 *
	 * @param string $stage Stage.
	 * @return string One of LINEAR, CONDITIONAL, EXCEPTION, or '' for a non-stage.
	 */
	public static function kind( string $stage ): string {
		if ( ! self::exists( $stage ) ) {
			return '';
		}

		return self::KINDS[ $stage ] ?? self::LINEAR;
	}

	/**
	 * The stages a board shows as columns.
	 *
	 * Not all twelve: Blocked is somewhere an item goes *from* a column rather
	 * than a column of its own, and Bug Tracking only exists for bugs. Both are
	 * shown on the item, not as a place work queues up.
	 *
	 * @return array<int, string>
	 */
	public static function linear(): array {
		return array_values(
			array_filter(
				self::ALL,
				static function ( string $stage ): bool {
					return self::LINEAR === self::kind( $stage );
				}
			)
		);
	}

	/**
	 * Whether an item of this work type may ever be in this stage (#110).
	 *
	 * **Asked by every route that moves work, not only the forward one.** Bug
	 * Tracking being conditional is worth nothing if the forward path checks it
	 * and a return, an unblock or a reopen does not — one unchecked door and a
	 * Feature is sitting in Bug Tracking with a gate that assumes it is a bug.
	 * So the condition lives here, on the stage, and every mover asks the stage.
	 *
	 * @param string $stage     Stage.
	 * @param string $work_type The item's work type.
	 * @return bool
	 */
	public static function may_hold( string $stage, string $work_type ): bool {
		if ( ! self::exists( $stage ) ) {
			return false;
		}

		if ( self::BUG_TRACKING === $stage ) {
			return Types::BUG === $work_type;
		}

		return true;
	}

	/**
	 * Whether a bug is obliged to pass through this stage. The other half of
	 * WF-1: Bug Tracking is not optional for a bug, it is the only way out of
	 * Triage for one.
	 *
	 * @param string $stage     Stage.
	 * @param string $work_type The item's work type.
	 * @return bool
	 */
	public static function required_for( string $stage, string $work_type ): bool {
		return self::BUG_TRACKING === $stage && Types::BUG === $work_type;
	}

	/**
	 * The stages an item of this work type may occupy, in order. What a board
	 * draws for one item, as against the twelve it draws in general.
	 *
	 * @param string $work_type The item's work type.
	 * @return array<int, string>
	 */
	public static function path_for( string $work_type ): array {
		return array_values(
			array_filter(
				self::ALL,
				static function ( string $stage ) use ( $work_type ): bool {
					return self::EXCEPTION !== self::kind( $stage ) && self::may_hold( $stage, $work_type );
				}
			)
		);
	}

	/**
	 * Whether work in this stage is still being worked on — which is what "any
	 * active stage" means everywhere the specification says it.
	 *
	 * Released is not active: it is done. Blocked is not active: it is paused,
	 * and the move out of it is its own path.
	 *
	 * @param string $stage Stage.
	 * @return bool
	 */
	public static function is_active( string $stage ): bool {
		return self::exists( $stage ) && self::BLOCKED !== $stage && 'released' !== $stage;
	}

	/**
	 * How a stage reads to a human.
	 *
	 * @param string $stage Stage.
	 * @return string
	 */
	public static function label( string $stage ): string {
		switch ( $stage ) {
			case 'future-idea':
				return __( 'Future idea', 'blueworx-forge' );
			case 'triage':
				return __( 'Triage', 'blueworx-forge' );
			case 'bug-tracking':
				return __( 'Bug tracking', 'blueworx-forge' );
			case 'documentation-period':
				return __( 'Documentation period', 'blueworx-forge' );
			case 'technical-audit':
				return __( 'Technical audit', 'blueworx-forge' );
			case 'design-process':
				return __( 'Design process', 'blueworx-forge' );
			case 'blocked':
				return __( 'Blocked', 'blueworx-forge' );
			case 'up-next':
				return __( 'Up next', 'blueworx-forge' );
			case 'in-development':
				return __( 'In development', 'blueworx-forge' );
			case 'in-review':
				return __( 'In review', 'blueworx-forge' );
			case 'completed':
				return __( 'Completed', 'blueworx-forge' );
			case 'released':
				return __( 'Released', 'blueworx-forge' );
			default:
				return '';
		}
	}
}
