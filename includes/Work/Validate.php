<?php
/**
 * Input rules for work items.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

/**
 * Plain PHP, no WordPress, no database — the same shape as Tenancy\Validate and
 * for the same reason: two doors reach these records and both have to refuse
 * the same input for the same reason.
 *
 * Two rules that look like validation are deliberately *not* here, because they
 * need the database and belong to the caller that has one: whether a parent
 * exists, and whether it sits under the same client site. Pretending to check
 * them here would be worse than not checking them.
 */
final class Validate {

	/**
	 * Longest a title may be, matching the column it lands in.
	 */
	public const MAX_TITLE = 191;

	/**
	 * Checks a work item.
	 *
	 * @param array<string, mixed> $input   Raw input.
	 * @param bool                 $partial True for an edit, which may mention
	 *                                      only the fields it changes.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function item( array $input, bool $partial ): array {
		$values = array();
		$errors = array();

		// The stage is never writable. Refused rather than dropped: silently
		// ignoring it would let a caller believe they had moved the item, and
		// the whole point of the transition service is that they cannot.
		if ( array_key_exists( 'stage', $input ) ) {
			$errors['stage'] = 'A stage is changed by moving the item, not by editing it.';
		}

		if ( ! $partial || array_key_exists( 'title', $input ) ) {
			$title = trim( (string) ( $input['title'] ?? '' ) );

			if ( '' === $title ) {
				$errors['title'] = 'Work needs a title.';
			} elseif ( mb_strlen( $title ) > self::MAX_TITLE ) {
				$errors['title'] = 'That title is too long.';
			} else {
				$values['title'] = $title;
			}
		}

		if ( ! $partial || array_key_exists( 'level', $input ) ) {
			$level = trim( (string) ( $input['level'] ?? '' ) );

			if ( ! Levels::exists( $level ) ) {
				$errors['level'] = 'That is not one of the four levels.';
			} else {
				$values['level'] = $level;
			}
		}

		if ( ! $partial || array_key_exists( 'work_type', $input ) ) {
			$type = trim( (string) ( $input['work_type'] ?? '' ) );

			if ( ! Types::exists( $type ) ) {
				$errors['work_type'] = 'That is not one of the work types.';
			} else {
				$values['work_type'] = $type;
			}
		}

		if ( array_key_exists( 'parent_id', $input ) ) {
			// An empty parent is a real answer — a Bug standing alone, a Project
			// at the top — so it is stored rather than treated as missing.
			$values['parent_id'] = trim( (string) $input['parent_id'] );
		} elseif ( ! $partial ) {
			$values['parent_id'] = '';
		}

		self::text_fields( $input, $values );
		self::enum_fields( $input, $values, $errors );
		self::planning_fields( $input, $values, $errors );

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * The definition and delivery text, which have no rules beyond being text.
	 *
	 * @param array<string, mixed> $input  Raw input.
	 * @param array<string, mixed> $values Cleaned values, by reference.
	 */
	private static function text_fields( array $input, array &$values ): void {
		$fields = array_merge(
			array_diff( Fields::DEFINITION, array( 'title' ) ),
			array( 'release_destination' )
		);

		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$values[ $field ] = trim( (string) $input[ $field ] );
			}
		}
	}

	/**
	 * The fields that may only hold one of a fixed set.
	 *
	 * @param array<string, mixed>  $input  Raw input.
	 * @param array<string, mixed>  $values Cleaned values, by reference.
	 * @param array<string, string> $errors Errors, by reference.
	 */
	private static function enum_fields( array $input, array &$values, array &$errors ): void {
		$enums = array(
			'commercial_class' => Fields::COMMERCIAL_CLASSES,
			'release_method'   => Fields::RELEASE_METHODS,
			'priority'         => Fields::PRIORITIES,
		);

		foreach ( $enums as $field => $allowed ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$value = trim( (string) $input[ $field ] );

			if ( '' === $value ) {
				$values[ $field ] = '';
				continue;
			}

			if ( ! in_array( $value, $allowed, true ) ) {
				$errors[ $field ] = 'That is not one of the values ' . $field . ' can hold.';
				continue;
			}

			$values[ $field ] = $value;
		}

		if ( array_key_exists( 'delivered_by_forge', $input ) ) {
			$values['delivered_by_forge'] = empty( $input['delivered_by_forge'] ) ? 0 : 1;
		}
	}

	/**
	 * Dates and hours.
	 *
	 * Dates are stored as plain YYYY-MM-DD rather than as timestamps. WORK-3
	 * makes a due date a calendar day agreed with a client, not an instant: a
	 * timestamp would silently become the day before for anybody in a timezone
	 * west of the one it was entered in.
	 *
	 * @param array<string, mixed>  $input  Raw input.
	 * @param array<string, mixed>  $values Cleaned values, by reference.
	 * @param array<string, string> $errors Errors, by reference.
	 */
	private static function planning_fields( array $input, array &$values, array &$errors ): void {
		foreach ( array( 'planned_start', 'planned_due', 'review_target', 'release_target' ) as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$date = trim( (string) $input[ $field ] );

			if ( '' === $date ) {
				$values[ $field ] = '';
				continue;
			}

			if ( ! self::is_date( $date ) ) {
				$errors[ $field ] = 'That is not a date. Use YYYY-MM-DD.';
				continue;
			}

			$values[ $field ] = $date;
		}

		if ( array_key_exists( 'remaining_estimate', $input ) ) {
			$hours = (float) $input['remaining_estimate'];

			if ( $hours < 0 ) {
				$errors['remaining_estimate'] = 'Hours cannot be negative.';
			} else {
				$values['remaining_estimate'] = $hours;
			}
		}

		if ( array_key_exists( 'planned_start', $values ) && array_key_exists( 'planned_due', $values )
			&& '' !== $values['planned_start'] && '' !== $values['planned_due']
			&& $values['planned_due'] < $values['planned_start'] ) {
			$errors['planned_due'] = 'Work cannot be due before it starts.';
		}
	}

	/**
	 * Whether a string is a real calendar date, not merely date-shaped. The
	 * check catches 2026-02-30, which a pattern match would not.
	 *
	 * @param string $date Candidate.
	 * @return bool
	 */
	private static function is_date( string $date ): bool {
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts ) ) {
			return false;
		}

		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
	}
}
