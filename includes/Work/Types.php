<?php
/**
 * The kinds of work.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

/**
 * WORK-1's four work types. Deliberately separate from the level: a Bug is a
 * kind of work, not a rung, which is what lets one attach at any level or stand
 * alone while a Sub-Feature always hangs beneath something.
 *
 * The type is also load-bearing in two places later. It decides whether Triage
 * leads to Bug Tracking (WF-1), and COMM-5 decides whether a bug is chargeable
 * from whether we delivered the thing that broke.
 */
final class Types {

	/**
	 * New capability.
	 */
	public const FEATURE = 'feature';

	/**
	 * Something that does not work.
	 */
	public const BUG = 'bug';

	/**
	 * A response to something delivered.
	 */
	public const FEEDBACK = 'feedback';

	/**
	 * Work that is neither a feature nor a fault.
	 */
	public const TASK = 'task';

	/**
	 * Every work type.
	 */
	public const ALL = array(
		self::FEATURE,
		self::BUG,
		self::FEEDBACK,
		self::TASK,
	);

	/**
	 * Whether a string is a work type at all.
	 *
	 * @param string $type Candidate.
	 * @return bool
	 */
	public static function exists( string $type ): bool {
		return in_array( $type, self::ALL, true );
	}

	/**
	 * How a work type reads to a human.
	 *
	 * @param string $type Work type.
	 * @return string
	 */
	public static function label( string $type ): string {
		switch ( $type ) {
			case self::FEATURE:
				return __( 'Feature', 'blueworx-forge' );
			case self::BUG:
				return __( 'Bug', 'blueworx-forge' );
			case self::FEEDBACK:
				return __( 'Feedback', 'blueworx-forge' );
			case self::TASK:
				return __( 'Task', 'blueworx-forge' );
			default:
				return '';
		}
	}
}
