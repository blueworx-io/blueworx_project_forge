<?php
/**
 * The ways work ends without being released.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

/**
 * #111. WF-2's five outcomes, each with where it may be reached from, what it
 * requires, and how it behaves in reporting.
 *
 * **All five stay in reporting.** Nothing here deletes anything or hides it from
 * a report — an outcome is a flag on a record that remains. A rejected idea that
 * vanishes takes with it the answer to "did we already say no to this", which is
 * the question somebody asks a year later when it is proposed again.
 *
 * Two of the five are not really endings, and saying so here saves every caller
 * from guessing:
 *
 * - **Deferred** returns the item to Future Idea and leaves it open. It is
 *   recorded as an outcome because the decision happened, not because the work
 *   stopped existing.
 * - **Archived** is not an outcome at all but a state on top of one. It hides
 *   the item from default views and from nothing else.
 */
final class Outcomes {

	/**
	 * Triage said no.
	 */
	public const REJECTED = 'rejected';

	/**
	 * Somebody had already asked for this.
	 */
	public const DUPLICATE = 'duplicate';

	/**
	 * Stopped after it had started.
	 */
	public const CANCELLED = 'cancelled';

	/**
	 * Not now. Back to Future Idea, still open.
	 */
	public const DEFERRED = 'deferred';

	/**
	 * Out of the way, still in the reports.
	 */
	public const ARCHIVED = 'archived';

	/**
	 * The four that end an item, in the order the specification lists them.
	 * Archived is absent: it is a state applied to one of these.
	 */
	public const ALL = array(
		self::REJECTED,
		self::DUPLICATE,
		self::CANCELLED,
		self::DEFERRED,
	);

	/**
	 * The outcomes that close an item. Deferred is not among them.
	 */
	public const CLOSING = array(
		self::REJECTED,
		self::DUPLICATE,
		self::CANCELLED,
	);

	/**
	 * Longest a reason may be, matching the column it lands in.
	 */
	public const MAX_REASON = 191;

	/**
	 * Every outcome's rules.
	 *
	 * `from` is a list of stages, or `active` for any active stage. `needs` is
	 * `reason`, `duplicate_of` or nothing. `returns_to` is where the item lands.
	 * The reporting keys are read by the reports rather than re-derived there.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		return array(
			self::REJECTED  => array(
				'label'      => __( 'Rejected', 'blueworx-forge' ),
				'from'       => array( 'triage' ),
				'needs'      => 'reason',
				'returns_to' => '',
				'counted'    => true,
				'throughput' => false,
				'open'       => false,
			),
			self::DUPLICATE => array(
				'label'      => __( 'Duplicate', 'blueworx-forge' ),
				'from'       => array( 'triage' ),
				'needs'      => 'duplicate_of',
				'returns_to' => '',
				'counted'    => true,
				// Attributed to the survivor instead, so counting it here would
				// count one piece of work twice.
				'throughput' => false,
				'open'       => false,
			),
			self::CANCELLED => array(
				'label'      => __( 'Cancelled', 'blueworx-forge' ),
				'from'       => 'active',
				'needs'      => 'reason',
				'returns_to' => '',
				'counted'    => true,
				'throughput' => false,
				'open'       => false,
			),
			self::DEFERRED  => array(
				'label'      => __( 'Deferred', 'blueworx-forge' ),
				'from'       => array( 'triage', 'up-next' ),
				'needs'      => 'reason',
				'returns_to' => Stages::FIRST,
				'counted'    => true,
				'throughput' => false,
				// The one that stays open. It went back to being an idea.
				'open'       => true,
			),
		);
	}

	/**
	 * Whether a string is one of the four.
	 *
	 * @param string $outcome Candidate.
	 * @return bool
	 */
	public static function exists( string $outcome ): bool {
		return in_array( $outcome, self::ALL, true );
	}

	/**
	 * One outcome's rules.
	 *
	 * @param string $outcome Outcome.
	 * @return array<string, mixed>|null
	 */
	public static function definition( string $outcome ): ?array {
		return self::all()[ $outcome ] ?? null;
	}

	/**
	 * Whether an item in this stage may be given this outcome.
	 *
	 * @param string $outcome Outcome.
	 * @param string $stage   The stage it is in.
	 * @return bool
	 */
	public static function reachable_from( string $outcome, string $stage ): bool {
		$definition = self::definition( $outcome );

		if ( null === $definition ) {
			return false;
		}

		if ( 'active' === $definition['from'] ) {
			return Stages::is_active( $stage );
		}

		return in_array( $stage, (array) $definition['from'], true );
	}

	/**
	 * The outcomes offered on an item where it currently stands.
	 *
	 * @param array<string, mixed> $item The item, as read.
	 * @return array<int, string>
	 */
	public static function available_for( array $item ): array {
		if ( self::is_closed( $item ) ) {
			return array();
		}

		$stage     = (string) $item['stage'];
		$available = array();

		foreach ( self::ALL as $outcome ) {
			if ( self::reachable_from( $outcome, $stage ) ) {
				$available[] = $outcome;
			}
		}

		return $available;
	}

	/**
	 * Whether an item has already ended.
	 *
	 * @param array<string, mixed> $item The item, as read.
	 * @return bool
	 */
	public static function is_closed( array $item ): bool {
		return in_array( (string) ( $item['terminal_outcome'] ?? '' ), self::CLOSING, true );
	}

	/**
	 * Whether an item is in a state that may be archived: it ended, either at a
	 * terminal outcome or at Released.
	 *
	 * @param array<string, mixed> $item The item, as read.
	 * @return bool
	 */
	public static function may_archive( array $item ): bool {
		if ( ! empty( $item['archived'] ) ) {
			return false;
		}

		return self::is_closed( $item ) || 'released' === (string) $item['stage'];
	}

	/**
	 * How an outcome reads to a human.
	 *
	 * @param string $outcome Outcome.
	 * @return string
	 */
	public static function label( string $outcome ): string {
		if ( self::ARCHIVED === $outcome ) {
			return __( 'Archived', 'blueworx-forge' );
		}

		$definition = self::definition( $outcome );

		return null === $definition ? '' : (string) $definition['label'];
	}
}
