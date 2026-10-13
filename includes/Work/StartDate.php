<?php
/**
 * A task's start, set by Forge.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

/**
 * #513. Each person on a task has their own date, and nobody enters a start.
 *
 * The start is kept, set to the earliest of those dates, because the Gantt,
 * the client timelines and the start filters all draw from it. A recurring
 * chore or reminder sets its own start and keeps it, and so does any path
 * that names one outright.
 */
final class StartDate {

	/**
	 * The changes, with the start set from the dates after the edit.
	 *
	 * @param array<string, mixed> $changes The columns being written.
	 * @param array<string, mixed> $current The task as it stands; empty for a new one.
	 * @return array<string, mixed>
	 */
	public static function settle( array $changes, array $current = array() ): array {
		if ( array_key_exists( 'planned_start', $changes ) || array() === array_intersect_key( $changes, array_flip( Fields::DATE_ORDER ) ) ) {
			return $changes;
		}

		$assignees = $changes['assignees'] ?? $current['assignees'] ?? array();
		$assignees = is_string( $assignees ) ? (array) json_decode( $assignees, true ) : (array) $assignees;

		if ( array() !== $assignees ) {
			return $changes;
		}

		$dates = array();

		foreach ( Fields::DATE_ORDER as $field ) {
			$date = (string) ( $changes[ $field ] ?? $current[ $field ] ?? '' );

			if ( '' !== $date ) {
				$dates[] = $date;
			}
		}

		if ( array() !== $dates ) {
			$changes['planned_start'] = min( $dates );
		}

		return $changes;
	}
}
