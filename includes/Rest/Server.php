<?php
/**
 * REST namespace registration.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use InvalidArgumentException;

/**
 * One place that knows which controllers exist, and the one door every route is
 * registered through.
 *
 * A controller missing from the list below is a route that silently does not
 * exist, so new controllers are added here and nowhere else. A route that does
 * not say who may call it, or how it is scoped to a tenant, never registers at
 * all — see register_route().
 */
final class Server {

	/**
	 * The plugin's REST namespace.
	 *
	 * Versioned, and the version is part of the path rather than a header: a
	 * breaking change ships as /v2 alongside /v1 rather than by changing what /v1
	 * means underneath a client that cannot be redeployed at the same moment.
	 */
	public const NAMESPACE = 'blueworx-forge/v1';

	/**
	 * Registers a route, refusing any that does not declare who may call it and
	 * how it is scoped.
	 *
	 * WordPress treats a missing or null permission_callback as "anyone", warning
	 * about it and carrying on. That default is how one forgotten line becomes an
	 * open endpoint, so this refuses instead: a route with no answer to "who may
	 * call this" does not register, and the mistake is a failed test rather than
	 * a live hole. A deliberately public route says so explicitly, with
	 * Permissions::read().
	 *
	 * The scope declaration (#92) is the same rule asked of the other question a
	 * route must answer: which tenant's records is this. Boundary::apply() both
	 * refuses a route that does not say, and wraps the callback so that what it
	 * says is enforced rather than merely documented.
	 *
	 * The two are separate questions and both are asked. Permission is what
	 * somebody may do; scope is whose records they may do it to. A route that
	 * checks only the first lets a legitimate user act on somebody else's data.
	 *
	 * @param string               $route_namespace REST namespace.
	 * @param string               $route           Route pattern.
	 * @param array<string, mixed> $args            Route arguments.
	 *
	 * @throws InvalidArgumentException When no permission callback is declared.
	 */
	public static function register_route( string $route_namespace, string $route, array $args ): void {
		if ( ! array_key_exists( 'permission_callback', $args ) || null === $args['permission_callback'] ) {
			throw new InvalidArgumentException(
				sprintf(
					'%s%s was registered without a permission callback. Every route must say who may call it; a public one says so with Permissions::read().',
					esc_html( $route_namespace ),
					esc_html( $route )
				)
			);
		}

		register_rest_route( $route_namespace, $route, Boundary::apply( $route_namespace . $route, $args ) );
	}

	/**
	 * Registers every controller's routes. Hooked to rest_api_init.
	 */
	public static function register_routes(): void {
		StatusController::register_routes( self::NAMESPACE );
		SitesController::register_routes( self::NAMESPACE );
		ClientController::register_routes( self::NAMESPACE );
		ClientsController::register_routes( self::NAMESPACE );
		ClientSitesController::register_routes( self::NAMESPACE );
		IntegrationsController::register_routes( self::NAMESPACE );
		UsersController::register_routes( self::NAMESPACE );
		MembershipsController::register_routes( self::NAMESPACE );
		WorkItemsController::register_routes( self::NAMESPACE );
		CommentsController::register_routes( self::NAMESPACE );
		SavedViewsController::register_routes( self::NAMESPACE );
		SubmissionsController::register_routes( self::NAMESPACE );
	}
}
