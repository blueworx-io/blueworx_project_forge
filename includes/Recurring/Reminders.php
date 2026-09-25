<?php
/**
 * Reminders: a task on a fixed day or period.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Recurring;

use Blueworx\Forge\Work\Fields;

/**
 * Luke, 2026-09-25: "recurring tasks that don't have a recurrence, they are
 * for a fixed date or period." A reminder is a source of its own kind in the
 * recurring table, with no rule and no next day. Saving it makes one task
 * per person there and then, so each sees it ahead of time; those tasks are
 * ticked off exactly as a recurring task's are.
 */
final class Reminders {

	/**
	 * Longest title.
	 */
	private const MAX_TITLE = 160;

	/**
	 * What an end before the start is called.
	 */
	public const ENDS_EARLY = 'The end date is before the start date.';

	/**
	 * Checks a reminder. The client is checked by the route, which knows the
	 * caller's reach.
	 *
	 * @param array<string, mixed> $input   Raw input.
	 * @param bool                 $partial True for an edit.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function validate( array $input, bool $partial ): array {
		$values = array();
		$errors = array();

		if ( ! $partial || array_key_exists( 'title', $input ) ) {
			$title = trim( (string) ( $input['title'] ?? '' ) );

			if ( '' === $title ) {
				$errors['title'] = 'A reminder needs a title.';
			} elseif ( mb_strlen( $title ) > self::MAX_TITLE ) {
				$errors['title'] = 'That title is too long.';
			} else {
				$values['title'] = $title;
			}
		}

		// Notes are optional.
		if ( array_key_exists( 'description', $input ) ) {
			$values['description'] = trim( wp_kses( (string) $input['description'], Fields::ALLOWED_HTML ) );
		}

		if ( ! $partial || array_key_exists( 'assignees', $input ) ) {
			$people = array();
			$bad    = false;

			foreach ( (array) ( $input['assignees'] ?? array() ) as $id ) {
				$id = trim( (string) $id );

				if ( '' === $id ) {
					continue;
				}

				if ( 1 !== preg_match( '/^usr_[A-Za-z0-9]+$/', $id ) ) {
					$bad = true;
					break;
				}

				$people[ $id ] = $id;
			}

			if ( $bad ) {
				$errors['assignees'] = 'That is not a person.';
			} elseif ( array() === $people ) {
				$errors['assignees'] = 'Choose at least one person.';
			} else {
				$values['assignees'] = array_values( $people );
			}
		}

		foreach ( array( 'starts_on', 'ends_on' ) as $date ) {
			if ( ! array_key_exists( $date, $input ) && ( $partial || 'ends_on' === $date ) ) {
				continue;
			}

			$value = trim( (string) ( $input[ $date ] ?? '' ) );

			if ( '' === $value ) {
				if ( 'ends_on' === $date ) {
					$values['ends_on'] = '';
				} else {
					$errors['starts_on'] = 'Say the day.';
				}
				continue;
			}

			if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) || false === strtotime( $value ) ) {
				$errors[ $date ] = 'That is not a date.';
			} else {
				$values[ $date ] = $value;
			}
		}

		if ( isset( $values['starts_on'], $values['ends_on'] ) && '' !== $values['ends_on'] && $values['ends_on'] < $values['starts_on'] ) {
			$errors['ends_on'] = self::ENDS_EARLY;
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * Whether a work item's source id is a reminder's.
	 *
	 * @param string $recurring_id The item's recurring_id.
	 * @return bool
	 */
	public static function is_reminder( string $recurring_id ): bool {
		return 0 === strpos( $recurring_id, Sources::REMINDER_PREFIX . '_' );
	}

	/**
	 * One person's copy. Pure, so the shape can be tested.
	 *
	 * @param array<string, mixed> $source The reminder.
	 * @param string               $person Person id.
	 * @return array<string, mixed>
	 */
	public static function values( array $source, string $person ): array {
		$description = trim( (string) $source['description'] );
		$starts      = (string) $source['starts_on'];
		$ends        = '' === (string) $source['ends_on'] ? $starts : (string) $source['ends_on'];

		return array(
			'title'            => (string) $source['title'],
			'problem'          => '' === $description ? (string) $source['title'] : $description,
			'level'            => 'sub-feature',
			'work_type'        => 'task',
			'priority'         => 'normal',
			'planned_start'    => $starts,
			'planned_due'      => $ends,
			// Free, like a recurring task: nobody pays for a reminder.
			'commercial_class' => 'free-general',
			'recurring_id'     => (string) $source['id'],
			'assignees'        => array( $person ),
			'hours_each'       => 0.0,
			'checklist'        => '[]',
		);
	}
}
