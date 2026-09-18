<?php
/**
 * A person's working week and time off, over REST.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Capacity\Availability;
use Blueworx\Forge\Capacity\Patterns;
use Blueworx\Forge\Capacity\Unavailability;
use Blueworx\Forge\Tenancy\Users;
use WP_REST_Request;

/**
 * What the Availability admin page did, as routes, so the studio app is the
 * one place this is done. The reads and writes are the ones that page made;
 * the shapes are the Capacity classes' own. Every answer is the whole
 * picture for one person, so a screen that has just written never has to
 * read again.
 *
 * Administrators only, reads included: the admin page it replaced required
 * the same, and a person's hours are configuration, not work.
 */
final class AvailabilityController {

	/**
	 * The idempotency operation for adding time off. Scoped by person when
	 * used, so one retry key cannot answer another person's replay.
	 */
	private const LEAVE_OPERATION = 'availability.leave.create';

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		$scope = array(
			'kind'   => Boundary::SCOPE_OPEN,
			'reason' => 'A person\'s hours are a global record (AUTH-6), not a client\'s. The administrator\'s configuration, and the person\'s own.',
		);

		Server::register_route(
			$route_namespace,
			'/users/(?P<user_id>[A-Za-z0-9_\-]+)/availability',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'read' ),
				'permission_callback' => array( Permissions::class, 'manage_or_self' ),
				'scope'               => $scope,
			)
		);

		Server::register_route(
			$route_namespace,
			'/users/(?P<user_id>[A-Za-z0-9_\-]+)/availability/hours',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'set_hours' ),
				'permission_callback' => array( Permissions::class, 'manage_or_self' ),
				'scope'               => $scope,
			)
		);

		Server::register_route(
			$route_namespace,
			'/users/(?P<user_id>[A-Za-z0-9_\-]+)/leave',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'add_leave' ),
				'permission_callback' => array( Permissions::class, 'manage_or_self' ),
				'scope'               => $scope,
			)
		);

		Server::register_route(
			$route_namespace,
			'/users/(?P<user_id>[A-Za-z0-9_\-]+)/leave/(?P<leave_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( self::class, 'remove_leave' ),
				'permission_callback' => array( Permissions::class, 'manage_or_self' ),
				'scope'               => $scope,
			)
		);
	}

	/**
	 * The whole picture for one person.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function read( WP_REST_Request $request ) {
		$user = Users::get( (string) $request['user_id'] );

		if ( null === $user ) {
			return self::unknown_user();
		}

		return rest_ensure_response( self::answer( $user ) );
	}

	/**
	 * Records a working week from a date.
	 *
	 * Always an append, as {@see Patterns::record()} is: a correction is a
	 * new row that wins from its date, and the old one stays in the history.
	 * That is why this is a POST and not a PUT.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function set_hours( WP_REST_Request $request ) {
		$user = Users::get( (string) $request['user_id'] );

		if ( null === $user ) {
			return self::unknown_user();
		}

		$body           = (array) $request->get_json_params();
		$effective_from = sanitize_text_field( (string) ( $body['effective_from'] ?? '' ) );

		if ( ! self::is_date( $effective_from ) ) {
			return self::invalid( array( 'effective_from' => __( 'Say the date these hours start from.', 'blueworx-forge' ) ) );
		}

		// Until a date, or ongoing (2026-09-18).
		$effective_to = sanitize_text_field( (string) ( $body['effective_to'] ?? '' ) );
		$fields       = array();

		if ( '' !== $effective_to && ( ! self::is_date( $effective_to ) || $effective_to < $effective_from ) ) {
			$fields['effective_to'] = __( 'The end has to be on or after the start.', 'blueworx-forge' );
		}

		$hours = array();

		foreach ( Patterns::day_columns() as $column ) {
			$hours[ $column ] = (float) ( $body[ $column ] ?? 0 );

			if ( $hours[ $column ] > Patterns::MAX_DAY ) {
				$fields[ $column ] = __( 'At most 12 hours in a day.', 'blueworx-forge' );
			}
		}

		if ( array() !== $fields ) {
			return self::invalid( $fields );
		}

		$note    = sanitize_text_field( (string) ( $body['note'] ?? '' ) );
		$pattern = Patterns::record( (string) $user['id'], $effective_from, $hours, get_current_user_id(), $note, $effective_to );

		if ( null === $pattern ) {
			return Errors::rest( 'write_failed', __( 'Those hours could not be saved.', 'blueworx-forge' ), 500 );
		}

		return rest_ensure_response( array_merge( array( 'pattern' => $pattern ), self::answer( $user ) ) );
	}

	/**
	 * Records time somebody is not available for.
	 *
	 * Replay-safe under an idempotency key: a resend that made a second row
	 * would show two identical periods, and somebody would delete one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function add_leave( WP_REST_Request $request ) {
		$user = Users::get( (string) $request['user_id'] );

		if ( null === $user ) {
			return self::unknown_user();
		}

		$key       = (string) $request->get_header( Idempotency::HEADER );
		$operation = self::LEAVE_OPERATION . ':' . (string) $user['id'];

		if ( '' !== $key ) {
			if ( ! Idempotency::is_valid_key( $key ) ) {
				return Errors::rest( 'invalid_idempotency_key', __( 'That retry key cannot be used.', 'blueworx-forge' ), 400 );
			}

			$replay = Idempotency::replay( $operation, $key );

			if ( null !== $replay ) {
				return rest_ensure_response( $replay );
			}
		}

		$body      = (array) $request->get_json_params();
		$starts_on = sanitize_text_field( (string) ( $body['starts_on'] ?? '' ) );
		$ends_on   = sanitize_text_field( (string) ( $body['ends_on'] ?? '' ) );
		$fields    = array();

		if ( ! self::is_date( $starts_on ) ) {
			$fields['starts_on'] = __( 'Say the first day away.', 'blueworx-forge' );
		}

		if ( ! self::is_date( $ends_on ) ) {
			$fields['ends_on'] = __( 'Say the last day away.', 'blueworx-forge' );
		}

		if ( array() !== $fields ) {
			return self::invalid( $fields );
		}

		$kind = sanitize_key( (string) ( $body['kind'] ?? 'leave' ) );
		$note = sanitize_text_field( (string) ( $body['note'] ?? '' ) );

		$record = Unavailability::add( (string) $user['id'], $starts_on, $ends_on, $kind, get_current_user_id(), $note );

		if ( null === $record ) {
			return Errors::rest( 'write_failed', __( 'That time off could not be saved.', 'blueworx-forge' ), 500 );
		}

		$response = array_merge( array( 'record' => $record ), self::answer( $user ) );

		if ( '' !== $key ) {
			Idempotency::remember( $operation, $key, $response );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Removes one record, if it is this person's.
	 *
	 * The record is read by id and its owner compared to the person in the
	 * path, rather than found by scanning the display window: that window is
	 * only a year either side of today (see {@see self::answer()}), and a
	 * genuine record outside it would otherwise answer as not found.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function remove_leave( WP_REST_Request $request ) {
		$user = Users::get( (string) $request['user_id'] );

		if ( null === $user ) {
			return self::unknown_user();
		}

		$leave_id = (string) $request['leave_id'];
		$record   = Unavailability::get( $leave_id );

		if ( null === $record || (string) $record['user_id'] !== (string) $user['id'] || ! Unavailability::remove( $leave_id ) ) {
			return Errors::rest( 'unknown_leave', __( 'There is no such time off.', 'blueworx-forge' ), 404 );
		}

		return rest_ensure_response( self::answer( $user ) );
	}

	/**
	 * Whether a value is a date this can store.
	 *
	 * Checked rather than trusted, for the reason the admin screen checks:
	 * a malformed date stored here sorts wrong against every other date.
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
	 * The refusal for input that cannot be stored, by field.
	 *
	 * @param array<string, string> $fields Field to what is wrong with it.
	 * @return \WP_Error
	 */
	private static function invalid( array $fields ) {
		return Errors::rest(
			'invalid_availability',
			__( 'That could not be saved.', 'blueworx-forge' ),
			400,
			array( 'fields' => $fields )
		);
	}

	/**
	 * What every route answers with: the pattern in force, its history, the
	 * time off a year either side of today, and the next seven days.
	 *
	 * The windows are the admin screen's, so the two show the same thing
	 * while both exist.
	 *
	 * @param array<string, mixed> $user The person.
	 * @return array<string, mixed>
	 */
	private static function answer( array $user ): array {
		$id    = (string) $user['id'];
		$today = gmdate( 'Y-m-d' );
		$start = (int) strtotime( $today . ' 00:00:00 UTC' );

		$week_to    = gmdate( 'Y-m-d', $start + ( 6 * DAY_IN_SECONDS ) );
		$leave_from = gmdate( 'Y-m-d', $start - ( 365 * DAY_IN_SECONDS ) );
		$leave_to   = gmdate( 'Y-m-d', $start + ( 365 * DAY_IN_SECONDS ) );

		return array(
			'ok'       => true,
			'person'   => array(
				'id'           => $id,
				'display_name' => (string) $user['display_name'],
			),
			'recorded' => Availability::is_recorded( $id, $today ),
			'current'  => Patterns::in_force( $id, $today ),
			'history'  => Patterns::history( $id ),
			'leave'    => Unavailability::overlapping( $id, $leave_from, $leave_to ),
			'week'     => array(
				'from'  => $today,
				'to'    => $week_to,
				'hours' => Availability::hours( $id, $today, $week_to ),
				'days'  => Availability::by_day( $id, $today, $week_to ),
			),
		);
	}

	/**
	 * The refusal for a person who is not there.
	 *
	 * @return \WP_Error
	 */
	private static function unknown_user() {
		return Errors::rest( 'unknown_user', __( 'There is no such person.', 'blueworx-forge' ), 404 );
	}
}
