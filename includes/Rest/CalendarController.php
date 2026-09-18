<?php
/**
 * The calendar's own dates, and the diary every view draws.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Calendar\Dates;
use Blueworx\Forge\Calendar\Feed;
use Blueworx\Forge\Tenancy\Reach;
use WP_REST_Request;

/**
 * Two things (2026-09-18): the studio's dates — company days, birthdays,
 * campaigns — which administrators keep and everyone reads; and the feed of
 * everything on the diary in a window, scoped by the caller's reach, which
 * the calendar and the standup both draw from.
 */
final class CalendarController {

	/**
	 * How far a feed may reach in one read. A year is a lot of calendar.
	 */
	private const MAX_DAYS = 400;

	/**
	 * Registers the routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		Server::register_route(
			$route_namespace,
			'/calendar',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'feed' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_LIST,
					'reason' => 'The diary spans clients by design: chores and meetings are kept to the sites in reach by Calendar\Feed, and the studio\'s own dates, renewals and leave are the studio\'s.',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/calendar-dates',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'index' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'The studio\'s own dates, for everyone on the studio to see.',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/calendar-dates',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Configuration, administrators only; a date belongs to the studio, not a client.',
				),
			)
		);

		$editing = array(
			'PATCH'  => 'update',
			'DELETE' => 'remove',
		);

		foreach ( $editing as $method => $callback ) {
			Server::register_route(
				$route_namespace,
				'/calendar-dates/(?P<date_id>[A-Za-z0-9_\-]+)',
				array(
					'methods'             => $method,
					'callback'            => array( self::class, $callback ),
					'permission_callback' => array( Permissions::class, 'manage' ),
					'scope'               => array(
						'kind'   => Boundary::SCOPE_OPEN,
						'reason' => 'Configuration, administrators only; a date belongs to the studio, not a client.',
					),
				)
			);
		}
	}

	/**
	 * Everything on the diary in a window, for whoever is asking.
	 *
	 * @param WP_REST_Request $request Request, with from and to.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function feed( WP_REST_Request $request ) {
		$window = self::window( $request );

		if ( null === $window ) {
			return Errors::rest( 'invalid_window', __( 'Say which days, from and to, as dates.', 'blueworx-forge' ), 400 );
		}

		$reach = Boundary::current();

		return rest_ensure_response(
			array(
				'ok'      => true,
				'denied'  => Reach::is_nothing( $reach ),
				'from'    => $window[0],
				'to'      => $window[1],
				'kinds'   => Feed::KINDS,
				'entries' => Feed::for_reach( $reach, $window[0], $window[1] ),
			)
		);
	}

	/**
	 * The studio's dates in a window.
	 *
	 * @param WP_REST_Request $request Request, with from and to.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function index( WP_REST_Request $request ) {
		$window = self::window( $request );

		if ( null === $window ) {
			return Errors::rest( 'invalid_window', __( 'Say which days, from and to, as dates.', 'blueworx-forge' ), 400 );
		}

		return rest_ensure_response(
			array(
				'ok'    => true,
				'kinds' => Dates::KINDS,
				'dates' => Dates::between( $window[0], $window[1] ),
			)
		);
	}

	/**
	 * Adds a date.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$checked = Dates::validate( (array) $request->get_json_params(), false );

		if ( array() !== $checked['errors'] ) {
			return self::invalid( $checked['errors'] );
		}

		$date = Dates::create( $checked['values'], get_current_user_id() );

		if ( null === $date ) {
			return Errors::rest( 'write_failed', __( 'That date could not be saved.', 'blueworx-forge' ), 500 );
		}

		return rest_ensure_response(
			array(
				'ok'   => true,
				'date' => $date,
			)
		);
	}

	/**
	 * Changes a date.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update( WP_REST_Request $request ) {
		$date = Dates::get( (string) $request['date_id'] );

		if ( null === $date ) {
			return Boundary::absent( 'calendar_date' );
		}

		$input   = (array) $request->get_json_params();
		$known   = array(
			'on_date' => $date['on_date'],
			'ends_on' => $date['ends_on'],
		);
		$checked = Dates::validate( array_merge( $known, $input ), true );

		if ( array() !== $checked['errors'] ) {
			return self::invalid( $checked['errors'] );
		}

		$updated = Dates::update( (string) $date['id'], $checked['values'], (int) ( $input[ Versioning::PARAM ] ?? $date['record_version'] ) );

		if ( null === $updated ) {
			return Errors::rest( 'stale_version', __( 'That date changed elsewhere first — reload and try again.', 'blueworx-forge' ), 409 );
		}

		return rest_ensure_response(
			array(
				'ok'   => true,
				'date' => $updated,
			)
		);
	}

	/**
	 * Removes a date.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function remove( WP_REST_Request $request ) {
		$date = Dates::get( (string) $request['date_id'] );

		if ( null === $date ) {
			return Boundary::absent( 'calendar_date' );
		}

		Dates::remove( (string) $date['id'] );

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * The window asked for, bounded, or null when it is not two dates.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{0: string, 1: string}|null
	 */
	private static function window( WP_REST_Request $request ): ?array {
		$from = sanitize_text_field( (string) $request->get_param( 'from' ) );
		$to   = sanitize_text_field( (string) $request->get_param( 'to' ) );

		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) || $to < $from ) {
			return null;
		}

		$start = (int) strtotime( $from . ' 00:00:00 UTC' );
		$end   = (int) strtotime( $to . ' 00:00:00 UTC' );

		if ( ( $end - $start ) / DAY_IN_SECONDS > self::MAX_DAYS ) {
			$to = gmdate( 'Y-m-d', $start + self::MAX_DAYS * DAY_IN_SECONDS );
		}

		return array( $from, $to );
	}

	/**
	 * A refusal by field.
	 *
	 * @param array<string, string> $fields Field to what is wrong with it.
	 * @return \WP_Error
	 */
	private static function invalid( array $fields ) {
		return Errors::rest(
			'invalid_calendar_date',
			__( 'That date could not be saved.', 'blueworx-forge' ),
			400,
			array( 'fields' => $fields )
		);
	}
}
