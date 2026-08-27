<?php
/**
 * What the availability screen's forms do.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Capacity\Patterns;
use Blueworx\Forge\Capacity\Unavailability;
use Blueworx\Forge\Tenancy\Users;

/**
 * Recording working hours and time off (#136).
 *
 * Separate from the screen because these change state and that one does not.
 */
final class AvailabilityActions {

	/**
	 * Hooks the handlers up.
	 */
	public static function boot(): void {
		add_action( 'admin_post_bwx_forge_set_hours', array( self::class, 'set_hours' ) );
		add_action( 'admin_post_bwx_forge_add_leave', array( self::class, 'add_leave' ) );
		add_action( 'admin_post_bwx_forge_remove_leave', array( self::class, 'remove_leave' ) );
	}

	/**
	 * Records a working week from a date.
	 */
	public static function set_hours(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_set_hours' );

		$person = isset( $_POST['person'] ) ? sanitize_text_field( wp_unslash( $_POST['person'] ) ) : '';

		if ( null === Users::get( $person ) ) {
			self::back( '', 'unknown-person' );
		}

		$effective_from = isset( $_POST['effective_from'] ) ? sanitize_text_field( wp_unslash( $_POST['effective_from'] ) ) : '';

		if ( ! self::is_date( $effective_from ) ) {
			self::back( $person, 'needs-date' );
		}

		$hours = array();

		foreach ( Patterns::day_columns() as $column ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above, for this whole handler.
			$hours[ $column ] = isset( $_POST[ $column ] ) ? (float) sanitize_text_field( wp_unslash( $_POST[ $column ] ) ) : 0.0;
		}

		Patterns::record( $person, $effective_from, $hours, get_current_user_id() );

		self::back( $person, 'hours-set' );
	}

	/**
	 * Records time somebody is not available for.
	 */
	public static function add_leave(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_add_leave' );

		$person = isset( $_POST['person'] ) ? sanitize_text_field( wp_unslash( $_POST['person'] ) ) : '';

		if ( null === Users::get( $person ) ) {
			self::back( '', 'unknown-person' );
		}

		$starts_on = isset( $_POST['starts_on'] ) ? sanitize_text_field( wp_unslash( $_POST['starts_on'] ) ) : '';
		$ends_on   = isset( $_POST['ends_on'] ) ? sanitize_text_field( wp_unslash( $_POST['ends_on'] ) ) : '';

		if ( ! self::is_date( $starts_on ) || ! self::is_date( $ends_on ) ) {
			self::back( $person, 'needs-dates' );
		}

		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : 'leave';
		$note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';

		Unavailability::add( $person, $starts_on, $ends_on, $kind, get_current_user_id(), $note );

		self::back( $person, 'leave-added' );
	}

	/**
	 * Removes one record.
	 */
	public static function remove_leave(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_remove_leave' );

		$person = isset( $_POST['person'] ) ? sanitize_text_field( wp_unslash( $_POST['person'] ) ) : '';
		$record = isset( $_POST['record'] ) ? sanitize_text_field( wp_unslash( $_POST['record'] ) ) : '';

		Unavailability::remove( $record );

		self::back( $person, 'leave-removed' );
	}

	/**
	 * Whether a value is a date this screen can store.
	 *
	 * Checked rather than trusted: `<input type="date">` is a hint to a browser,
	 * not a guarantee about what arrives, and a malformed date stored here would
	 * quietly sort wrong against every other date in the table.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	private static function is_date( string $value ): bool {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}

		list( $year, $month, $day ) = array_map( 'intval', explode( '-', $value ) );

		return checkdate( $month, $day, $year );
	}

	/**
	 * Refuses anyone who does not administer this site.
	 *
	 * The nonce check sits in each handler rather than here, because the coding
	 * standard only recognises a nonce checked in the same function as the form
	 * data it protects.
	 */
	private static function require_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to change availability.', 'blueworx-forge' ),
				'',
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Returns to the screen with the outcome, and stops.
	 *
	 * @param string $person_id Whose screen to return to.
	 * @param string $result    One of the result codes the screen knows.
	 */
	private static function back( string $person_id, string $result ): void {
		wp_safe_redirect( AvailabilityScreen::url( $person_id, $result ) );
		exit;
	}
}
