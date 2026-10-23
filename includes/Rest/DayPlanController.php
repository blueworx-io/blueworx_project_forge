<?php
/**
 * The routes for the order a person sets on their day.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Work\DayPlan;
use WP_REST_Request;
use WP_REST_Response;

/**
 * #620. Outside the tenant boundary for the same reason saved views are: it is
 * held against a WordPress account, carries task ids and nothing else, and one
 * person's is not reachable from another's request.
 */
final class DayPlanController {

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		$scope = array(
			'kind'   => Boundary::SCOPE_OPEN,
			'reason' => 'The order a person sets on their own day, held against their WordPress account. It carries task ids and nothing else, and one person\'s is not reachable from another\'s request.',
		);

		Server::register_route(
			$route_namespace,
			'/day-plan',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'show' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => $scope,
			)
		);

		Server::register_route(
			$route_namespace,
			'/day-plan',
			array(
				'methods'             => 'PUT',
				'callback'            => array( self::class, 'save' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => $scope,
			)
		);
	}

	/**
	 * The current person's order for a day.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function show( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response(
			array_merge(
				array( 'ok' => true ),
				DayPlan::for_day( get_current_user_id(), (string) $request->get_param( 'day' ) )
			)
		);
	}

	/**
	 * Saves the current person's order for a day.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function save( WP_REST_Request $request ) {
		$body = (array) $request->get_json_params();
		$day  = (string) ( $body['day'] ?? '' );

		if ( ! DayPlan::save( get_current_user_id(), $day, (array) ( $body['order'] ?? array() ), (array) ( $body['down'] ?? array() ) ) ) {
			return Errors::rest( 'plan_not_saved', __( 'That plan could not be saved. It needs a day.', 'blueworx-forge' ), 400 );
		}

		return rest_ensure_response(
			array_merge(
				array( 'ok' => true ),
				DayPlan::for_day( get_current_user_id(), $day )
			)
		);
	}
}
