<?php
/**
 * The routes for a person's own saved views.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Work\SavedViews;
use WP_REST_Request;
use WP_REST_Response;

/**
 * #123. A person's own shortcuts to a way of looking at the work.
 *
 * Outside the tenant boundary, and that is a decision rather than an oversight:
 * a saved view is held against a WordPress account, so one person's views are
 * not reachable from another's request at all. There is no client record in one
 * — Work\Filters builds what is stored key by key, so a view holds a name, some
 * filters and a grouping and nothing else.
 *
 * What the view *does* when it is opened is scoped like everything else: it
 * turns into filters on /work-items, which is scoped to the site named there.
 * A saved view changes what is shown and never what is allowed.
 */
final class SavedViewsController {

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		$scope = array(
			'kind'   => Boundary::SCOPE_OPEN,
			'reason' => 'A person\'s own saved views, held against their WordPress account. No client record is in one, and one person\'s are not reachable from another\'s request.',
		);

		Server::register_route(
			$route_namespace,
			'/saved-views',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'index' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => $scope,
			)
		);

		Server::register_route(
			$route_namespace,
			'/saved-views',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => $scope,
				'args'                => array(
					'name' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/saved-views/(?P<view_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( self::class, 'remove' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => $scope,
			)
		);
	}

	/**
	 * The current person's saved views.
	 *
	 * @return WP_REST_Response
	 */
	public static function index(): WP_REST_Response {
		return rest_ensure_response(
			array(
				'ok'    => true,
				'views' => SavedViews::for_user( get_current_user_id() ),
			)
		);
	}

	/**
	 * Saves one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$view = SavedViews::save( get_current_user_id(), (array) $request->get_json_params() );

		if ( null === $view ) {
			/*
			 * Three refusals share this answer and all three are the same
			 * failure from the caller's side: no name, nobody signed in, or the
			 * list is full. The message names all three rather than guessing
			 * which one somebody hit.
			 */
			return Errors::rest(
				'view_not_saved',
				sprintf(
					/* translators: %d: the most saved views one person may keep. */
					__( 'That view could not be saved. A view needs a name, and nobody may keep more than %d.', 'blueworx-forge' ),
					SavedViews::MOST
				),
				400
			);
		}

		return rest_ensure_response(
			array(
				'ok'   => true,
				'view' => $view,
			)
		);
	}

	/**
	 * Removes one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function remove( WP_REST_Request $request ) {
		if ( ! SavedViews::remove( get_current_user_id(), (string) $request['view_id'] ) ) {
			// Somebody else's view gets the same answer as one that does not
			// exist, which is both true and all the caller needs to know.
			return Errors::rest( 'unknown_view', __( 'There is no such saved view.', 'blueworx-forge' ), 404 );
		}

		return rest_ensure_response(
			array(
				'ok'    => true,
				'views' => SavedViews::for_user( get_current_user_id() ),
			)
		);
	}
}
