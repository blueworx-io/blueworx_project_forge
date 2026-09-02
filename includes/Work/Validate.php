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
	 * What a client sent when they asked for something (#129).
	 *
	 * Everything a client may not decide is refused rather than dropped. The
	 * difference matters: a dropped field lets somebody believe they set the
	 * intake state, and find out weeks later that nobody read it as accepted.
	 * A refusal is an answer.
	 *
	 * Nothing here names which client the submission is for. The site that
	 * signed the request is the only site it can be from, so a client_id in the
	 * body would be whatever the sender typed (D-2).
	 *
	 * @param array<string, mixed> $input What the client site sent.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function submission( array $input ): array {
		$values = array();
		$errors = array();

		foreach ( array( 'intake_state', 'response', 'converted_item_id' ) as $ours ) {
			if ( array_key_exists( $ours, $input ) ) {
				$errors[ $ours ] = 'That is the studio\'s answer, not part of the question.';
			}
		}

		foreach ( array( 'client_id', 'client_site_id' ) as $named ) {
			if ( array_key_exists( $named, $input ) ) {
				$errors[ $named ] = 'A submission is for the site that sent it, and cannot name another.';
			}
		}

		$type = trim( (string) ( $input['type'] ?? '' ) );

		if ( ! Submissions::is_type( $type ) ) {
			$errors['type'] = 'Choose a bug, a request, an idea or a suggestion.';
		} else {
			$values['type'] = $type;
		}

		$title = trim( (string) ( $input['title'] ?? '' ) );

		if ( '' === $title ) {
			$errors['title'] = 'Give this a short title.';
		} elseif ( mb_strlen( $title ) > self::MAX_TITLE ) {
			$errors['title'] = 'That title is too long.';
		} else {
			$values['title'] = $title;
		}

		$description = trim( (string) ( $input['description'] ?? '' ) );

		if ( '' === $description ) {
			$errors['description'] = 'Say what you are asking for.';
		} else {
			$values['description'] = $description;
		}

		// Optional, and optional on purpose. Somebody who knows what they want
		// but not what good would look like should still be able to ask.
		$values['desired_outcome'] = trim( (string) ( $input['desired_outcome'] ?? '' ) );
		$values['evidence']        = trim( (string) ( $input['evidence'] ?? '' ) );

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

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

		self::seats( $input, $values, $errors );
		self::text_fields( $input, $values );
		self::enum_fields( $input, $values, $errors );
		self::planning_fields( $input, $values, $errors );

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * Who the item names: the Primary User, the Reviewer, the Deliverer, and
	 * the two substitute seats.
	 *
	 * The shape is checked here and the *existence* of the person is not — that
	 * needs the database, and Work\Validate deliberately does not have one. The
	 * caller confirms the user is real and belongs to this client, for the same
	 * reason it confirms a parent does.
	 *
	 * Clearing a seat is a real answer, so an empty string is stored rather than
	 * refused: somebody leaves, and the seat is empty until it is filled.
	 *
	 * @param array<string, mixed>  $input  Raw input.
	 * @param array<string, mixed>  $values Cleaned values, by reference.
	 * @param array<string, string> $errors Errors, by reference.
	 */
	private static function seats( array $input, array &$values, array &$errors ): void {
		/*
		 * The hours live inside the accountability group so that "may this
		 * person set the accountability fields" stays one question, which is
		 * right. It also walked them into this loop, where every field is
		 * required to be a person id — so no hours figure could be written at
		 * all. They have rules of their own in planning_fields(); here they are
		 * simply not seats.
		 */
		$seats = array_merge( array_diff( Fields::ACCOUNTABILITY, Fields::HOURS ), Fields::SUBSTITUTES );

		foreach ( $seats as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$id = trim( (string) $input[ $field ] );

			if ( '' === $id ) {
				$values[ $field ] = '';
				continue;
			}

			if ( 1 !== preg_match( '/^usr_[A-Za-z0-9]+$/', $id ) ) {
				$errors[ $field ] = 'That is not a person.';
				continue;
			}

			$values[ $field ] = $id;
		}

		/*
		 * AUTH-3. The Reviewer is somebody other than the Primary User unless
		 * they hold the Principal grant — and the grant is a fact about a
		 * person rather than about this item, so the caller applies it. What is
		 * checked here is the plain case: the same person in both seats, with
		 * nothing said about why, is refused.
		 */
		$primary  = (string) ( $values['primary_user_id'] ?? '' );
		$reviewer = (string) ( $values['reviewer_id'] ?? '' );

		if ( '' !== $primary && $primary === $reviewer && empty( $input['self_review_permitted'] ) ) {
			$errors['reviewer_id'] = 'A reviewer has to be somebody other than the person who did the work.';
		}
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

		/*
		 * Every field measured in hours, checked the same way. Negative hours
		 * are the only thing refused: zero is a real answer — "this seat has no
		 * planned time" — and a cap would be a guess about how long work takes.
		 */
		foreach ( array_merge( array( 'remaining_estimate' ), Fields::HOURS ) as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$hours = (float) $input[ $field ];

			if ( $hours < 0 ) {
				$errors[ $field ] = 'Hours cannot be negative.';
			} else {
				$values[ $field ] = $hours;
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
