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
 * What the availability admin screen does, as routes, so the studio app can
 * be the one place this is done. The reads and writes are the ones
 * `Admin\AvailabilityActions` makes; the shapes are the Capacity classes'
 * own. Every answer is the whole picture for one person, so a screen that
 * has just written never has to read again.
 *
 * Administrators only, reads included: the admin page it replaces requires
 * the same, and a person's hours are configuration, not work.
 */
final class AvailabilityController {

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		$scope = array(
			'kind'   => Boundary::SCOPE_OPEN,
			'reason' => 'A person\'s hours are a global record (AUTH-6), not a client\'s. Administrator-only configuration.',
		);

		Server::register_route(
			$route_namespace,
			'/users/(?P<user_id>[A-Za-z0-9_\-]+)/availability',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'read' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
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
