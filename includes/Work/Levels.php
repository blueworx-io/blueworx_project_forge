<?php
/**
 * The four rungs of the work hierarchy.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

/**
 * WORK-1: Project, Milestone (read as Release), Feature, Sub-Feature, as a field on one entity
 * rather than as four entities.
 *
 * One rule holds the shape together, and it lives here: a parent is always a
 * higher rung than its child. Strictly higher, so equal levels cannot parent
 * each other — which is the case that makes a cycle, two items each naming the
 * other. WORK-2 computes progress by walking down from a parent, and a cycle
 * there does not produce a wrong number, it produces a request that never ends.
 *
 * Levels may be skipped: a Feature directly under a Project is ordinary, not a
 * shortcut. That is why the rule compares rank rather than adjacency.
 */
final class Levels {

	/**
	 * The top rung.
	 */
	public const PROJECT = 'project';

	/**
	 * Beneath a project.
	 */
	public const MILESTONE = 'milestone';

	/**
	 * A piece of deliverable work.
	 */
	public const FEATURE = 'feature';

	/**
	 * The unit work actually gets done on.
	 */
	public const SUB_FEATURE = 'sub-feature';

	/**
	 * Every level, highest first. The order is the rank.
	 */
	public const ALL = array(
		self::PROJECT,
		self::MILESTONE,
		self::FEATURE,
		self::SUB_FEATURE,
	);

	/**
	 * Whether a string is a level at all.
	 *
	 * @param string $level Candidate.
	 * @return bool
	 */
	public static function exists( string $level ): bool {
		return in_array( $level, self::ALL, true );
	}

	/**
	 * How deep a level sits. Lower number, higher rung.
	 *
	 * @param string $level Level.
	 * @return int -1 when it is not a level.
	 */
	public static function rank( string $level ): int {
		$rank = array_search( $level, self::ALL, true );

		return false === $rank ? -1 : (int) $rank;
	}

	/**
	 * Whether one level may be the parent of another.
	 *
	 * @param string $higher The proposed parent's level.
	 * @param string $child  The child's level.
	 * @return bool
	 */
	public static function may_parent( string $higher, string $child ): bool {
		if ( ! self::exists( $higher ) || ! self::exists( $child ) ) {
			return false;
		}

		return self::rank( $higher ) < self::rank( $child );
	}

	/**
	 * #562. Whether one item may sit under another, reading the type for bugs,
	 * tasks and feedback and the level for everything else.
	 *
	 * A bug, task or feedback item never holds work, so it may sit under any
	 * level without making a loop. Everything else keeps the rank rule.
	 *
	 * @param string $parent_level The proposed parent's level.
	 * @param string $parent_type  The proposed parent's work type.
	 * @param string $child_level  The child's level.
	 * @param string $child_type   The child's work type.
	 * @return bool
	 */
	public static function may_hold( string $parent_level, string $parent_type, string $child_level, string $child_type ): bool {
		if ( Types::is_leaf( $parent_type ) ) {
			return false;
		}

		if ( Types::is_leaf( $child_type ) ) {
			return true;
		}

		return self::may_parent( $parent_level, $child_level );
	}

	/**
	 * How a level reads to a human.
	 *
	 * @param string $level Level.
	 * @return string
	 */
	public static function label( string $level ): string {
		switch ( $level ) {
			case self::PROJECT:
				return __( 'Project', 'blueworx-forge' );
			case self::MILESTONE:
				return __( 'Release', 'blueworx-forge' );
			case self::FEATURE:
				return __( 'Feature', 'blueworx-forge' );
			case self::SUB_FEATURE:
				return __( 'Sub-feature', 'blueworx-forge' );
			default:
				return '';
		}
	}
}
