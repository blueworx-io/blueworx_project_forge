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
use Blueworx\Forge\Work\Outcomes;
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
		'sales'     => 'Sales',
		'finance'   => 'Finance',
		'deadline'  => 'Deadline',
		'other'     => 'Other',
	);

	/**
	 * The times a reminder may take each person, in hours (#573): the steps
	 * work is planned in everywhere else (HOUR_OPTIONS in src/hours.tsx),
	 * up to the five-hour most any one assignment takes. Nothing is the
	 * default, and counts as no time at all.
	 */
	public const TIMES = array( 0.0, 0.08, 0.17, 0.5, 0.75, 1.0, 1.5, 2.0, 2.5, 3.0, 4.0, 5.0 );

	/**
	 * What a time off the list is called.
	 */
	public const BAD_TIME = 'Pick a time from the list.';

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

		// How long it takes each person (#573). Left out, it takes nothing.
		if ( ! $partial || array_key_exists( 'hours_each', $input ) ) {
			$time = trim( (string) ( $input['hours_each'] ?? '' ) );
			$time = '' === $time ? '0' : $time;

			if ( ! is_numeric( $time ) || ! in_array( round( (float) $time, 2 ), self::TIMES, true ) ) {
				$errors['hours_each'] = self::BAD_TIME;
			} else {
				$values['hours_each'] = (string) round( (float) $time, 2 );
			}
		}

		// #611. Both dates are optional: either, both or neither.
		foreach ( array( 'starts_on', 'ends_on' ) as $date ) {
			if ( ! array_key_exists( $date, $input ) ) {
				if ( ! $partial ) {
					$values[ $date ] = '';
				}
				continue;
			}

			$value = trim( (string) ( $input[ $date ] ?? '' ) );

			if ( '' === $value ) {
				$values[ $date ] = '';
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

		if ( isset( $values['starts_on'], $values['ends_on'] ) && '' !== $values['starts_on'] && '' !== $values['ends_on'] && $values['ends_on'] < $values['starts_on'] ) {
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
	 * #611. With no dates the copy has none, and waits under No date in My
	 * tasks. With only an end it runs from today to that day.
	 *
	 * @param array<string, mixed> $source The reminder.
	 * @param string               $person Person id.
	 * @param string               $today  YYYY-MM-DD; the site's today when empty.
	 * @return array<string, mixed>
	 */
	public static function values( array $source, string $person, string $today = '' ): array {
		$description = trim( (string) $source['description'] );
		$starts      = (string) $source['starts_on'];
		$ends        = '' === (string) $source['ends_on'] ? $starts : (string) $source['ends_on'];

		if ( '' === $starts && '' !== $ends ) {
			$starts = min( '' === $today ? wp_date( 'Y-m-d' ) : $today, $ends );
		}

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
			// Everyone on it spends the full time (#573): thirty minutes for
			// two people is thirty minutes each. No time is no hours.
			'hours_each'       => round( (float) ( $source['hours_each'] ?? 0 ), 2 ),
			'checklist'        => '[]',
		);
	}

	/**
	 * The task a reminder is turned into (#574). Pure, so the shape can be
	 * tested.
	 *
	 * It carries the reminder's title, words, people and time. It is a task
	 * of its own, named by no source, so the reminder's later edits never
	 * reach it. An assignment is done in a day, so it is due on one: the
	 * reminder's first day, or today when that has passed, so it never starts
	 * out late.
	 *
	 * @param array<string, mixed> $source The reminder.
	 * @param array<int, string>   $people Who it is for.
	 * @param string               $today  YYYY-MM-DD.
	 * @return array<string, mixed>
	 */
	public static function task_values( array $source, array $people, string $today ): array {
		$first = self::values( $source, '' );
		$day   = max( (string) $source['starts_on'], $today );

		return array_merge(
			$first,
			array(
				'planned_start' => $day,
				'planned_due'   => $day,
				'recurring_id'  => '',
				'assignees'     => array_values( array_map( 'strval', $people ) ),
			)
		);
	}

	/**
	 * Whether a copy can be closed into a task (#574): open, not archived,
	 * and somewhere Cancelled can be reached from. A Blocked copy cannot.
	 * Pure, so the rule can be tested.
	 *
	 * @param array<string, mixed> $item A copy.
	 * @return bool
	 */
	public static function closable( array $item ): bool {
		return empty( $item['archived'] )
			&& '' === (string) ( $item['terminal_outcome'] ?? '' )
			&& Outcomes::reachable_from( Outcomes::CANCELLED, (string) ( $item['stage'] ?? '' ) );
	}

	/**
	 * The task a converted copy went into, or ''. Pure.
	 *
	 * @param array<string, mixed> $item A copy.
	 * @return string
	 */
	public static function converted_to( array $item ): string {
		return Outcomes::CANCELLED === (string) ( $item['terminal_outcome'] ?? '' ) ? (string) ( $item['duplicate_of'] ?? '' ) : '';
	}

	/**
	 * Whether a reminder was turned into a task (#612): a copy went into one
	 * and nobody has one still to do. Pure, so the rule can be tested.
	 *
	 * @param array<int, array<string, mixed>> $copies Its copies.
	 * @return bool
	 */
	public static function converted( array $copies ): bool {
		$moved = false;

		foreach ( $copies as $item ) {
			if ( '' !== self::converted_to( $item ) ) {
				$moved = true;
			} elseif ( array() === (array) $item['ticks'] && '' === (string) ( $item['terminal_outcome'] ?? '' ) ) {
				return false;
			}
		}

		return $moved;
	}

	/**
	 * Turns a reminder's open copies into one task for the people they
	 * belong to (#574), and closes those copies into it.
	 *
	 * #612: the reminder then leaves reminders altogether. It is ended, not
	 * deleted, so copies already ticked stay as they were, done, with a
	 * source. Only when somebody's copy could not close does it stay, so
	 * they still have it.
	 *
	 * Nothing is changed until every open copy is known to close, so a
	 * Blocked copy refuses the whole thing rather than leaving one person
	 * counted twice. The reminder is claimed at the version it was read at,
	 * so two converts at once make one task. The task is made before any
	 * copy closes, so a failure never leaves closed copies with no task; a
	 * copy that still fails to close takes its person off the task again.
	 *
	 * @param array<string, mixed> $source The reminder.
	 * @param int                  $actor  WordPress user id doing it.
	 * @param string               $today  YYYY-MM-DD; the site's today when empty.
	 * @return array{code: string, task?: array<string, mixed>, people?: array<int, string>}
	 *         code '' with the task; or 'nothing_open', 'stuck' (with the
	 *         people whose copies cannot close), 'busy', 'write_failed', or
	 *         'partial' (with the task and the people left on the reminder).
	 */
	public static function convert( array $source, int $actor, string $today = '' ): array {
		$today = '' === $today ? wp_date( 'Y-m-d' ) : $today;
		$id    = (string) $source['id'];
		$open  = array();
		$stuck = array();

		foreach ( self::copies_for( array( $id ) )[ $id ] ?? array() as $item ) {
			if ( array() !== (array) $item['ticks'] || '' !== (string) ( $item['terminal_outcome'] ?? '' ) ) {
				continue;
			}

			$person = (string) ( ( (array) $item['assignees'] )[0] ?? '' );

			if ( ! self::closable( $item ) ) {
				$stuck[] = $person;
				continue;
			}

			$open[ $person ] = $item;
		}

		if ( array() !== $stuck ) {
			return array(
				'code'   => 'stuck',
				'people' => $stuck,
			);
		}

		if ( array() === $open ) {
			return array( 'code' => 'nothing_open' );
		}

		// The row is the claim, as a recurring day's is: one per version read.
		$claim = 'cv' . (int) $source['record_version'];

		if ( ! Occurrences::claim( $id, $claim ) ) {
			return array( 'code' => 'busy' );
		}

		$task = Items::create( (string) $source['client_site_id'], (string) $source['client_id'], self::task_values( $source, array_map( 'strval', array_keys( $open ) ), $today ), $actor, true );

		if ( null !== $task ) {
			Transition::record_creation( $task, $actor );
		}

		$placed = null === $task ? null : Transition::place( $task, Stages::UP_NEXT, $actor, __( 'Turned into a task from a reminder.', 'blueworx-forge' ) );

		if ( ! is_array( $placed ) ) {
			if ( null !== $task ) {
				Items::delete( (string) $task['id'] );
			}

			Occurrences::release( $id, $claim );

			return array( 'code' => 'write_failed' );
		}

		$task = $placed;
		Occurrences::record_item( $id, $claim, (string) $task['id'] );

		$closed = array();
		$left   = array();

		foreach ( $open as $person => $item ) {
			// Cancelled, naming the task, so its time is not counted beside
			// the task's and the reminder can say where it went.
			$ended = Transition::end(
				$item,
				Outcomes::CANCELLED,
				array(
					'reason'       => __( 'Turned into a task.', 'blueworx-forge' ),
					'duplicate_of' => (string) $task['id'],
				),
				(int) $item['record_version'],
				$actor
			);

			if ( ! is_array( $ended ) ) {
				$left[] = (string) $person;
				continue;
			}

			// Ticked too, so My tasks and a later edit leave it alone.
			Items::tick( (string) $item['id'], (string) $person, true );
			$closed[] = (string) $person;
		}

		if ( array() === $left ) {
			Sources::end( $id );

			return array(
				'code' => '',
				'task' => $task,
			);
		}

		// A later convert, for those left, claims afresh.
		Sources::update( $id, array(), (int) $source['record_version'] );

		if ( array() === $closed ) {
			Items::delete( (string) $task['id'] );

			return array( 'code' => 'write_failed' );
		}

		return array(
			'code'   => 'partial',
			'task'   => (array) Items::set_assignees( (string) $task['id'], $closed ),
			'people' => $left,
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
		// A reminder with no start date (#611) still claims its one making.
		$claim = '' === (string) $source['starts_on'] ? 'undated' : (string) $source['starts_on'];

		if ( ! Occurrences::claim( (string) $source['id'], $claim ) ) {
			return 0;
		}

		$made = 0;

		foreach ( (array) $source['assignees'] as $person ) {
			$item = self::add( $source, (string) $person );

			if ( null === $item ) {
				continue;
			}

			if ( 0 === $made ) {
				Occurrences::record_item( (string) $source['id'], $claim, (string) $item['id'] );
			}

			++$made;
		}

		return $made;
	}

	/**
	 * Each reminder's copies still on the board, keyed by reminder id.
	 *
	 * @param array<int, string> $ids      Reminder ids.
	 * @param bool               $archived Archived copies too.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function copies_for( array $ids, bool $archived = false ): array {
		global $wpdb;

		$wanted = array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) );

		if ( array() === $wanted ) {
			return array();
		}

		$table = Schema::work_items_table();
		$slots = implode( ', ', array_fill( 0, count( $wanted ), '%s' ) );

		$where = $archived ? '' : 'archived = 0 AND ';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name and filter are literals; the slots are counted above.
		$found = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE {$where}recurring_id IN ({$slots}) ORDER BY created_at ASC", $wanted ) );
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
	 * Ticked copies are the record of what was done and are left alone, and
	 * so are closed ones, a copy turned into a task among them (#574).
	 * Archived copies still count as that person's, so archiving one never
	 * brings a fresh copy back.
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

		foreach ( self::copies_for( array( (string) $source['id'] ), true )[ (string) $source['id'] ] ?? array() as $item ) {
			$person = (string) ( ( (array) $item['assignees'] )[0] ?? '' );
			$have[] = $person;

			if ( array() !== (array) $item['ticks'] || ! empty( $item['archived'] ) || '' !== (string) ( $item['terminal_outcome'] ?? '' ) ) {
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
				continue;
			}

			// The time (#573) is set on a copy only here, never by an edit to it.
			if ( (float) $updated['hours_each'] !== (float) $values['hours_each'] && null === Items::set_hours_each( (string) $item['id'], (float) $values['hours_each'] ) ) {
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
			if ( array() === (array) $item['ticks'] && '' === (string) ( $item['terminal_outcome'] ?? '' ) && 0 === Items::delete( (string) $item['id'] ) ) {
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
		Transition::place( $item, Stages::UP_NEXT, $author, '' === (string) $source['starts_on'] ? __( 'Reminder.', 'blueworx-forge' ) : sprintf( __( 'Reminder for %s', 'blueworx-forge' ), (string) $source['starts_on'] ) );

		return $item;
	}
}
