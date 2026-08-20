<?php
/**
 * The one filter model every view sits behind.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

/**
 * #123, and the reason #124 is nearly true by construction: there is one thing
 * that decides what a filter set means, so two views cannot disagree about it.
 *
 * The alternative is each view filtering the list it was handed. That is how a
 * board and a list come to show different counts for the same filters, and how
 * the difference survives review — each looks right on its own, and nothing
 * compares them.
 *
 * **A filter is a closed list, both in its name and in its values.** A view
 * that could name its own filter could name a column, and a filter is a query.
 * Anything unrecognised is dropped rather than applied: a saved view naming a
 * stage that has since been renamed should show the rest of what it asked for,
 * not everything or nothing.
 *
 * **A saved view changes what is shown and never what is allowed.** It holds a
 * name, some filters and a grouping. Nothing else survives being stored — a
 * view that could carry a capability would be a way of granting oneself one by
 * saving a view and opening it.
 */
final class Filters {

	/**
	 * The filters whose values come from a closed list, and where that list is.
	 *
	 * @var array<string, string>
	 */
	private const FROM_LIST = array(
		'stage'            => 'stage',
		'level'            => 'level',
		'work_type'        => 'work_type',
		'priority'         => 'priority',
		'commercial_class' => 'commercial_class',
		'terminal_outcome' => 'terminal_outcome',
	);

	/**
	 * The filters whose values are ids: anything, but matched exactly.
	 */
	private const BY_ID = array( 'person', 'parent_id' );

	/**
	 * The filters that take a single free value rather than a set.
	 */
	private const SINGLE = array( 'search', 'due_from', 'due_to', 'start_from', 'start_to' );

	/**
	 * What a view may be grouped by.
	 *
	 * The same closed-list rule: a grouping names a column, and a caller does
	 * not get to name one.
	 */
	public const GROUPINGS = array( 'stage', 'level', 'work_type', 'priority', 'commercial_class', 'parent_id' );

	/**
	 * The seats the person filter looks at.
	 */
	private const SEATS = array( 'primary_user_id', 'reviewer_id', 'deliverer_id' );

	/**
	 * What a saved view is allowed to hold.
	 */
	public const VIEW_KEYS = array( 'name', 'filters', 'grouping' );

	/**
	 * Reads a filter set, keeping only what is real.
	 *
	 * @param array<string, mixed> $input Whatever arrived.
	 * @return array<string, mixed>
	 */
	public static function sanitise( array $input ): array {
		$filters = array();

		foreach ( self::FROM_LIST as $filter => $vocabulary ) {
			if ( ! array_key_exists( $filter, $input ) ) {
				continue;
			}

			$values = self::keep( (array) $input[ $filter ], $vocabulary );

			if ( array() !== $values ) {
				$filters[ $filter ] = $values;
			}
		}

		foreach ( self::BY_ID as $filter ) {
			if ( ! array_key_exists( $filter, $input ) ) {
				continue;
			}

			$values = array_values( array_filter( array_map( 'strval', (array) $input[ $filter ] ), 'strlen' ) );

			if ( array() !== $values ) {
				$filters[ $filter ] = $values;
			}
		}

		foreach ( self::SINGLE as $filter ) {
			if ( ! array_key_exists( $filter, $input ) ) {
				continue;
			}

			$value = trim( (string) $input[ $filter ] );

			if ( '' !== $value ) {
				$filters[ $filter ] = $value;
			}
		}

		if ( array_key_exists( 'archived', $input ) ) {
			$filters['archived'] = (bool) $input['archived'];
		}

		return $filters;
	}

	/**
	 * Applies a filter set.
	 *
	 * Two different filters both have to match; several values within one filter
	 * match any of them. "Bugs in review" is not "bugs or things in review", and
	 * "triage or up next" is one question rather than two views.
	 *
	 * @param array<int, array<string, mixed>> $items   The items.
	 * @param array<string, mixed>             $filters A sanitised filter set.
	 * @return array<int, array<string, mixed>>
	 */
	public static function apply( array $items, array $filters ): array {
		$kept = array();

		foreach ( $items as $item ) {
			if ( self::matches( $item, $filters ) ) {
				$kept[] = $item;
			}
		}

		return $kept;
	}

	/**
	 * Arranges items into groups, without removing any.
	 *
	 * @param array<int, array<string, mixed>> $items    The items.
	 * @param string                           $grouping A grouping, or ''.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function group( array $items, string $grouping ): array {
		$grouping = self::grouping( $grouping );

		if ( '' === $grouping ) {
			return array( '' => $items );
		}

		$groups = array();

		foreach ( $items as $item ) {
			$groups[ (string) ( $item[ $grouping ] ?? '' ) ][] = $item;
		}

		return $groups;
	}

	/**
	 * A grouping, or '' when it is not one.
	 *
	 * @param string $grouping Candidate.
	 * @return string
	 */
	public static function grouping( string $grouping ): string {
		return in_array( $grouping, self::GROUPINGS, true ) ? $grouping : '';
	}

	/**
	 * A saved view as it may be stored, or null when it cannot be.
	 *
	 * @param array<string, mixed> $input Whatever arrived.
	 * @return array<string, mixed>|null
	 */
	public static function view_for_storage( array $input ): ?array {
		$name = trim( (string) ( $input['name'] ?? '' ) );

		// A view nobody can pick out of a list is not a view.
		if ( '' === $name ) {
			return null;
		}

		/*
		 * Built key by key rather than by removing what is not wanted. The two
		 * read the same and are not: a deny list has to be kept up to date with
		 * every field anybody adds, and the day it is not is the day a saved
		 * view carries something it should not.
		 */
		return array(
			'name'     => mb_substr( $name, 0, 120 ),
			'filters'  => self::sanitise( (array) ( $input['filters'] ?? array() ) ),
			'grouping' => self::grouping( (string) ( $input['grouping'] ?? '' ) ),
		);
	}

	/**
	 * Whether one item matches a filter set.
	 *
	 * @param array<string, mixed> $item    The item.
	 * @param array<string, mixed> $filters A sanitised filter set.
	 * @return bool
	 */
	private static function matches( array $item, array $filters ): bool {
		foreach ( self::FROM_LIST as $filter => $ignored ) {
			if ( isset( $filters[ $filter ] ) && ! in_array( (string) ( $item[ $filter ] ?? '' ), (array) $filters[ $filter ], true ) ) {
				return false;
			}
		}

		if ( isset( $filters['parent_id'] ) && ! in_array( (string) ( $item['parent_id'] ?? '' ), (array) $filters['parent_id'], true ) ) {
			return false;
		}

		if ( isset( $filters['person'] ) && ! self::holds_a_seat( $item, (array) $filters['person'] ) ) {
			return false;
		}

		if ( isset( $filters['archived'] ) && (bool) ( $item['archived'] ?? false ) !== (bool) $filters['archived'] ) {
			return false;
		}

		if ( isset( $filters['search'] ) && ! self::mentions( $item, (string) $filters['search'] ) ) {
			return false;
		}

		return self::within_dates( $item, $filters );
	}

	/**
	 * Whether an item names any of these people in any of its three seats.
	 *
	 * One filter rather than three, because "my work" is one question and
	 * answering it with three views makes somebody check all of them.
	 *
	 * @param array<string, mixed> $item   The item.
	 * @param array<int, string>   $people The people asked about.
	 * @return bool
	 */
	private static function holds_a_seat( array $item, array $people ): bool {
		foreach ( self::SEATS as $seat ) {
			if ( in_array( (string) ( $item[ $seat ] ?? '' ), $people, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an item mentions a phrase.
	 *
	 * The title and the problem, which is what somebody would have typed. Not
	 * every field: a search that matches an id pasted into a note is a search
	 * nobody can predict the results of.
	 *
	 * @param array<string, mixed> $item   The item.
	 * @param string               $phrase What was searched for.
	 * @return bool
	 */
	private static function mentions( array $item, string $phrase ): bool {
		$haystack = mb_strtolower( (string) ( $item['title'] ?? '' ) . ' ' . (string) ( $item['problem'] ?? '' ) );

		return false !== mb_strpos( $haystack, mb_strtolower( $phrase ) );
	}

	/**
	 * Whether an item's dates fall in the range asked for.
	 *
	 * Both ends are included: somebody asking for September means the whole of
	 * September. Work with no date is in no range at all — it is unplanned,
	 * which is a different question and gets a different filter.
	 *
	 * @param array<string, mixed> $item    The item.
	 * @param array<string, mixed> $filters A sanitised filter set.
	 * @return bool
	 */
	private static function within_dates( array $item, array $filters ): bool {
		$ranges = array(
			'planned_due'   => array( 'due_from', 'due_to' ),
			'planned_start' => array( 'start_from', 'start_to' ),
		);

		foreach ( $ranges as $field => $bounds ) {
			list( $from, $to ) = $bounds;

			if ( ! isset( $filters[ $from ] ) && ! isset( $filters[ $to ] ) ) {
				continue;
			}

			$date = (string) ( $item[ $field ] ?? '' );

			if ( '' === $date ) {
				return false;
			}

			if ( isset( $filters[ $from ] ) && $date < (string) $filters[ $from ] ) {
				return false;
			}

			if ( isset( $filters[ $to ] ) && $date > (string) $filters[ $to ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The values of a filter that are real, according to its own vocabulary.
	 *
	 * @param array<int, mixed> $values     What was asked for.
	 * @param string            $vocabulary Which list decides.
	 * @return array<int, string>
	 */
	private static function keep( array $values, string $vocabulary ): array {
		$kept = array();

		foreach ( $values as $value ) {
			$candidate = trim( (string) $value );

			if ( self::is_real( $vocabulary, $candidate ) && ! in_array( $candidate, $kept, true ) ) {
				$kept[] = $candidate;
			}
		}

		return $kept;
	}

	/**
	 * Whether one value belongs to one vocabulary.
	 *
	 * @param string $vocabulary Which list decides.
	 * @param string $value      The value.
	 * @return bool
	 */
	private static function is_real( string $vocabulary, string $value ): bool {
		switch ( $vocabulary ) {
			case 'stage':
				return Stages::exists( $value );
			case 'level':
				return Levels::exists( $value );
			case 'work_type':
				return Types::exists( $value );
			case 'priority':
				return in_array( $value, Fields::PRIORITIES, true );
			case 'commercial_class':
				return in_array( $value, Fields::COMMERCIAL_CLASSES, true );
			case 'terminal_outcome':
				return Outcomes::exists( $value );
			default:
				return false;
		}
	}
}
