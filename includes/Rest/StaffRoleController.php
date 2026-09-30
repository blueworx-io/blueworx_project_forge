<?php
/**
 * What a person does here, over REST.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Tenancy\StaffRoles;
use Blueworx\Forge\Tenancy\Users;
use WP_REST_Request;

/**
 * #474. An administrator reads and sets anybody's role; a person reads their
 * own. Nothing here touches capacity: the hours are a note.
 */
final class StaffRoleController {

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		$scope = array(
			'kind'   => Boundary::SCOPE_OPEN,
			'reason' => 'A person\'s role is a global record (AUTH-6), not a client\'s. The administrator\'s to set, and the person\'s own to read.',
		);

		Server::register_route(
			$route_namespace,
			'/users/(?P<user_id>[A-Za-z0-9_\-]+)/role',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'read' ),
				'permission_callback' => array( Permissions::class, 'manage_or_self' ),
				'scope'               => $scope,
			)
		);

		Server::register_route(
			$route_namespace,
			'/users/(?P<user_id>[A-Za-z0-9_\-]+)/role',
			array(
				'methods'             => 'PUT',
				'callback'            => array( self::class, 'save' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => $scope,
			)
		);
	}

	/**
	 * A person's role.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function read( WP_REST_Request $request ) {
		$user = Users::get( (string) $request['user_id'] );

		if ( null === $user ) {
			return self::unknown_user();
		}

		return rest_ensure_response(
			array(
				'ok'   => true,
				'role' => StaffRoles::get( $user['id'] ),
			)
		);
	}

	/**
	 * Replaces a person's role.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save( WP_REST_Request $request ) {
		$user = Users::get( (string) $request['user_id'] );

		if ( null === $user ) {
			return self::unknown_user();
		}

		$checked = StaffRoles::clean( (array) $request->get_json_params() );

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_role',
				__( 'That could not be saved.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		StaffRoles::save( $user['id'], $checked['values'] );

		return rest_ensure_response(
			array(
				'ok'   => true,
				'role' => StaffRoles::get( $user['id'] ),
			)
		);
	}

	/**
	 * The refusal for somebody who is not there.
	 *
	 * @return \WP_Error
	 */
	private static function unknown_user() {
		return Errors::rest( 'unknown_user', __( 'There is no such person.', 'blueworx-forge' ), 404 );
	}
}
