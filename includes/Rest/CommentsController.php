<?php
/**
 * The comment and evidence routes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Work\Comments;
use Blueworx\Forge\Work\Items;
use WP_REST_Request;
use WP_REST_Response;

/**
 * #100. Discussion and evidence on a work item, inheriting that item's tenant
 * scoping, with internal notes in their own permission scope.
 *
 * **The scope is resolved server-side, per request, from the membership.** It is
 * never taken from the request: a client asking for the internal view gets the
 * client view, because the parameter that would grant it does not exist.
 */
final class CommentsController {

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		Server::register_route(
			$route_namespace,
			'/work-items/(?P<item_id>[A-Za-z0-9_\-]+)/comments',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'index' ),

				/*
				 * Not manage(). A client user has to be able to read their own
				 * comments, and the read itself is scoped by the membership
				 * rather than by the capability — which is the only way #100's
				 * acceptance can hold, since "client reads client-visible
				 * comments" and "client cannot reach internal notes" are one
				 * route answering two ways.
				 */
				'permission_callback' => array( self::class, 'may_read' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_ITEM,
					'param'  => 'item_id',
					'record' => 'work_item',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/work-items/(?P<item_id>[A-Za-z0-9_\-]+)/comments',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create' ),
				'permission_callback' => array( self::class, 'may_read' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_ITEM,
					'param'  => 'item_id',
					'record' => 'work_item',
				),
				'args'                => array(
					'body' => array( 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * Whether the caller is somebody at all. Which comments they then see is
	 * decided by their scope, not here.
	 *
	 * @return bool
	 */
	public static function may_read(): bool {
		return is_user_logged_in();
	}

	/**
	 * The comments this reader may see on an item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function index( WP_REST_Request $request ) {
		$found = self::resolve( $request );

		if ( ! is_array( $found ) ) {
			return $found;
		}

		return rest_ensure_response(
			array(
				'ok'       => true,
				'scope'    => $found['scope'],
				'comments' => Comments::for_item( (string) $found['item']['id'], $found['scope'] ),
			)
		);
	}

	/**
	 * Adds a comment, a piece of evidence or an attachment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$found = self::resolve( $request );

		if ( ! is_array( $found ) ) {
			return $found;
		}

		$body    = (array) $request->get_json_params();
		$item    = $found['item'];
		$author  = wp_get_current_user();
		$comment = Comments::add(
			array(
				'item_id'        => (string) $item['id'],
				'client_site_id' => (string) $item['client_site_id'],
				'client_id'      => (string) $item['client_id'],
				'kind'           => (string) ( $body['kind'] ?? Comments::COMMENT ),
				'visibility'     => (string) ( $body['visibility'] ?? Comments::INTERNAL ),
				'body'           => (string) ( $body['body'] ?? '' ),
				'url'            => (string) ( $body['url'] ?? '' ),
				'author'         => get_current_user_id(),
				'author_name'    => $author instanceof \WP_User ? (string) $author->display_name : '',
			),
			$found['scope']
		);

		if ( null === $comment ) {
			return Errors::rest(
				'comment_refused',
				__( 'That could not be saved. A comment needs something in it, and evidence needs a link.', 'blueworx-forge' ),
				400
			);
		}

		return rest_ensure_response(
			array(
				'ok'      => true,
				'comment' => $comment,
			)
		);
	}

	/**
	 * The item and the caller's scope on it, or the refusal.
	 *
	 * A reader with no membership on the item's client is refused outright
	 * rather than shown an empty list. "There is nothing here" and "this is not
	 * yours to read" are different answers, and #125 renders them differently.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{item: array<string, mixed>, scope: string}|\WP_Error
	 */
	private static function resolve( WP_REST_Request $request ) {
		$item = Items::get( (string) $request['item_id'] );

		if ( null === $item ) {
			return Boundary::absent( 'work_item' );
		}

		$scope = Scope::current( (string) $item['client_id'] );

		if ( Scope::NONE === $scope ) {
			return Errors::rest(
				'not_your_client',
				__( 'You do not have access to this work.', 'blueworx-forge' ),
				403
			);
		}

		return array(
			'item'  => $item,
			'scope' => $scope,
		);
	}
}
