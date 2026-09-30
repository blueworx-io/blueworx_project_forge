<?php
/**
 * What each person does here.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

use Blueworx\Forge\Data\Formats;
use Blueworx\Forge\Data\Schema;

/**
 * #474. A person's role: a title, the hours they are meant to work as a note,
 * when their contract starts and ends, a description and their regular duties.
 *
 * One row per person, keyed by the person's own id, replaced whole on every
 * save. The hours are only ever shown: capacity reads a person's working week
 * from their availability and never from here.
 */
final class StaffRoles {

	/**
	 * Most duties one person can have listed.
	 */
	public const MAX_DUTIES = 50;

	/**
	 * Cleans what was sent and says what is wrong with it, by field. Pure.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function clean( array $input ): array {
		$errors = array();

		$title       = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		$description = trim( sanitize_textarea_field( (string) ( $input['description'] ?? '' ) ) );
		$starts_on   = sanitize_text_field( (string) ( $input['starts_on'] ?? '' ) );
		$ends_on     = sanitize_text_field( (string) ( $input['ends_on'] ?? '' ) );
		$hours       = trim( sanitize_text_field( (string) ( $input['weekly_hours'] ?? '' ) ) );

		if ( mb_strlen( $title ) > 191 ) {
			$errors['title'] = __( 'Keep the title under 191 characters.', 'blueworx-forge' );
		}

		if ( '' !== $hours ) {
			if ( ! is_numeric( $hours ) || (float) $hours < 0 || (float) $hours > 168 ) {
				$errors['weekly_hours'] = __( 'Weekly hours must be a number from 0 to 168.', 'blueworx-forge' );
			} else {
				$hours = (string) (float) $hours;
			}
		}

		if ( '' !== $starts_on && ! self::is_date( $starts_on ) ) {
			$errors['starts_on'] = __( 'Use a real date for the start.', 'blueworx-forge' );
		}

		if ( '' !== $ends_on && ! self::is_date( $ends_on ) ) {
			$errors['ends_on'] = __( 'Use a real date for the end, or leave it blank if the contract is ongoing.', 'blueworx-forge' );
		}

		if ( ! isset( $errors['starts_on'] ) && ! isset( $errors['ends_on'] ) && '' !== $starts_on && '' !== $ends_on && $ends_on < $starts_on ) {
			$errors['ends_on'] = __( 'The end cannot be before the start.', 'blueworx-forge' );
		}

		if ( '' === $starts_on && '' !== $ends_on && ! isset( $errors['ends_on'] ) ) {
			$errors['starts_on'] = __( 'Say when the contract starts.', 'blueworx-forge' );
		}

		$duties = array();

		foreach ( (array) ( $input['duties'] ?? array() ) as $duty ) {
			$duty = is_string( $duty ) ? sanitize_text_field( $duty ) : '';

			if ( '' !== $duty ) {
				$duties[] = $duty;
			}
		}

		if ( count( $duties ) > self::MAX_DUTIES ) {
			$errors['duties'] = __( 'That is too many duties to list.', 'blueworx-forge' );
		}

		foreach ( $duties as $duty ) {
			if ( mb_strlen( $duty ) > 191 ) {
				$errors['duties'] = __( 'Keep each duty under 191 characters.', 'blueworx-forge' );
			}
		}

		return array(
			'values' => array(
				'title'        => $title,
				'weekly_hours' => $hours,
				'starts_on'    => $starts_on,
				'ends_on'      => $ends_on,
				'description'  => $description,
				'duties'       => $duties,
			),
			'errors' => $errors,
		);
	}

	/**
	 * A person's role. A person with none set has an empty one.
	 *
	 * @param string $user_id Forge person id.
	 * @return array<string, mixed>
	 */
	public static function get( string $user_id ): array {
		global $wpdb;

		$table = Schema::staff_roles_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be a placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $user_id ), ARRAY_A );

		$row    = is_array( $row ) ? $row : array();
		$duties = json_decode( (string) ( $row['duties'] ?? '' ), true );

		return array(
			'title'        => (string) ( $row['title'] ?? '' ),
			'weekly_hours' => (string) ( $row['weekly_hours'] ?? '' ),
			'starts_on'    => (string) ( $row['starts_on'] ?? '' ),
			'ends_on'      => (string) ( $row['ends_on'] ?? '' ),
			'description'  => (string) ( $row['description'] ?? '' ),
			'duties'       => is_array( $duties ) ? array_values( array_map( 'strval', $duties ) ) : array(),
		);
	}

	/**
	 * Saves a person's role, replacing what was there.
	 *
	 * @param string               $user_id Forge person id.
	 * @param array<string, mixed> $values  Cleaned values.
	 */
	public static function save( string $user_id, array $values ): void {
		global $wpdb;

		$row = array(
			'id'           => $user_id,
			'title'        => (string) $values['title'],
			'weekly_hours' => (string) $values['weekly_hours'],
			'starts_on'    => (string) $values['starts_on'],
			'ends_on'      => (string) $values['ends_on'],
			'description'  => (string) $values['description'],
			'duties'       => (string) wp_json_encode( array_values( (array) $values['duties'] ) ),
			'updated_at'   => bwx_forge_now(),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->replace( Schema::staff_roles_table(), $row, Formats::for_row( $row ) );
	}

	/**
	 * Forgets a person's role, when the person goes.
	 *
	 * @param string $user_id Forge person id.
	 */
	public static function forget( string $user_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->delete( Schema::staff_roles_table(), array( 'id' => $user_id ), array( '%s' ) );
	}

	/**
	 * Whether a string is a real calendar date, YYYY-MM-DD.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function is_date( string $value ): bool {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}

		list( $year, $month, $day ) = array_map( 'intval', explode( '-', $value ) );

		return checkdate( $month, $day, $year );
	}
}
