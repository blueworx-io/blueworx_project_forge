<?php
/**
 * Somebody's working week, from a date.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Capacity;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Tenancy\Ids;

/**
 * CAP-1's weekly pattern, stored effective-dated (#136).
 *
 * Recording a change as a new row rather than an edit is the whole point.
 * Someone who drops to four days in March was full time in February, and a
 * February capacity figure that silently recalculates the moment their hours
 * are edited disagrees with what was decided at the time, with nothing to say
 * why. Every read here therefore asks "as at which date", never "what are their
 * hours".
 */
final class Patterns {

	/**
	 * Id prefix for a pattern.
	 */
	public const PREFIX = 'avp';

	/**
	 * The seven columns, in the order PHP's `w` format numbers them — Sunday
	 * first. Keeping that order here means the lookup is an index rather than a
	 * mapping somebody has to keep in step.
	 */
	private const DAY_COLUMNS = array(
		'hours_sun',
		'hours_mon',
		'hours_tue',
		'hours_wed',
		'hours_thu',
		'hours_fri',
		'hours_sat',
	);

	/**
	 * Records a pattern taking effect on a date.
	 *
	 * Always an append. Re-stating hours for a date somebody has already spoken
	 * about writes another row rather than editing theirs, and the later row
	 * wins — so a correction is recorded as a correction instead of quietly
	 * replacing what was believed at the time. In a table that exists so
	 * history does not change, an update would be the one operation that
	 * changes it.
	 *
	 * @param string               $user_id        The person.
	 * @param string               $effective_from YYYY-MM-DD the pattern starts.
	 * @param array<string, float> $hours          Hours by column name.
	 * @param int                  $author         WordPress user id of the author.
	 * @param string               $note           Optional note.
	 * @return array<string, mixed>|null
	 */
	public static function record( string $user_id, string $effective_from, array $hours, int $author, string $note = '' ): ?array {
		global $wpdb;

		$row = array(
			'id'             => Ids::create( self::PREFIX ),
			'user_id'        => $user_id,
			'effective_from' => $effective_from,
			'note'           => $note,
			'created_at'     => bwx_forge_now(),
			'created_by'     => $author,
		);

		foreach ( self::DAY_COLUMNS as $column ) {
			// Negative hours are not a shorter week, they are a typo, and a
			// negative day would quietly reduce the week's total.
			$row[ $column ] = round( max( 0.0, (float) ( $hours[ $column ] ?? 0 ) ), 2 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; there is no core API for it.
		$inserted = $wpdb->insert( Schema::availability_patterns_table(), $row, Formats::for_row( $row ) );

		if ( ! $inserted ) {
			return null;
		}

		/*
		 * #144. Nothing is recalculated — the figures are read when they are
		 * asked for. What the work needs is to say that a person's hours moved
		 * underneath it, so a week that has turned red can be traced to what
		 * turned it. An hours change is open-ended, so it has no end date.
		 */
		Trail::record(
			$user_id,
			$effective_from,
			'',
			__( 'Somebody in a seat had their working hours changed.', 'blueworx-forge' )
		);

		return self::hydrate( $row );
	}

	/**
	 * The pattern in force for somebody on a date.
	 *
	 * The latest one starting on or before that date. Null when they have none
	 * yet, which is a person nobody has said anything about rather than a person
	 * who works no hours — the difference matters to whoever reads the answer.
	 *
	 * @param string $user_id The person.
	 * @param string $date    YYYY-MM-DD.
	 * @return array<string, mixed>|null
	 */
	public static function in_force( string $user_id, string $date ): ?array {
		return self::pick( self::history( $user_id ), $date );
	}

	/**
	 * The same choice, made over patterns already in hand.
	 *
	 * Separate from the query so the rule — the latest one starting on or before
	 * the date — can be tested without a database, and so a caller working
	 * across a period reads a person's history once rather than once per day.
	 *
	 * @param array<int, array<string, mixed>> $history Patterns, in any order.
	 * @param string                           $date    YYYY-MM-DD.
	 * @return array<string, mixed>|null
	 */
	public static function pick( array $history, string $date ): ?array {
		$best = null;

		foreach ( $history as $pattern ) {
			$from = (string) ( $pattern['effective_from'] ?? '' );

			if ( '' === $from || $from > $date ) {
				continue;
			}

			if ( null === $best ) {
				$best = $pattern;
				continue;
			}

			$best_from = (string) $best['effective_from'];

			if ( $from > $best_from ) {
				$best = $pattern;
				continue;
			}

			// Two statements about the same date: the later one is a
			// correction of the earlier, so it wins. This is the only thing
			// making a correction possible in an append-only table.
			if ( $from === $best_from && (int) ( $pattern['created_at'] ?? 0 ) >= (int) ( $best['created_at'] ?? 0 ) ) {
				$best = $pattern;
			}
		}

		return $best;
	}

	/**
	 * Every pattern recorded for somebody, newest first.
	 *
	 * @param string $user_id The person.
	 * @return array<int, array<string, mixed>>
	 */
	public static function history( string $user_id ): array {
		global $wpdb;

		$table = Schema::availability_patterns_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %s ORDER BY effective_from DESC, created_at DESC", $user_id ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Every pattern for several people, newest first within each.
	 *
	 * One query rather than one per person, for the same reason as
	 * Unavailability::for_people(): the capacity view asks about the whole
	 * studio at once (#139).
	 *
	 * @param array<int, string> $user_ids The people.
	 * @return array<string, array<int, array<string, mixed>>> Keyed by person, everybody present.
	 */
	public static function for_people( array $user_ids ): array {
		global $wpdb;

		$out = array_fill_keys( $user_ids, array() );

		if ( array() === $user_ids ) {
			return $out;
		}

		$table = Schema::availability_patterns_table();
		$slots = implode( ', ', array_fill( 0, count( $user_ids ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table name cannot be a placeholder; the id placeholders are counted above.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id IN ({$slots}) ORDER BY effective_from DESC, created_at DESC", array_values( $user_ids ) ),
			ARRAY_A
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$user_id = (string) $row['user_id'];

			if ( isset( $out[ $user_id ] ) ) {
				$out[ $user_id ][] = self::hydrate( $row );
			}
		}

		return $out;
	}

	/**
	 * The hours a pattern gives for a date's weekday.
	 *
	 * @param array<string, mixed> $pattern A hydrated pattern.
	 * @param string               $date    YYYY-MM-DD.
	 * @return float
	 */
	public static function hours_for_weekday( array $pattern, string $date ): float {
		$weekday = (int) gmdate( 'w', (int) strtotime( $date . ' 00:00:00 UTC' ) );
		$column  = self::DAY_COLUMNS[ $weekday ] ?? 'hours_sun';

		return (float) ( $pattern[ $column ] ?? 0 );
	}

	/**
	 * The seven column names, Sunday first.
	 *
	 * @return array<int, string>
	 */
	public static function day_columns(): array {
		return self::DAY_COLUMNS;
	}

	/**
	 * A stored row as the rest of the code wants it.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$out = array(
			'id'             => (string) $row['id'],
			'user_id'        => (string) $row['user_id'],
			'effective_from' => (string) $row['effective_from'],
			'note'           => (string) ( $row['note'] ?? '' ),
			'created_at'     => (int) ( $row['created_at'] ?? 0 ),
			'created_by'     => (int) ( $row['created_by'] ?? 0 ),
		);

		$week = 0.0;

		foreach ( self::DAY_COLUMNS as $column ) {
			$out[ $column ] = (float) ( $row[ $column ] ?? 0 );
			$week          += $out[ $column ];
		}

		$out['hours_week'] = round( $week, 2 );

		return $out;
	}
}
