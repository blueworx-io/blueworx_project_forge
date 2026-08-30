<?php
/**
 * The client's own view of their onboarding checklist.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * The read half of a client's checklist (#162).
 *
 * The ordinary read-through rule, with no exception: a checklist is exactly the
 * kind of record that is better shown a minute old than not shown at all. As
 * everywhere else here, `ok` means the studio answered — not that there is
 * anything in the list. "You have no checklist" and "we cannot see your
 * checklist" are different sentences, and answering both with an empty page
 * tells a client their onboarding vanished.
 *
 * The grouping and the "what next" question are answered here rather than on
 * the screen, for the same reason the studio answers overdue in one place: the
 * checklist page and the landing page both want them, and two screens working
 * it out separately is how they come to disagree.
 *
 * **The order is the studio's.** This artifact never re-sorts a checklist. A
 * client seeing their steps in a different order from the person they are
 * talking to is a support call about a checklist that "changed".
 */
final class Checklist {

	/**
	 * The studio route this reads.
	 */
	public const ROUTE = '/client/onboarding';

	/**
	 * The sections, in the order they happen.
	 *
	 * Mirrored from the studio's own list rather than shared, because this is a
	 * separate plugin (ARCH-1) and cannot see that class. Anything the studio
	 * sends that is not here is still shown, under its own heading — see
	 * {@see self::sections()}.
	 *
	 * @var array<int, string>
	 */
	private const SECTIONS = array( 'foundations', 'build-reviews', 'launch' );

	/**
	 * How each section reads on screen.
	 *
	 * @var array<string, string>
	 */
	private const LABELS = array(
		'foundations'   => 'Foundations',
		'build-reviews' => 'Build reviews',
		'launch'        => 'Launch',
	);

	/**
	 * The statuses that mean there is nothing for the client to do right now.
	 *
	 * Submitted is in the list deliberately. It is not finished, but it is with
	 * us — and leaving it looking actionable is how somebody answers the same
	 * question twice and wonders why nothing happened.
	 *
	 * @var array<int, string>
	 */
	private const NOT_THEIRS_NOW = array( 'submitted', 'approved', 'not-applicable' );

	/**
	 * The checklist, as this site can currently see it.
	 *
	 * @param bool $force True to ignore a still-fresh copy and ask the studio.
	 * @return array<string, mixed>
	 */
	public static function view( bool $force = false ): array {
		$read    = ReadThrough::view( self::ROUTE, $force );
		$payload = $read['payload'];

		$steps = is_array( $payload['steps'] ?? null ) ? array_values( $payload['steps'] ) : array();

		return array(
			'ok'       => null !== $payload,
			'steps'    => $steps,
			'sections' => self::sections( $steps ),
			'next'     => self::next_of( $steps ),
			'progress' => is_array( $payload['progress'] ?? null ) ? $payload['progress'] : array(),
			'sync'     => $read['sync'],
		);
	}

	/**
	 * Whether this step is the client's to do, now.
	 *
	 * Two questions at once, and on purpose: whose step it is, and whether it
	 * is currently waiting on them. A screen only ever wants both together.
	 *
	 * @param array<string, mixed> $step The step, as the studio sent it.
	 * @return bool
	 */
	public static function is_theirs( array $step ): bool {
		if ( 'client' !== (string) ( $step['owner_side'] ?? '' ) ) {
			return false;
		}

		return ! in_array( (string) ( $step['status'] ?? '' ), self::NOT_THEIRS_NOW, true );
	}

	/**
	 * The steps, grouped into sections, in the order the sections happen.
	 *
	 * A section with nothing in it is left out: an empty heading reads as work
	 * that has gone missing rather than work that does not apply here.
	 *
	 * @param array<int, array<string, mixed>> $steps The steps.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function sections( array $steps ): array {
		$grouped = array();

		foreach ( self::SECTIONS as $section ) {
			$found = array();

			foreach ( $steps as $step ) {
				if ( (string) ( $step['section'] ?? '' ) === $section ) {
					$found[] = $step;
				}
			}

			if ( array() !== $found ) {
				$grouped[ $section ] = $found;
			}
		}

		/*
		 * Anything in a section this artifact has not heard of goes last, under
		 * its own heading. A template edited on the studio can name one, and
		 * dropping the step would hide work — which is the worse of the two
		 * ways to be wrong about it.
		 */
		foreach ( $steps as $step ) {
			$section = (string) ( $step['section'] ?? '' );

			if ( '' === $section || in_array( $section, self::SECTIONS, true ) ) {
				continue;
			}

			$grouped[ $section ][] = $step;
		}

		return $grouped;
	}

	/**
	 * What to call a section on screen.
	 *
	 * @param string $section Section name.
	 * @return string The section itself when it is one we have no words for.
	 */
	public static function label( string $section ): string {
		return self::LABELS[ $section ] ?? ucfirst( str_replace( '-', ' ', $section ) );
	}

	/**
	 * The one step to point somebody at.
	 *
	 * Late first, then the order the studio put them in. Position decides how
	 * the list reads; lateness decides where somebody is sent, because a client
	 * with one late step and nine on time should be sent to the late one.
	 *
	 * @param array<int, array<string, mixed>> $steps The steps.
	 * @return array<string, mixed> Empty when nothing is waiting on them.
	 */
	public static function next_of( array $steps ): array {
		$outstanding = array();

		foreach ( $steps as $step ) {
			if ( self::is_theirs( $step ) ) {
				$outstanding[] = $step;
			}
		}

		if ( array() === $outstanding ) {
			return array();
		}

		foreach ( $outstanding as $step ) {
			if ( ! empty( $step['overdue'] ) ) {
				return $step;
			}
		}

		return $outstanding[0];
	}
}
