<?php
/**
 * How far through a client is, and whether they can go live.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Onboarding;

/**
 * ONB-1 and #164: completion is earned, not declared.
 *
 * Both figures here are worked out from the steps every time they are asked
 * for. Neither is stored, which is what #164 means by refusing a direct write
 * to the completion figure — there is no figure to write to. A percentage
 * somebody can set by hand is a percentage that eventually disagrees with the
 * checklist it claims to describe, and the checklist is the thing that is true.
 *
 * **Two questions, not one.** Completion is the share of required steps
 * approved. Launch readiness is a separate flag over the launch-critical steps
 * alone, because a site at 95% with an unapproved DNS step is not nearly ready
 * — it is not ready at all, and a single number would say otherwise in the one
 * case where being wrong costs the most.
 */
final class Progress {

	/**
	 * How many decimal places a percentage carries.
	 *
	 * One. "Thirty-three point three per cent" is a thing somebody says; the
	 * full expansion is noise that makes two figures look different when they
	 * are not.
	 */
	public const PLACES = 1;

	/**
	 * Where a site has got to.
	 *
	 * @param array<int, array<string, mixed>> $steps The site's steps.
	 * @return array{required: int, approved: int, completion: float, launch_ready: bool, blocking: array<int, array<string, mixed>>}
	 */
	public static function of( array $steps ): array {
		$required = 0;
		$approved = 0;
		$critical = 0;
		$blocking = array();

		foreach ( $steps as $step ) {
			$settled = self::is_settled( $step );

			// ONB-1 counts the percentage over required steps. An optional one
			// nobody has done is not work outstanding.
			if ( empty( $step['optional'] ) ) {
				++$required;

				if ( $settled ) {
					++$approved;
				}
			}

			if ( empty( $step['launch_critical'] ) ) {
				continue;
			}

			++$critical;

			if ( $settled ) {
				continue;
			}

			/*
			 * Named rather than counted, because #166 refuses a launch and has
			 * to say what is in the way. "Three things outstanding" sends
			 * somebody looking; naming them sends them to the right place.
			 */
			$blocking[] = array(
				'id'    => (string) ( $step['id'] ?? '' ),
				'title' => (string) ( $step['title'] ?? '' ),
			);
		}

		return array(
			'required'     => $required,
			'approved'     => $approved,
			'completion'   => self::share( $approved, $required ),

			/*
			 * Three things have to be true, and the third is the awkward one.
			 *
			 * A site with no steps at all is not ready: it has not been given a
			 * checklist yet, which is a different thing from having finished
			 * one. And a site whose checklist names *no* launch-critical steps
			 * is not ready either — not because something is outstanding, but
			 * because nobody has said what ready would mean.
			 *
			 * That second case is a misconfigured template rather than a real
			 * situation: ONB-1 names five launch-critical categories, so a live
			 * template always has some. Of the two ways to be wrong about it,
			 * refusing every launch gets noticed and corrected within the hour,
			 * and declaring a site ready when nothing was ever checked gets
			 * noticed after it is live.
			 */
			'launch_ready' => array() !== $steps && array() === $blocking && 0 < $critical,
			'blocking'     => $blocking,
		);
	}

	/**
	 * Whether a step is finished with, either way.
	 *
	 * Approved or not applicable. A step that does not apply to this client is
	 * not work anybody is waiting for, and counting it as outstanding would
	 * hold somebody at 80% for ever over something nobody is going to do.
	 *
	 * @param array<string, mixed> $step The step.
	 * @return bool
	 */
	public static function is_settled( array $step ): bool {
		return in_array( (string) ( $step['status'] ?? '' ), Statuses::SETTLED, true );
	}

	/**
	 * One count as a percentage of another.
	 *
	 * Nought out of nought is nought, not a hundred. A checklist nobody has
	 * been given yet reads as nothing done rather than as complete, and the
	 * difference decides whether anybody goes looking at it.
	 *
	 * @param int $part  How many are done.
	 * @param int $whole How many there are.
	 * @return float
	 */
	public static function share( int $part, int $whole ): float {
		if ( $whole <= 0 ) {
			return 0.0;
		}

		return round( ( $part / $whole ) * 100, self::PLACES );
	}
}
