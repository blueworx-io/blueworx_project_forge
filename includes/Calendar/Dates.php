<?php
/**
 * Company days, birthdays, campaign days: the studio's own dates.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Calendar;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Tenancy\Ids;

/**
 * A basic entry (Luke, 2026-09-17): what it is, when, and who it is for —
 * one person, several, or all staff. It sits on every view so people can see
 * it, and that is all it does.
 */
final class Dates {

	/**
	 * Id prefix for a date.
	 */
	public const PREFIX = 'cdt';

	/**
	 * What a date can be.
	 */
	public const KINDS = array(
		'company-day' => 'Company day',
		'birthday'    => 'Birthday',
		'campaign'    => 'Campaign',
		'other'       => 'Other',
	);

	/**
	 * Everyone on the studio, as the people column holds it.
	 */
	public const ALL = 'all';

	/**
	 * Longest a title or a note may be.
	 */
	public const MAX_TEXT = 191;

	/**
	 * Checks a date's values.
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
				$errors['title'] = 'Say what the date is.';
			} elseif ( mb_strlen( $title ) > self::MAX_TEXT ) {
				$errors['title'] = 'That title is too long.';
			} else {
				$values['title'] = $title;
			}
		}

		if ( ! $partial || array_key_exists( 'kind', $input ) ) {
			$kind = trim( (string) ( $input['kind'] ?? 'other' ) );

			if ( ! array_key_exists( $kind, self::KINDS ) ) {
				$errors['kind'] = 'Choose a kind of date.';
			} else {
				$values['kind'] = $kind;
			}
		}

		if ( ! $partial || array_key_exists( 'on_date', $input ) ) {
			$on = trim( (string) ( $input['on_date'] ?? '' ) );

			if ( ! self::is_date( $on ) ) {
				$errors['on_date'] = 'Say which day.';
			} else {
				$values['on_date'] = $on;
			}
		}

		if ( array_key_exists( 'ends_on', $input ) ) {
			$ends = trim( (string) $input['ends_on'] );

			if ( '' !== $ends && ! self::is_date( $ends ) ) {
				$errors['ends_on'] = 'The last day has to be a real date.';
			} else {
				$values['ends_on'] = $ends;
			}
		}

		$on   = (string) ( $values['on_date'] ?? '' );
		$ends = (string) ( $values['ends_on'] ?? '' );

		if ( '' !== $on && '' !== $ends && $ends < $on ) {
			$errors['ends_on'] = 'The last day has to be on or after the first.';
		}

		if ( ! $partial || array_key_exists( 'people', $input ) ) {
			$people = $input['people'] ?? self::ALL;

			if ( self::ALL === $people || array() === $people ) {
				$values['people'] = self::ALL;
			} elseif ( is_array( $people ) ) {
				$ids = array();

				foreach ( $people as $id ) {
					$id = trim( (string) $id );

					if ( 1 !== preg_match( '/^usr_[A-Za-z0-9]+$/', $id ) ) {
						$errors['people'] = 'That is not a person.';
						break;
					}

					$ids[ $id ] = $id;
				}

				if ( ! isset( $errors['people'] ) ) {
					$values['people'] = (string) wp_json_encode( array_values( $ids ) );
				}
			} else {
				$errors['people'] = 'Choose people, or all staff.';
			}
		}

		if ( array_key_exists( 'note', $input ) ) {
			$values['note'] = mb_substr( trim( (string) $input['note'] ), 0, self::MAX_TEXT );
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * Stores a date.
	 *
	 * @param array<string, mixed> $values Validated values.
	 * @param int                  $author WordPress user id.
	 * @return array<string, mixed>|null
	 */
	public static function create( array $values, int $author ): ?array {
		global $wpdb;

		$now = bwx_forge_now();
		$row = array_merge(
			array(
				'kind'    => 'other',
				'title'   => '',
				'on_date' => '',
				'ends_on' => '',
				'people'  => self::ALL,
				'note'    => '',
			),
			array_intersect_key( $values, array_flip( array( 'kind', 'title', 'on_date', 'ends_on', 'people', 'note' ) ) ),
			array(
				'id'             => Ids::create( self::PREFIX ),
				'created_at'     => $now,
				'updated_at'     => $now,
				'created_by'     => $author,
				'record_version' => 1,
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$inserted = $wpdb->insert( Schema::calendar_dates_table(), $row, Formats::for_row( $row ) );

		return $inserted ? self::hydrate( $row ) : null;
	}

	/**
	 * Changes a date, against the version the caller saw.
	 *
	 * @param string               $id           Date id.
	 * @param array<string, mixed> $values       Validated values.
	 * @param int                  $sent_version Version the change was made against.
	 * @return array<string, mixed>|null Null when nothing matched.
	 */
	public static function update( string $id, array $values, int $sent_version ): ?array {
		global $wpdb;

		$changes = array_intersect_key( $values, array_flip( array( 'kind', 'title', 'on_date', 'ends_on', 'people', 'note' ) ) );

		$changes['updated_at']     = bwx_forge_now();
		$changes['record_version'] = $sent_version + 1;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$changed = $wpdb->update(
			Schema::calendar_dates_table(),
			$changes,
			array(
				'id'             => $id,
				'record_version' => $sent_version,
			),
			Formats::for_row( $changes ),
			array( '%s', '%d' )
		);

		return $changed ? self::get( $id ) : null;
	}

	/**
	 * Removes a date. It was never work, so there is nothing to keep.
	 *
	 * @param string $id Date id.
	 * @return bool
	 */
	public static function remove( string $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		return (bool) $wpdb->delete( Schema::calendar_dates_table(), array( 'id' => $id ), array( '%s' ) );
	}

	/**
	 * One date.
	 *
	 * @param string $id Date id.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		global $wpdb;

		$table = Schema::calendar_dates_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Every date touching a window: on a day in it, or running across it.
	 *
	 * @param string $from YYYY-MM-DD, inclusive.
	 * @param string $to   YYYY-MM-DD, inclusive.
	 * @return array<int, array<string, mixed>>
	 */
	public static function between( string $from, string $to ): array {
		global $wpdb;

		$table = Schema::calendar_dates_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder; the values are.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE on_date <= %s AND ( ends_on >= %s OR ( ends_on = '' AND on_date >= %s ) ) ORDER BY on_date ASC, title ASC",
				$to,
				$from,
				$from
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Whether a date is for a person: everyone's, or theirs by name.
	 *
	 * @param array<string, mixed> $date    A hydrated date.
	 * @param string               $user_id Person id.
	 * @return bool
	 */
	public static function is_for( array $date, string $user_id ): bool {
		return self::ALL === $date['people'] || in_array( $user_id, (array) $date['people'], true );
	}

	/**
	 * Whether a string is a real calendar date.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	private static function is_date( string $value ): bool {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}

		[ $year, $month, $day ] = array_map( 'intval', explode( '-', $value ) );

		return checkdate( $month, $day, $year );
	}

	/**
	 * A row as the rest of the plugin reads it.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$people = (string) ( $row['people'] ?? self::ALL );
		$listed = self::ALL === $people ? self::ALL : json_decode( $people, true );

		return array(
			'id'             => (string) $row['id'],
			'kind'           => (string) $row['kind'],
			'kind_label'     => self::KINDS[ (string) $row['kind'] ] ?? (string) $row['kind'],
			'title'          => (string) $row['title'],
			'on_date'        => (string) $row['on_date'],
			'ends_on'        => (string) ( $row['ends_on'] ?? '' ),
			'people'         => self::ALL === $listed ? self::ALL : array_values( array_map( 'strval', is_array( $listed ) ? $listed : array() ) ),
			'note'           => (string) ( $row['note'] ?? '' ),
			'created_at'     => (int) ( $row['created_at'] ?? 0 ),
			'updated_at'     => (int) ( $row['updated_at'] ?? 0 ),
			'created_by'     => (int) ( $row['created_by'] ?? 0 ),
			'record_version' => (int) ( $row['record_version'] ?? 1 ),
		);
	}
}
