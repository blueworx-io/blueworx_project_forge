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
