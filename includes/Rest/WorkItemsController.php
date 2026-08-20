<?php
/**
 * The work item routes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Work\Events;
use Blueworx\Forge\Work\Items;
use Blueworx\Forge\Work\Stages;
use Blueworx\Forge\Work\Transition;
use Blueworx\Forge\Work\Transitions;
use Blueworx\Forge\Work\Validate;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Work items (#96, #97) and the one route that moves them (#106).
 *
 * The board is built on these four reads and two writes, and the split between
 * them is deliberate: PATCH changes what an item *is*, and only the transition
 * route changes where it *is*. A single endpoint doing both would make a stage
 * change look like an ordinary field edit, which is exactly what the gates
 * exist to prevent.
 *
 * Gated to Permissions::manage() for now; #91 swaps each for a capability, and
 * #92 puts the site scoping behind a layer rather than in each callback.
 */
final class WorkItemsController {

	/**
	 * Name this write is remembered under, scoped per site at the call site.
	 */
	private const CREATE_OPERATION = 'create_work_item';

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		Server::register_route(
			$route_namespace,
			'/work-items',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'index' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'args'                => array(
					'client_site_id' => array(
						'type'     => 'string',
						'required' => true,
					),
					'stage'          => array( 'type' => 'string' ),
					'level'          => array( 'type' => 'string' ),
					'work_type'      => array( 'type' => 'string' ),
					'parent_id'      => array( 'type' => 'string' ),
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/work-items',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
			)
		);

		Server::register_route(
			$route_namespace,
			'/work-items/(?P<item_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'show' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
			)
		);

		Server::register_route(
			$route_namespace,
			'/work-items/(?P<item_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( self::class, 'update' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'args'                => array(
					Versioning::PARAM => array(
						'type'        => 'integer',
						'required'    => false,
						'description' => 'The record version this write was made against.',
					),
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/work-items/(?P<item_id>[A-Za-z0-9_\-]+)/transition',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'transition' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'args'                => array(
					'to'              => array(
						'type'     => 'string',
						'required' => true,
					),
					Versioning::PARAM => array(
						'type'     => 'integer',
						'required' => false,
					),
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/stages',
			array(
				'methods'  => 'GET',
				'callback' => array( self::class, 'stages' ),
				// Read-only and derived from a class constant. There is nothing
				// here a visitor could not infer from the product itself.
				'permission_callback' => array( Permissions::class, 'read' ),
			)
		);
	}

	/**
	 * The stage registry, as the board's columns.
	 *
	 * @return WP_REST_Response
	 */
	public static function stages(): WP_REST_Response {
		$stages = array();

		foreach ( Stages::ALL as $stage ) {
			$stages[] = array(
				'id'    => $stage,
				'label' => Stages::label( $stage ),
				'kind'  => Stages::kind( $stage ),
			);
		}

		return rest_ensure_response(
			array(
				'ok'      => true,
				'stages'  => $stages,
				// The board's columns are the linear ones: Blocked is somewhere
				// an item goes from a column, and Bug Tracking only exists for
				// bugs.
				'columns' => Stages::linear(),
			)
		);
	}

	/**
	 * The work on one site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function index( WP_REST_Request $request ) {
		$site = ClientSites::get( (string) $request->get_param( 'client_site_id' ) );

		if ( null === $site ) {
			return Errors::rest( 'unknown_client_site', __( 'There is no such client site.', 'blueworx-forge' ), 404 );
		}

		$filters = array();

		foreach ( array( 'stage', 'level', 'work_type', 'parent_id' ) as $filter ) {
			if ( $request->has_param( $filter ) ) {
				$filters[ $filter ] = (string) $request->get_param( $filter );
			}
		}

		return rest_ensure_response(
			array(
				'ok'    => true,
				'items' => Items::for_site( $site['id'], $filters ),
			)
		);
	}

	/**
	 * One item, with its history.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function show( WP_REST_Request $request ) {
		$item = Items::get( (string) $request['item_id'] );

		if ( null === $item ) {
			return Errors::rest( 'unknown_work_item', __( 'There is no such work item.', 'blueworx-forge' ), 404 );
		}

		return rest_ensure_response(
			array(
				'ok'        => true,
				'item'      => $item,
				'children'  => Items::children( $item['id'] ),
				'history'   => Events::for_item( $item['id'] ),
				// What a board or detail screen may offer as the next step,
				// decided here rather than by each screen working it out.
				'available' => Transitions::next_from( $item['stage'], $item['work_type'] ),
			)
		);
	}

	/**
	 * Creates an item, at Future Idea.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$body = (array) $request->get_json_params();
		$site = ClientSites::get( (string) ( $body['client_site_id'] ?? '' ) );

		if ( null === $site ) {
			return Errors::rest( 'unknown_client_site', __( 'There is no such client site.', 'blueworx-forge' ), 404 );
		}

		if ( 'active' !== (string) $site['status'] ) {
			return Errors::rest(
				'inactive_client_site',
				__( 'That site is closed; reactivate it before adding work.', 'blueworx-forge' ),
				409
			);
		}

		$key       = (string) $request->get_header( Idempotency::HEADER );
		$operation = self::CREATE_OPERATION . ':' . $site['id'];

		// Scoped per site, for the reason #88 fixed the hard way: two sites
		// reusing one retry key must never answer each other's replays.
		if ( '' !== $key ) {
			if ( ! Idempotency::is_valid_key( $key ) ) {
				return Errors::rest( 'invalid_idempotency_key', __( 'That retry key cannot be used.', 'blueworx-forge' ), 400 );
			}

			$replay = Idempotency::replay( $operation, $key );

			if ( null !== $replay ) {
				return rest_ensure_response( $replay );
			}
		}

		$checked = Validate::item( $body, false );

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_work_item',
				__( 'That work could not be saved.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		$parent_error = self::parent_error( (string) $checked['values']['parent_id'], (string) $checked['values']['level'], $site['id'] );

		if ( null !== $parent_error ) {
			return $parent_error;
		}

		$item = Items::create( $site['id'], (string) $site['client_id'], $checked['values'], get_current_user_id() );

		if ( null === $item ) {
			return Errors::rest( 'write_failed', __( 'That work could not be saved.', 'blueworx-forge' ), 500 );
		}

		Transition::record_creation( $item, get_current_user_id() );

		$response = array(
			'ok'   => true,
			'item' => $item,
		);

		if ( '' !== $key ) {
			Idempotency::remember( $operation, $key, $response );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Edits an item's fields. Never its stage.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function update( WP_REST_Request $request ) {
		$item = Items::get( (string) $request['item_id'] );

		if ( null === $item ) {
			return Errors::rest( 'unknown_work_item', __( 'There is no such work item.', 'blueworx-forge' ), 404 );
		}

		$sent  = $request->get_param( Versioning::PARAM );
		$stale = Versioning::check( null === $sent ? null : (int) $sent, $item['record_version'], $item );

		if ( null !== $stale ) {
			return $stale;
		}

		$checked = Validate::item( (array) $request->get_json_params(), true );

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_work_item',
				__( 'That change could not be saved.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		if ( array_key_exists( 'parent_id', $checked['values'] ) ) {
			$level = (string) ( $checked['values']['level'] ?? $item['level'] );

			$parent_error = self::parent_error( (string) $checked['values']['parent_id'], $level, (string) $item['client_site_id'], (string) $item['id'] );

			if ( null !== $parent_error ) {
				return $parent_error;
			}
		}

		$updated = Items::update( $item['id'], $checked['values'], (int) $sent );

		if ( null === $updated ) {
			$current = Items::get( $item['id'] );

			$mismatch = Versioning::check(
				(int) $sent,
				null === $current ? 0 : $current['record_version'],
				null === $current ? array() : $current
			);

			if ( null !== $mismatch ) {
				return $mismatch;
			}

			return Errors::rest( 'write_failed', __( 'That change could not be saved.', 'blueworx-forge' ), 500 );
		}

		return rest_ensure_response(
			array(
				'ok'   => true,
				'item' => $updated,
			)
		);
	}

	/**
	 * Moves an item to the next stage.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function transition( WP_REST_Request $request ) {
		$item = Items::get( (string) $request['item_id'] );

		if ( null === $item ) {
			return Errors::rest( 'unknown_work_item', __( 'There is no such work item.', 'blueworx-forge' ), 404 );
		}

		$sent  = $request->get_param( Versioning::PARAM );
		$stale = Versioning::check( null === $sent ? null : (int) $sent, $item['record_version'], $item );

		if ( null !== $stale ) {
			return $stale;
		}

		$moved = Transition::move( $item, (string) $request->get_param( 'to' ), (int) $sent, get_current_user_id() );

		if ( is_wp_error( $moved ) ) {
			return $moved;
		}

		return rest_ensure_response(
			array(
				'ok'        => true,
				'item'      => $moved,
				'available' => Transitions::next_from( $moved['stage'], $moved['work_type'] ),
			)
		);
	}

	/**
	 * Refuses a parent that does not exist, sits on another site, or is not a
	 * higher level than the child.
	 *
	 * @param string $parent_id      The proposed parent, or '' for none.
	 * @param string $level          The child's level.
	 * @param string $client_site_id The site the child belongs to.
	 * @param string $child_id       The child's own id, when it has one.
	 * @return \WP_Error|null Null when there is nothing to refuse.
	 */
	private static function parent_error( string $parent_id, string $level, string $client_site_id, string $child_id = '' ) {
		if ( '' === $parent_id ) {
			return null;
		}

		if ( $parent_id === $child_id ) {
			return Errors::rest( 'invalid_parent', __( 'Work cannot be its own parent.', 'blueworx-forge' ), 400 );
		}

		$parent = Items::get( $parent_id );

		/*
		 * A parent on another site gets the same answer as a parent that does
		 * not exist. Saying "that exists but is not yours" would confirm which
		 * ids are real on sites the caller has nothing to do with — and the
		 * grant itself is the one ARCH-3 exists to prevent, since an item whose
		 * parent sits elsewhere is reachable from two tenants at once.
		 */
		if ( null === $parent || (string) $parent['client_site_id'] !== $client_site_id ) {
			return Errors::rest( 'unknown_parent', __( 'There is no such parent item.', 'blueworx-forge' ), 404 );
		}

		if ( ! \Blueworx\Forge\Work\Levels::may_parent( (string) $parent['level'], $level ) ) {
			return Errors::rest(
				'invalid_parent',
				__( 'A parent has to be a higher level than the work beneath it.', 'blueworx-forge' ),
				400,
				array(
					'parent_level' => $parent['level'],
					'level'        => $level,
				)
			);
		}

		return null;
	}
}
