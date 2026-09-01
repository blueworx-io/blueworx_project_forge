<?php
/**
 * The delivery numbers.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Reports\Source;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * #176. One read: whether delivery is working.
 *
 * A list scope, like the day's list and the request queue, and for the same
 * reason: the route names no record, so there is nothing for the boundary to
 * check, and {@see Source::for_reach()} is the tenant check for the whole
 * screen. It runs before any arithmetic does.
 *
 * There is no write here and there is not going to be one. Nothing about a
 * report is editable, because a report is not a record — it is the records,
 * counted. A route that let somebody adjust a figure would be a route that lets
 * the numbers and the work disagree, which is the one thing this whole
 * milestone exists to prevent.
 */
final class ReportsController {

	/**
	 * The longest window that can be asked for, in days.
	 *
	 * A year. Long enough for every question anybody has asked of these numbers,
	 * and a bound rather than none at all, so one bookmarked URL cannot ask for
	 * the whole log.
	 */
	private const MAX_DAYS = 366;

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		Server::register_route(
			$route_namespace,
			'/reports',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'index' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'args'                => array(
					'from' => array( 'type' => 'string' ),
					'to'   => array( 'type' => 'string' ),
				),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_LIST,
					'reason' => 'The delivery numbers span clients by design (#176): they are how the studio sees whether delivery is working across everything it holds. The route names no record, and Reports\Source scopes every part of the answer by reach before any arithmetic runs.',
				),
			)
		);
	}

	/**
	 * The numbers, for one window.
	 *
	 * The window defaults to the last twelve weeks rather than to all of time.
	 * A report with no window is a report whose cost grows every month it exists
	 * and whose figures get slower to change until they stop meaning anything.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function index( WP_REST_Request $request ) {
		$to   = self::timestamp( (string) $request->get_param( 'to' ), bwx_forge_now() );
		$from = self::timestamp(
			(string) $request->get_param( 'from' ),
			$to - ( 84 * DAY_IN_SECONDS )
		);

		if ( $from > $to ) {
			return Errors::rest( 'bad_window', __( 'Give a start date and an end date, in that order.', 'blueworx-forge' ), 400 );
		}

		if ( $to - $from > self::MAX_DAYS * DAY_IN_SECONDS ) {
			return Errors::rest( 'window_too_long', __( 'That is longer than a year. Ask for a shorter period.', 'blueworx-forge' ), 400 );
		}

		return rest_ensure_response(
			array(
				'ok'        => true,
				'generated' => bwx_forge_now(),
				'reports'   => Source::for_reach( Boundary::current(), $from, $to ),
			)
		);
	}

	/**
	 * A date somebody asked for, or the default where they asked for nothing.
	 *
	 * A date rather than a timestamp on the wire, because that is what a person
	 * picks and what a URL somebody shares should still mean next week.
	 *
	 * @param string $given    YYYY-MM-DD, or empty.
	 * @param int    $fallback Used when nothing usable was given.
	 * @return int
	 */
	private static function timestamp( string $given, int $fallback ): int {
		if ( '' === $given ) {
			return $fallback;
		}

		$parsed = strtotime( $given . ' 00:00:00 UTC' );

		return false === $parsed ? $fallback : (int) $parsed;
	}
}
