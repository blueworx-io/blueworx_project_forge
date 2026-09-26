<?php
/**
 * Reminders: a task on a fixed day or period.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Recurring;

use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Work\Fields;
use Blueworx\Forge\Work\Items;
use Blueworx\Forge\Work\Stages;
use Blueworx\Forge\Work\Transition;

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
	 * What kind of reminder it is (2026-09-25), and what each is called.
	 */
	public const CATEGORIES = array(
		'general'   => 'General',
		'campaign'  => 'Campaign',
		'marketing' => 'Marketing',
		'deadline'  => 'Deadline',
		'other'     => 'Other',
	);

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

		// Its type (2026-09-25): General unless somebody picks another.
		if ( ! $partial || array_key_exists( 'category', $input ) ) {
			$category = trim( (string) ( $input['category'] ?? 'general' ) );

			if ( ! array_key_exists( $category, self::CATEGORIES ) ) {
				$errors['category'] = 'Pick a type.';
			} else {
				$values['category'] = $category;
			}
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

			// checkdate() rather than strtotime(), which rolls 2026-02-31 over
			// into March and calls it a date.
			if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
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
			// The title is plain text, not trusted HTML like a real
			// description (2026-09-25): escape it before it stands in.
			'problem'          => '' === $description ? '<p>' . esc_html( (string) $source['title'] ) . '</p>' : $description,
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

	/**
	 * Makes a new reminder's copies, one per person, once. The start day is
	 * claimed first, so two saves racing make one set.
	 *
	 * @param array<string, mixed> $source The reminder.
	 * @return int How many copies were made.
	 */
	public static function make( array $source ): int {
		if ( ! Occurrences::claim( (string) $source['id'], (string) $source['starts_on'] ) ) {
			return 0;
		}

		$made = 0;

		foreach ( (array) $source['assignees'] as $person ) {
			$item = self::add( $source, (string) $person );

			if ( null === $item ) {
				continue;
			}

			if ( 0 === $made ) {
				Occurrences::record_item( (string) $source['id'], (string) $source['starts_on'], (string) $item['id'] );
			}

			++$made;
		}

		return $made;
	}

	/**
	 * Each reminder's copies still on the board, keyed by reminder id.
	 *
	 * @param array<int, string> $ids Reminder ids.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function copies_for( array $ids ): array {
		global $wpdb;

		$wanted = array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) );

		if ( array() === $wanted ) {
			return array();
		}

		$table = Schema::work_items_table();
		$slots = implode( ', ', array_fill( 0, count( $wanted ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name cannot be a placeholder; the slots are counted above.
		$found = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE archived = 0 AND recurring_id IN ({$slots}) ORDER BY created_at ASC", $wanted ) );
		$out   = array_fill_keys( $wanted, array() );

		foreach ( is_array( $found ) ? $found : array() as $id ) {
			$item = Items::get( (string) $id );

			if ( null !== $item ) {
				$out[ (string) $item['recurring_id'] ][] = $item;
			}
		}

		return $out;
	}

	/**
	 * Brings the copies nobody has ticked in line with an edited reminder: new
	 * words and dates, a copy for anyone added, none for anyone removed.
	 * Ticked copies are the record of what was done and are left alone.
	 *
	 * @param array<string, mixed> $source The reminder as it now stands.
	 * @return bool True only if every write this attempted landed. A copy
	 *              somebody else changed between the read and the write is
	 *              left as it was rather than overwritten, and reported here
	 *              so the caller can say so rather than answering as if it
	 *              worked.
	 */
	public static function sync( array $source ): bool {
		$people = array_map( 'strval', (array) $source['assignees'] );
		$have   = array();
		$clean  = true;

		foreach ( self::copies_for( array( (string) $source['id'] ) )[ (string) $source['id'] ] ?? array() as $item ) {
			$person = (string) ( ( (array) $item['assignees'] )[0] ?? '' );
			$have[] = $person;

			if ( array() !== (array) $item['ticks'] ) {
				continue;
			}

			if ( ! in_array( $person, $people, true ) ) {
				if ( 0 === Items::delete( (string) $item['id'] ) ) {
					$clean = false;
				}

				continue;
			}

			$values = self::values( $source, $person );

			$updated = Items::update(
				(string) $item['id'],
				array_intersect_key( $values, array_flip( array( 'title', 'problem', 'planned_start', 'planned_due' ) ) ),
				(int) $item['record_version']
			);

			if ( null === $updated ) {
				$clean = false;
			}
		}

		foreach ( array_diff( $people, $have ) as $person ) {
			if ( null === self::add( $source, $person ) ) {
				$clean = false;
			}
		}

		return $clean;
	}

	/**
	 * Deletes a reminder: its unticked copies go, ticked ones stay, and the
	 * reminder itself is ended rather than removed so they keep a source.
	 *
	 * The source is ended whether or not every copy's delete landed —
	 * deleting is what the person asked for, and a copy that changed
	 * underneath them is reported rather than left blocking the reminder
	 * itself from going away.
	 *
	 * @param array<string, mixed> $source The reminder.
	 * @return bool True only if every copy that needed deleting was deleted.
	 */
	public static function remove( array $source ): bool {
		$clean = true;

		foreach ( self::copies_for( array( (string) $source['id'] ) )[ (string) $source['id'] ] ?? array() as $item ) {
			if ( array() === (array) $item['ticks'] && 0 === Items::delete( (string) $item['id'] ) ) {
				$clean = false;
			}
		}

		Sources::end( (string) $source['id'] );

		return $clean;
	}

	/**
	 * One person's copy, made and placed at Up Next.
	 *
	 * @param array<string, mixed> $source The reminder.
	 * @param string               $person Person id.
	 * @return array<string, mixed>|null
	 */
	private static function add( array $source, string $person ): ?array {
		$author = (int) ( $source['created_by'] ?? 0 );
		$item   = Items::create( (string) $source['client_site_id'], (string) $source['client_id'], self::values( $source, $person ), $author, true );

		if ( null === $item ) {
			return null;
		}

		Transition::record_creation( $item, $author );
		/* translators: %s: a date */
		Transition::place( $item, Stages::UP_NEXT, $author, sprintf( __( 'Reminder for %s', 'blueworx-forge' ), (string) $source['starts_on'] ) );

		return $item;
	}
}
