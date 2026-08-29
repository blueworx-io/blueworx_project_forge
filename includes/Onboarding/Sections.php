<?php
/**
 * The three parts of getting a site launched.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Onboarding;

/**
 * ONB-1 (#159). Every step belongs to exactly one of three sections.
 *
 * Sections rather than a flat list, because a client looking at forty
 * outstanding things has no idea which are theirs to do now and which are
 * months away — and a checklist nobody can find their place in is a checklist
 * that gets abandoned and chased by email instead.
 *
 * Three rather than twelve, because the twelve categories are what the steps
 * are *about* and the sections are *when they happen*. Grouping by category
 * would tell somebody what a step concerns and not whether it is their turn.
 */
final class Sections {

	/**
	 * Getting the ground ready: access, accounts, and the things everything
	 * else needs before it can start.
	 */
	public const FOUNDATIONS = 'foundations';

	/**
	 * The client seeing the work and saying what they think, while it is still
	 * cheap to change.
	 */
	public const BUILD_REVIEWS = 'build-reviews';

	/**
	 * Going live, and everything that has to be true first.
	 */
	public const LAUNCH = 'launch';

	/**
	 * Every section, in the order they happen.
	 *
	 * The order is the point rather than a tidiness: it is what a checklist
	 * screen renders down, and what tells somebody how far along they are.
	 *
	 * @var array<int, string>
	 */
	public const ALL = array(
		self::FOUNDATIONS,
		self::BUILD_REVIEWS,
		self::LAUNCH,
	);

	/**
	 * How each section reads on screen.
	 *
	 * @var array<string, string>
	 */
	private const LABELS = array(
		self::FOUNDATIONS   => 'Foundations',
		self::BUILD_REVIEWS => 'Build reviews',
		self::LAUNCH        => 'Launch',
	);

	/**
	 * Whether this is one of the three.
	 *
	 * @param string $section Section name.
	 * @return bool
	 */
	public static function exists( string $section ): bool {
		return in_array( $section, self::ALL, true );
	}

	/**
	 * What to call a section on screen.
	 *
	 * @param string $section Section name.
	 * @return string Empty when it is not a section.
	 */
	public static function label( string $section ): string {
		return self::LABELS[ $section ] ?? '';
	}
}
