<?php
/**
 * What a recurring task definition may say.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Recurring;

use Blueworx\Forge\Work\Types;

/**
 * The same shape as every other validator here: raw input in, cleaned values
 * and errors out, and a partial edit may mention only what it changes.
 *
 * The seats follow Work\Validate's rule — a person id or nothing — and are
 * all optional, because a recurring task with no reviewer yet is still worth
 * scheduling; the Up Next gate asks for the seats when the task tries to
 * leave, not when it arrives.
 */
final class Validate {

	/**
	 * Longest title.
	 */
	private const MAX_TITLE = 160;

	/**
	 * Checks a source.
	 *
	 * @param array<string, mixed> $input   Raw input.
	 * @param bool                 $partial True for an edit.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function source( array $input, bool $partial ): array {
		$values = array();
		$errors = array();

		if ( ! $partial || array_key_exists( 'title', $input ) ) {
			$title = trim( (string) ( $input['title'] ?? '' ) );

			if ( '' === $title ) {
				$errors['title'] = 'A recurring task needs a title.';
			} elseif ( mb_strlen( $title ) > self::MAX_TITLE ) {
				$errors['title'] = 'That title is too long.';
			} else {
				$values['title'] = $title;
			}
		}

		if ( array_key_exists( 'description', $input ) ) {
			$values['description'] = trim( (string) $input['description'] );
		}

		if ( ! $partial || array_key_exists( 'work_type', $input ) ) {
			$type = trim( (string) ( $input['work_type'] ?? Types::TASK ) );

			if ( ! in_array( $type, Types::ALL, true ) ) {
				$errors['work_type'] = 'That is not a kind of work.';
			} else {
				$values['work_type'] = $type;
			}
		}

		if ( ! $partial || array_key_exists( 'rule', $input ) ) {
			$rule = Rule::normalise( is_array( $input['rule'] ?? null ) ? $input['rule'] : array() );

			if ( null === $rule ) {
				$errors['rule'] = 'Pick every day, days of the week, or a day of the month.';
			} else {
				$values['rule'] = $rule;
			}
		}

		foreach ( array( 'primary_user_id', 'reviewer_id', 'deliverer_id' ) as $seat ) {
			if ( ! array_key_exists( $seat, $input ) ) {
				continue;
			}

			$id = trim( (string) $input[ $seat ] );

			if ( '' !== $id && 1 !== preg_match( '/^usr_[A-Za-z0-9]+$/', $id ) ) {
				$errors[ $seat ] = 'That is not a person.';
				continue;
			}

			$values[ $seat ] = $id;
		}

		/*
		 * Who does it (2026-09-18): one or more people, each real, and the
		 * hours each of them spends. A schedule is for somebody; one for
		 * nobody would make tasks nobody sees.
		 */
		if ( ! $partial || array_key_exists( 'assignees', $input ) ) {
			$assignees = array();
			$bad       = false;

			foreach ( (array) ( $input['assignees'] ?? array() ) as $id ) {
				$id = trim( (string) $id );

				if ( '' === $id ) {
					continue;
				}

				if ( 1 !== preg_match( '/^usr_[A-Za-z0-9]+$/', $id ) ) {
					$bad = true;
					break;
				}

				$assignees[ $id ] = $id;
			}

			if ( $bad ) {
				$errors['assignees'] = 'That is not a person.';
			} elseif ( array() === $assignees && ! $partial && '' === trim( (string) ( $input['primary_user_id'] ?? '' ) ) ) {
				$errors['assignees'] = 'Choose at least one person.';
			} else {
				$values['assignees'] = array_values( $assignees );
			}
		}

		if ( array_key_exists( 'hours_each', $input ) ) {
			$figure = '' === trim( (string) $input['hours_each'] ) ? 0.0 : (float) $input['hours_each'];

			if ( $figure < 0 || ( ! is_numeric( $input['hours_each'] ) && '' !== trim( (string) $input['hours_each'] ) ) ) {
				$errors['hours_each'] = 'Hours are a number, zero or more.';
			} else {
				$values['hours_each'] = (string) round( $figure, 2 );
			}
		}

		foreach ( array( 'hours_primary', 'hours_review', 'hours_delivery' ) as $hours ) {
			if ( ! array_key_exists( $hours, $input ) ) {
				continue;
			}

			$figure = '' === trim( (string) $input[ $hours ] ) ? 0.0 : (float) $input[ $hours ];

			if ( $figure < 0 || ( ! is_numeric( $input[ $hours ] ) && '' !== trim( (string) $input[ $hours ] ) ) ) {
				$errors[ $hours ] = 'Hours are a number, zero or more.';
			} else {
				$values[ $hours ] = (string) round( $figure, 2 );
			}
		}

		foreach ( array( 'starts_on', 'ends_on' ) as $date ) {
			if ( ! array_key_exists( $date, $input ) ) {
				continue;
			}

			$value = trim( (string) $input[ $date ] );

			if ( '' === $value ) {
				if ( 'ends_on' === $date ) {
					$values[ $date ] = '';
				} else {
					$values[ $date ] = wp_date( 'Y-m-d' );
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
			$errors['ends_on'] = 'It cannot end before it starts.';
		}

		if ( array_key_exists( 'status', $input ) ) {
			$status = trim( (string) $input['status'] );

			if ( ! in_array( $status, array( Sources::ACTIVE, Sources::PAUSED ), true ) ) {
				$errors['status'] = 'A recurring task is active or paused; ending it is its own action.';
			} else {
				$values['status'] = $status;
			}
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}
}
