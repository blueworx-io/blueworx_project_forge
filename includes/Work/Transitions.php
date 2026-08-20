<?php
/**
 * What may move where.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

use Blueworx\Forge\Tenancy\Capabilities;

/**
 * The forward path from docs/architecture/workflow-state-machine.md, as a table
 * rather than as a series of conditions somewhere in a controller.
 *
 * **Single step only.** A jump from Triage to In Development would skip the
 * documentation, audit and design gates, and a service that permits it has
 * quietly made those gates optional. There is no entry in the table for it, so
 * there is no code to forget to write.
 *
 * Only forward moves are here. Returns (#108), Blocked (#109), terminal
 * outcomes (#111), reopen (#113) and the administrator override (#114) are each
 * their own path with their own requirements — a mandatory reason on every
 * backwards move, for instance (WF-3) — and folding them into this table would
 * lose that.
 *
 * Every move names its gate. The gates themselves arrive with #105; naming them
 * now means no move can be added later without one, which is how a gate gets
 * skipped by accident.
 */
final class Transitions {

	/**
	 * The forward path: from → each allowed destination, with its gate and the
	 * work type it requires, where it requires one.
	 *
	 * @var array<string, array<string, array{gate: string, work_type: string|null}>>
	 */
	private const FORWARD = array(
		'future-idea'          => array(
			'triage' => array(
				'gate'      => 'G-FUTURE-IDEA',
				'work_type' => null,
			),
		),
		'triage'               => array(
			// The one fork in the forward path. WF-1 makes Bug Tracking
			// conditional on the work type, so both destinations are listed and
			// the type decides which is offered.
			'bug-tracking'         => array(
				'gate'      => 'G-TRIAGE',
				'work_type' => Types::BUG,
			),
			'documentation-period' => array(
				'gate'      => 'G-TRIAGE',
				'work_type' => 'not-bug',
			),
		),
		'bug-tracking'         => array(
			'documentation-period' => array(
				'gate'      => 'G-BUG-TRACKING',
				'work_type' => null,
			),
		),
		'documentation-period' => array(
			'technical-audit' => array(
				'gate'      => 'G-DOCUMENTATION',
				'work_type' => null,
			),
		),
		'technical-audit'      => array(
			'design-process' => array(
				'gate'      => 'G-TECHNICAL-AUDIT',
				'work_type' => null,
			),
		),
		'design-process'       => array(
			'up-next' => array(
				'gate'      => 'G-DESIGN',
				'work_type' => null,
			),
		),
		'up-next'              => array(
			'in-development' => array(
				'gate'      => 'G-UP-NEXT',
				'work_type' => null,
			),
		),
		'in-development'       => array(
			'in-review' => array(
				'gate'      => 'G-IN-DEVELOPMENT',
				'work_type' => null,
			),
		),
		'in-review'            => array(
			'completed' => array(
				'gate'      => 'G-IN-REVIEW',
				'work_type' => null,
			),
		),
		'completed'            => array(
			'released' => array(
				'gate'      => 'G-COMPLETED',
				'work_type' => null,
			),
		),
		'released'             => array(),
	);

	/**
	 * The gate recorded when an item is created.
	 */
	public const CREATE_GATE = 'G-CREATE';

	/**
	 * Whether a forward move is allowed for an item of this work type.
	 *
	 * @param string $from      Stage moving from.
	 * @param string $to        Stage moving to.
	 * @param string $work_type The item's work type.
	 * @return bool
	 */
	public static function allowed( string $from, string $to, string $work_type ): bool {
		if ( ! Stages::exists( $from ) || ! Stages::exists( $to ) ) {
			return false;
		}

		$move = self::FORWARD[ $from ][ $to ] ?? null;

		if ( null === $move ) {
			return false;
		}

		return self::type_matches( $move['work_type'], $work_type );
	}

	/**
	 * Where an item of this work type may go next.
	 *
	 * The board asks this rather than working it out from the stage order, so
	 * the fork at Triage is answered in one place.
	 *
	 * @param string $from      Stage moving from.
	 * @param string $work_type The item's work type.
	 * @return array<int, string>
	 */
	public static function next_from( string $from, string $work_type ): array {
		$next = array();

		foreach ( self::FORWARD[ $from ] ?? array() as $to => $move ) {
			if ( self::type_matches( $move['work_type'], $work_type ) ) {
				$next[] = $to;
			}
		}

		return $next;
	}

	/**
	 * The gate a move has to satisfy.
	 *
	 * @param string $from Stage moving from.
	 * @param string $to   Stage moving to.
	 * @return string The gate's name, or '' when the move is not on the path.
	 */
	public static function gate_for( string $from, string $to ): string {
		return self::FORWARD[ $from ][ $to ]['gate'] ?? '';
	}

	/**
	 * The gate recorded on *entering* a stage, as against leaving one.
	 *
	 * Released is the only stage with one, and it has to be a separate idea from
	 * the exit gate: G-COMPLETED asks whether the item is ready to be released,
	 * and G-RELEASED asks what actually happened when it was. Folding the second
	 * into the first would mean recording the release evidence before the
	 * release.
	 *
	 * @param string $to Stage being entered.
	 * @return string The gate's name, or '' where entry has no gate of its own.
	 */
	public static function entry_gate_for( string $to ): string {
		return 'released' === $to ? 'G-RELEASED' : '';
	}

	/**
	 * Which capability a forward move needs (#112).
	 *
	 * Two of the eleven are not the ordinary one. Approving a review and
	 * confirming a release belong to the person the item names, not to anybody
	 * with permission to move work — so the move itself says which capability
	 * it wants, and the permission layer answers whether this person has it.
	 * Deciding that in the controller instead would mean every new caller has
	 * to remember which two moves are special.
	 *
	 * @param string $from Stage moving from.
	 * @param string $to   Stage moving to.
	 * @return string A Tenancy\Capabilities constant.
	 */
	public static function capability_for( string $from, string $to ): string {
		if ( 'in-review' === $from && 'completed' === $to ) {
			return Capabilities::APPROVE_REVIEW;
		}

		if ( 'completed' === $from && 'released' === $to ) {
			return Capabilities::CONFIRM_RELEASE;
		}

		return Capabilities::MOVE_FORWARD;
	}

	/**
	 * Whether an item may be created in this stage. Only one may.
	 *
	 * @param string $stage Stage.
	 * @return bool
	 */
	public static function may_start( string $stage ): bool {
		return Stages::FIRST === $stage;
	}

	/**
	 * Whether a move's work-type condition is met.
	 *
	 * @param string|null $required  What the move requires: a type, the special
	 *                               'not-bug', or null for no condition.
	 * @param string      $work_type The item's work type.
	 * @return bool
	 */
	private static function type_matches( ?string $required, string $work_type ): bool {
		if ( null === $required ) {
			return true;
		}

		if ( 'not-bug' === $required ) {
			return Types::BUG !== $work_type;
		}

		return $required === $work_type;
	}
}
