<?php
/**
 * The capacity routes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Capacity\Availability;
use Blueworx\Forge\Capacity\Commitments;
use Blueworx\Forge\Capacity\Periods;
use Blueworx\Forge\Capacity\Position;
use Blueworx\Forge\Tenancy\Capabilities;
use Blueworx\Forge\Tenancy\Users;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Who has room (#139), counted across every client (#138).
 *
 * Both routes are deliberately outside the tenant boundary, which is the one
 * thing about them that needed a decision. Every other read in Forge is scoped
 * to a client because showing one client another client's records is the whole
 * thing ARCH-3 exists to prevent. This read is about the studio's own people
 * and their own hours, and scoping it per client would produce three clients
 * each shown a person with plenty of room, and the person with none.
 *
 * Nothing here names a client, so there is no client in the answer to leak. The
 * drill-down does name work, which is why it asks for the staff capacity
 * capability rather than for a login.
 */
final class CapacityController {

	/**
	 * The longest window a caller may ask for, in days.
	 *
	 * A guard against a typo rather than a policy, matching Availability's own.
	 */
	private const MAX_DAYS = 370;

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		$scope = array(
			'kind'   => Boundary::SCOPE_OPEN,
			'reason' => 'Capacity spans every client by definition (#138): a person committed on one client must not look free on another. The answer names the studio\'s own people and no client, and the capability is checked in the callback.',
		);

		$window = array(
			'from' => array(
				'type'     => 'string',
				'required' => true,
			),
			'to'   => array(
				'type'     => 'string',
				'required' => true,
			),
		);

		Server::register_route(
			$route_namespace,
			'/capacity',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'index' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => $scope,
				'args'                => $window,
			)
		);

		Server::register_route(
			$route_namespace,
			'/capacity/person/(?P<user_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'person' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => $scope,
				'args'                => $window,
			)
		);
	}

	/**
	 * Everybody, week by week.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function index( WP_REST_Request $request ) {
		$refusal = self::refuse_unless_permitted();

		if ( null !== $refusal ) {
			return $refusal;
		}

		$window = self::window( $request );

		if ( ! is_array( $window ) ) {
			return $window;
		}

		list( $from, $to ) = $window;

		$people = Users::all( 'active' );
		$ids    = array_map(
			static fn( array $person ): string => (string) $person['id'],
			$people
		);

		$weeks = Periods::weeks( $from, $to );

		/*
		 * One call for the whole grid. Asking week by week meant reading every
		 * person's availability once per column, which on a quarter and a full
		 * studio was hundreds of queries and a screen that never arrived.
		 */
		$grid = Position::grid( $ids, $weeks, $from, $to );
		$rows = array();

		foreach ( $people as $person ) {
			$id = (string) $person['id'];

			$rows[] = array(
				'user_id'      => $id,
				'display_name' => (string) $person['display_name'],
				'weeks'        => $grid[ $id ]['weeks'],
				'total'        => $grid[ $id ]['total'],
			);
		}

		return new WP_REST_Response(
			array(
				'from'   => $from,
				'to'     => $to,
				'weeks'  => $weeks,
				'people' => $rows,
			),
			200
		);
	}

	/**
	 * One person, and the work behind every figure.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function person( WP_REST_Request $request ) {
		$refusal = self::refuse_unless_permitted();

		if ( null !== $refusal ) {
			return $refusal;
		}

		$window = self::window( $request );

		if ( ! is_array( $window ) ) {
			return $window;
		}

		list( $from, $to ) = $window;

		$user_id = (string) $request->get_param( 'user_id' );
		$person  = Users::get( $user_id );

		if ( null === $person ) {
			return Errors::rest( 'not_found', __( 'There is no such person.', 'blueworx-forge' ), 404 );
		}

		$gathered = Commitments::for_people( array( $user_id ), $from, $to );

		return new WP_REST_Response(
			array(
				'user_id'          => $user_id,
				'display_name'     => (string) $person['display_name'],
				'from'             => $from,
				'to'               => $to,
				'days'             => Availability::by_day( $user_id, $from, $to ),
				'committed_by_day' => $gathered[ $user_id ]['by_day'] ?? array(),
				'allocations'      => $gathered[ $user_id ]['allocations'] ?? array(),
				'position'         => Position::for_people( array( $user_id ), $from, $to )[ $user_id ],
			),
			200
		);
	}

	/**
	 * Refuses anybody who may not see staff against capacity.
	 *
	 * @return WP_Error|null Null when it is allowed.
	 */
	private static function refuse_unless_permitted() {
		if ( Access::allows_anywhere( Capabilities::VIEW_STAFF_CAPACITY ) ) {
			return null;
		}

		return Errors::rest(
			'not_permitted',
			__( 'You cannot see staff capacity.', 'blueworx-forge' ),
			403,
			array( 'capability' => Capabilities::VIEW_STAFF_CAPACITY )
		);
	}

	/**
	 * The window a request asks for, refused when it makes no sense.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<int, string>|WP_Error
	 */
	private static function window( WP_REST_Request $request ) {
		$from = (string) $request->get_param( 'from' );
		$to   = (string) $request->get_param( 'to' );

		if ( ! self::is_date( $from ) || ! self::is_date( $to ) || $to < $from ) {
			return Errors::rest( 'bad_window', __( 'Give a start date and an end date, in that order.', 'blueworx-forge' ), 400 );
		}

		$days = ( (int) strtotime( $to . ' 00:00:00 UTC' ) - (int) strtotime( $from . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS;

		if ( $days > self::MAX_DAYS ) {
			return Errors::rest( 'window_too_long', __( 'That is longer than a year. Ask for a shorter period.', 'blueworx-forge' ), 400 );
		}

		return array( $from, $to );
	}

	/**
	 * Whether a string is a date this can work with.
	 *
	 * @param string $date Candidate.
	 * @return bool
	 */
	private static function is_date( string $date ): bool {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date );
	}
}
