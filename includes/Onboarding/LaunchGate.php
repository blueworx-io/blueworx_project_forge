<?php
/**
 * Nothing goes live on a site whose onboarding is not finished.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Onboarding;

/**
 * #166. The first go-live waits for the launch-critical steps, and only the
 * first.
 *
 * **Gating every release was considered and rejected**, and the reason is worth
 * keeping because the alternative looks safer than it is. A gate that stands
 * between somebody and an urgent bug fix, months after a site went live,
 * because a box on a checklist was never ticked, is a gate people learn to go
 * round — and a gate with a habit of being overridden protects nothing at all.
 * Gating the first release costs one conversation at exactly the moment
 * somebody is paying attention to onboarding anyway.
 *
 * So the question is not "is this client's onboarding finished" but "would this
 * be the first thing this site has ever released". Once anything is live the
 * gate has done its work and stays out of the way.
 *
 * The other decision written down here is what happens to a site nobody has
 * given a checklist to. It is let through. A site with no checklist is not one
 * failing its onboarding — it is one that never started, which is the ordinary
 * state of every site that predates the checklist existing, and there is
 * nothing the person being refused could do about it. {@see Progress} reports
 * both cases as `launch_ready` false, so telling them apart is this class's job
 * and is done by looking at whether anything is actually outstanding.
 */
final class LaunchGate {

	/**
	 * What this refusal calls itself among a move's unmet requirements.
	 *
	 * Named like a gate requirement because that is how it travels back to the
	 * caller — the board draws it beside the workflow gate's own reasons, and a
	 * second shape would need a second thing on screen to render it.
	 */
	public const REQUIREMENT = 'G-LAUNCH-ONBOARDING';

	/**
	 * Whether this release must be refused.
	 *
	 * @param array<string, mixed> $progress    As {@see Progress::of()} reports it.
	 * @param bool                 $already_live Whether the site has released
	 *                                           anything before.
	 * @return bool
	 */
	public static function refuses( array $progress, bool $already_live ): bool {
		if ( $already_live ) {
			return false;
		}

		return array() !== self::outstanding( $progress );
	}

	/**
	 * The launch-critical steps standing in the way, as unmet requirements.
	 *
	 * Named rather than counted. "Three things outstanding" sends somebody
	 * looking; naming them sends them to the right place, and the step id lets
	 * a screen link straight to the one that needs doing.
	 *
	 * @param array<string, mixed> $progress As {@see Progress::of()} reports it.
	 * @return array<int, array<string, string>>
	 */
	public static function unmet( array $progress ): array {
		$unmet = array();

		foreach ( self::outstanding( $progress ) as $step ) {
			$unmet[] = array(
				'requirement' => self::REQUIREMENT,
				'step_id'     => (string) ( $step['id'] ?? '' ),
				'title'       => (string) ( $step['title'] ?? '' ),
				'reason'      => sprintf(
					/* translators: %s: the outstanding onboarding step. */
					__( 'Onboarding is not finished: %s', 'blueworx-forge' ),
					(string) ( $step['title'] ?? '' )
				),
			);
		}

		return $unmet;
	}

	/**
	 * The launch-critical steps that are not settled.
	 *
	 * @param array<string, mixed> $progress As {@see Progress::of()} reports it.
	 * @return array<int, array<string, mixed>>
	 */
	private static function outstanding( array $progress ): array {
		$blocking = $progress['blocking'] ?? array();

		return is_array( $blocking ) ? array_values( $blocking ) : array();
	}
}
