<?php
/**
 * The work item routes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Work\Comments;
use Blueworx\Forge\Work\Events;
use Blueworx\Forge\Work\GateRecords;
use Blueworx\Forge\Work\Gates;
use Blueworx\Forge\Work\Items;
use Blueworx\Forge\Work\Outcomes;
use Blueworx\Forge\Work\Returns;
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
	 * The ways work moves other than forwards, as route path to callback.
	 */
	private const MOVES = array(
		'return'  => 'send_back',
		'block'   => 'block',
		'unblock' => 'unblock',
		'outcome' => 'outcome',
		'archive' => 'archive',
	);

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

		/*
		 * One route per way work moves, rather than one route with a mode
		 * parameter. Each of these has different requirements — a reason, a
		 * resolution note, five blocker answers — and a single endpoint
		 * switching on a string would validate whichever set the caller
		 * happened to name.
		 */
		foreach ( self::MOVES as $path => $callback ) {
			Server::register_route(
				$route_namespace,
				'/work-items/(?P<item_id>[A-Za-z0-9_\-]+)/' . $path,
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, $callback ),
					'permission_callback' => array( Permissions::class, 'manage' ),
					'args'                => array(
						Versioning::PARAM => array(
							'type'     => 'integer',
							'required' => false,
						),
					),
				)
			);
		}

		Server::register_route(
			$route_namespace,
			'/work-items/(?P<item_id>[A-Za-z0-9_\-]+)/gate',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'record_gate' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'args'                => array(
					'requirement' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/gates',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'gates' ),
				// The gate definitions are the product's own rules, not anybody's
				// data. A person about to be refused a move is better off having
				// read them.
				'permission_callback' => array( Permissions::class, 'read' ),
			)
		);

		Server::register_route(
			$route_namespace,
			'/stages',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'stages' ),
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

		// Archived work is out of the default view and in every report (#111),
		// so a caller has to ask for it by name.
		if ( $request->has_param( 'include_archived' ) ) {
			$filters['include_archived'] = rest_sanitize_boolean( $request->get_param( 'include_archived' ) );
		}

		return rest_ensure_response(
			array(
				'ok'    => true,
				'items' => Items::for_site( $site['id'], $filters ),
			)
		);
	}

	/**
	 * The gate registry: every requirement of every gate.
	 *
	 * @return WP_REST_Response
	 */
	public static function gates(): WP_REST_Response {
		return rest_ensure_response(
			array(
				'ok'    => true,
				'gates' => Gates::all(),
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

		$children = Items::children( $item['id'] );
		$history  = Events::for_item( $item['id'] );
		$scope    = Scope::current( (string) $item['client_id'] );

		/*
		 * Everything a screen needs to draw the item's options, worked out here
		 * rather than by each screen inferring it. A board that decides for
		 * itself which moves exist is a second implementation of the state
		 * machine, and the two disagree the first time one of them changes.
		 */
		$readiness = array();

		foreach ( Transitions::next_from( $item['stage'], $item['work_type'] ) as $to ) {
			$readiness[ $to ] = Transition::readiness( $item, $to, $children );
		}

		return rest_ensure_response(
			array(
				'ok'          => true,
				'item'        => $item,
				'children'    => $children,
				'history'     => $history,
				'available'   => array_keys( $readiness ),
				// What each of those moves is still waiting on, so a person can
				// see the gate before it refuses them rather than after.
				'readiness'   => $readiness,
				'returns'     => Returns::targets( $item, $history ),
				'outcomes'    => Outcomes::available_for( $item ),
				'can_archive' => Outcomes::may_archive( $item ),
				'records'     => GateRecords::current_for( $item ),
				'comments'    => Comments::for_item( $item['id'], Scope::NONE === $scope ? Comments::SCOPE_CLIENT : $scope ),
				'scope'       => $scope,
			)
		);
	}

	/**
	 * Records one gate requirement as satisfied (#105).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function record_gate( WP_REST_Request $request ) {
		$item = Items::get( (string) $request['item_id'] );

		if ( null === $item ) {
			return Errors::rest( 'unknown_work_item', __( 'There is no such work item.', 'blueworx-forge' ), 404 );
		}

		$body        = (array) $request->get_json_params();
		$requirement = (string) ( $body['requirement'] ?? $request->get_param( 'requirement' ) );

		if ( null === Gates::requirement( $requirement ) ) {
			return Errors::rest( 'unknown_requirement', __( 'There is no such gate requirement.', 'blueworx-forge' ), 400 );
		}

		$record = GateRecords::complete(
			array(
				'item_id'        => (string) $item['id'],
				'client_site_id' => (string) $item['client_site_id'],
				'requirement'    => $requirement,
				'value'          => (string) ( $body['value'] ?? '' ),
				'evidence'       => (string) ( $body['evidence'] ?? '' ),
				'cycle'          => (int) $item['cycle'],
				'attempt'        => (int) $item['review_attempt'],
				'actor'          => get_current_user_id(),
			)
		);

		if ( null === $record ) {
			/*
			 * Three refusals share this answer, and all three are the same
			 * failure from the caller's side: nobody signed it, the evidence a
			 * requirement demands was not supplied, or the write failed.
			 */
			return Errors::rest(
				'gate_record_refused',
				__( 'That could not be recorded. A completion needs a signed-in person, and a requirement that asks for evidence needs evidence.', 'blueworx-forge' ),
				400
			);
		}

		$refreshed = Items::get( (string) $item['id'] );

		return rest_ensure_response(
			array(
				'ok'      => true,
				'record'  => $record,
				'records' => GateRecords::current_for( null === $refreshed ? $item : $refreshed ),
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

		return self::answer( Transition::move( $item, (string) $request->get_param( 'to' ), (int) $sent, get_current_user_id() ) );
	}

	/**
	 * Sends an item back to a stage it has occupied (#108).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function send_back( WP_REST_Request $request ) {
		$ready = self::ready( $request );

		if ( ! is_array( $ready ) ) {
			return $ready;
		}

		$body = (array) $request->get_json_params();

		return self::answer(
			Transition::send_back(
				$ready['item'],
				(string) ( $body['to'] ?? '' ),
				(string) ( $body['reason'] ?? '' ),
				(string) ( $body['feedback'] ?? '' ),
				Events::for_item( (string) $ready['item']['id'] ),
				$ready['version'],
				get_current_user_id()
			)
		);
	}

	/**
	 * Blocks an item, storing where it came from (#109).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function block( WP_REST_Request $request ) {
		$ready = self::ready( $request );

		if ( ! is_array( $ready ) ) {
			return $ready;
		}

		$body = (array) $request->get_json_params();

		return self::answer(
			Transition::block(
				$ready['item'],
				array(
					'reason'      => (string) ( $body['reason'] ?? '' ),
					'owner'       => (string) ( $body['owner'] ?? '' ),
					'dependency'  => (string) ( $body['dependency'] ?? '' ),
					'target_date' => (string) ( $body['target_date'] ?? '' ),
					'next_action' => (string) ( $body['next_action'] ?? '' ),
				),
				$ready['version'],
				get_current_user_id()
			)
		);
	}

	/**
	 * Returns a blocked item to exactly the stage it left (#109).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function unblock( WP_REST_Request $request ) {
		$ready = self::ready( $request );

		if ( ! is_array( $ready ) ) {
			return $ready;
		}

		$body = (array) $request->get_json_params();

		return self::answer(
			Transition::unblock( $ready['item'], (string) ( $body['resolution'] ?? '' ), $ready['version'], get_current_user_id() )
		);
	}

	/**
	 * Ends an item at one of the WF-2 outcomes (#111).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function outcome( WP_REST_Request $request ) {
		$ready = self::ready( $request );

		if ( ! is_array( $ready ) ) {
			return $ready;
		}

		$body = (array) $request->get_json_params();

		return self::answer(
			Transition::end(
				$ready['item'],
				(string) ( $body['outcome'] ?? '' ),
				array(
					'reason'       => (string) ( $body['reason'] ?? '' ),
					'duplicate_of' => (string) ( $body['duplicate_of'] ?? '' ),
				),
				$ready['version'],
				get_current_user_id()
			)
		);
	}

	/**
	 * Puts an ended item out of the default views (#111).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function archive( WP_REST_Request $request ) {
		$ready = self::ready( $request );

		if ( ! is_array( $ready ) ) {
			return $ready;
		}

		return self::answer( Transition::archive( $ready['item'], $ready['version'], get_current_user_id() ) );
	}

	/**
	 * The item and the version every move route needs, or the refusal that
	 * stops it: no such item, or a write against a version that has moved on.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{item: array<string, mixed>, version: int}|\WP_Error|WP_REST_Response
	 */
	private static function ready( WP_REST_Request $request ) {
		$item = Items::get( (string) $request['item_id'] );

		if ( null === $item ) {
			return Errors::rest( 'unknown_work_item', __( 'There is no such work item.', 'blueworx-forge' ), 404 );
		}

		$sent  = $request->get_param( Versioning::PARAM );
		$stale = Versioning::check( null === $sent ? null : (int) $sent, $item['record_version'], $item );

		if ( null !== $stale ) {
			return $stale;
		}

		return array(
			'item'    => $item,
			'version' => (int) $sent,
		);
	}

	/**
	 * The one answer shape every move route gives.
	 *
	 * A gate failure is separated out here rather than in each route: it is not
	 * an error in the WP_Error sense — the request was well-formed and allowed,
	 * the item simply is not ready — and it has its own documented body.
	 *
	 * @param array<string, mixed>|\WP_Error $moved What the transition service said.
	 * @return WP_REST_Response|\WP_Error
	 */
	private static function answer( $moved ) {
		if ( is_wp_error( $moved ) ) {
			if ( Transition::GATE_FAILURE === $moved->get_error_code() ) {
				return Errors::from_gate_failure( $moved );
			}

			return $moved;
		}

		return rest_ensure_response(
			array(
				'ok'        => true,
				'item'      => $moved,
				'available' => Transitions::next_from( $moved['stage'], $moved['work_type'] ),
				'returns'   => Returns::targets( $moved, Events::for_item( (string) $moved['id'] ) ),
				'outcomes'  => Outcomes::available_for( $moved ),
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
