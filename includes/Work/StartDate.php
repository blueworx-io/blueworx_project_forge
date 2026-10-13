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
		// No design needed, no Design by: the date goes with the designer.
		if ( array_key_exists( 'designer_id', $changes ) && '' === (string) $changes['designer_id'] ) {
			$changes['design_target'] = '';
		}

		if ( array_key_exists( 'planned_start', $changes ) || ! self::moved( $changes, $current ) ) {
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

		// Every date cleared is no dates at all, the start included.
		$changes['planned_start'] = array() === $dates ? '' : min( $dates );

		return $changes;
	}

	/**
	 * Whether the edit changes any of the dates. The panel sends them all on
	 * every save, so being sent is not the same as being changed.
	 *
	 * @param array<string, mixed> $changes The columns being written.
	 * @param array<string, mixed> $current The task as it stands.
	 * @return bool
	 */
	private static function moved( array $changes, array $current ): bool {
		foreach ( Fields::DATE_ORDER as $field ) {
			if ( array_key_exists( $field, $changes ) && (string) ( $current[ $field ] ?? '' ) !== (string) $changes[ $field ] ) {
				return true;
			}
		}

		return false;
	}
}
