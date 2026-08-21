<?php
/**
 * The work item routes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Tenancy\Capabilities;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Work\Changelog;
use Blueworx\Forge\Work\Comments;
use Blueworx\Forge\Work\Dependencies;
use Blueworx\Forge\Work\Derived;
use Blueworx\Forge\Work\Events;
use Blueworx\Forge\Work\Fields;
use Blueworx\Forge\Work\Filters;
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
 * Since #91 the routes that move work are gated on being signed in, and then on
 * the capability the move actually needs — which is what lets a staff member
 * who is not a WordPress administrator do their job, and what refuses every
 * client role by the same door (#115).
 *
 * Since #92 the reads are too. They were held on manage() until the tenant
 * boundary existed, because opening them before anything scoped them would have
 * been a hole rather than a permission; now every route here declares the record
 * it is about, and Rest\Boundary answers for a site the caller does not reach
 * exactly as it would for an id nobody has ever used.
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
		'return'   => 'send_back',
		'block'    => 'block',
		'unblock'  => 'unblock',
		'outcome'  => 'outcome',
		'archive'  => 'archive',
		'reopen'   => 'reopen',
		'override' => 'override',
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
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_SITE,
					'param'  => 'client_site_id',
					'record' => 'client_site',
				),
				/*
				 * Only the site is declared. The filters are not, deliberately:
				 * they are a closed list decided by Work\Filters, and declaring
				 * each one here would be a second list of what a filter may be
				 * — kept in a different file, and wrong the first time somebody
				 * changes one of them.
				 *
				 * Nothing reaches a query unchecked as a result. Anything
				 * Work\Filters does not recognise, by name or by value, is
				 * dropped rather than applied.
				 */
				'args'                => array(
					'client_site_id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/work-items',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_SITE,
					'param'  => 'client_site_id',
					'record' => 'client_site',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/work-items/(?P<item_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'show' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_ITEM,
					'param'  => 'item_id',
					'record' => 'work_item',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/work-items/(?P<item_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( self::class, 'update' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_ITEM,
					'param'  => 'item_id',
					'record' => 'work_item',
				),
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
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_ITEM,
					'param'  => 'item_id',
					'record' => 'work_item',
				),
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
					'permission_callback' => array( Permissions::class, 'signed_in' ),
					'scope'               => array(
						'kind'   => Boundary::SCOPE_ITEM,
						'param'  => 'item_id',
						'record' => 'work_item',
					),
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
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_ITEM,
					'param'  => 'item_id',
					'record' => 'work_item',
				),
				'args'                => array(
					'requirement' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		/*
		 * #103. Adding and removing a dependency are their own routes rather
		 * than fields on the item, because a dependency is a relationship
		 * between two records: an edit that set a list would have to decide what
		 * happens to the ones it left out, and would silently remove them.
		 */
		Server::register_route(
			$route_namespace,
			'/work-items/(?P<item_id>[A-Za-z0-9_\-]+)/dependencies',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'add_dependency' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_ITEM,
					'param'  => 'item_id',
					'record' => 'work_item',
				),
				'args'                => array(
					'depends_on_id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/work-items/(?P<item_id>[A-Za-z0-9_\-]+)/dependencies/(?P<dependency_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( self::class, 'remove_dependency' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_ITEM,
					'param'  => 'item_id',
					'record' => 'work_item',
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
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'The gate definitions are the product\'s own rules. A person about to be refused a move is better off having read them.',
				),
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
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'The stage registry is the product\'s own shape, derived from a class constant. Nothing in it belongs to a client.',
				),
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
			return Boundary::absent( 'client_site' );
		}

		$narrow = array();

		/*
		 * The four the query itself can narrow on, kept as query narrowing
		 * rather than folded into the filter model: reading a site's whole
		 * history into memory to throw most of it away is not the same as asking
		 * for less. Everything else is decided by Work\Filters, over what comes
		 * back.
		 */
		foreach ( array( 'stage', 'level', 'work_type', 'parent_id' ) as $filter ) {
			$value = $request->get_param( $filter );

			if ( is_string( $value ) && '' !== $value ) {
				$narrow[ $filter ] = $value;
			}
		}

		// Archived work is out of the default view and in every report (#111),
		// so a caller has to ask for it by name.
		if ( $request->has_param( 'include_archived' ) ) {
			$narrow['include_archived'] = rest_sanitize_boolean( $request->get_param( 'include_archived' ) );
		}

		/*
		 * #123. One filter model, applied here, so that every view is rendering
		 * one answer. A view that filtered the list it was handed would be a
		 * second thing deciding what a filter means, and #124 exists because
		 * two of those disagree.
		 */
		$filters = Filters::sanitise( (array) $request->get_params() );
		$items   = Filters::apply(
			self::derive_across( (string) $site['id'], Items::for_site( $site['id'], $narrow ) ),
			$filters
		);

		$grouping = Filters::grouping( (string) $request->get_param( 'group_by' ) );

		$answer = array(
			'ok'      => true,
			'items'   => $items,

			// Counted from the same array the items came out of, so a total and
			// a list cannot disagree even in principle.
			'total'   => count( $items ),

			// Echoed back, so a screen can show what it is actually filtering by
			// rather than what it thinks it asked for.
			'filters' => $filters,
		);

		if ( '' !== $grouping ) {
			$answer['grouping'] = $grouping;
			$answer['groups']   = Filters::group( $items, $grouping );
		}

		return rest_ensure_response( $answer );
	}

	/**
	 * Fills in what each item's children make it (#101).
	 *
	 * Done for the whole set at once, from one extra read of the site, rather
	 * than a query per parent. A board with forty cards on it would otherwise
	 * cost forty-one queries to answer, and the board is the screen somebody
	 * leaves open all day.
	 *
	 * The child map is built from **every** item on the site, not from the
	 * filtered set: a parent's progress is what its children are, and filtering
	 * the board to one column must not change what a card says about itself.
	 *
	 * @param string                           $site_id The site.
	 * @param array<int, array<string, mixed>> $items   The items being returned.
	 * @return array<int, array<string, mixed>>
	 */
	private static function derive_across( string $site_id, array $items ): array {
		$children = array();

		foreach ( Items::for_site( $site_id, array( 'include_archived' => true ) ) as $candidate ) {
			$parent = (string) $candidate['parent_id'];

			if ( '' !== $parent ) {
				$children[ $parent ][] = $candidate;
			}
		}

		foreach ( $items as $index => $item ) {
			$items[ $index ] = array_merge( $item, Derived::fields( $children[ (string) $item['id'] ] ?? array() ) );
		}

		return $items;
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
			return Boundary::absent( 'work_item' );
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
				'ok'           => true,

				// #101. What the children make it, filled in on the way out
				// rather than stored — there is no column for anybody to write.
				'item'         => array_merge( $item, Derived::fields( $children ) ),
				'children'     => $children,
				'history'      => $history,
				'available'    => array_keys( $readiness ),
				// What each of those moves is still waiting on, so a person can
				// see the gate before it refuses them rather than after.
				'readiness'    => $readiness,
				'returns'      => Returns::targets( $item, $history ),
				'outcomes'     => Outcomes::available_for( $item ),
				'can_archive'  => Outcomes::may_archive( $item ),
				'records'      => GateRecords::current_for( $item ),
				'comments'     => Comments::for_item( $item['id'], Scope::NONE === $scope ? Comments::SCOPE_CLIENT : $scope ),
				'scope'        => $scope,

				// #103. What this waits on, what waits on it, and which of the
				// first are not going to move on their own.
				'dependencies' => self::dependency_view( $item ),
			)
		);
	}

	/**
	 * What one item waits on, what waits on it, and what that adds up to (#103).
	 *
	 * @param array<string, mixed> $item The item.
	 * @return array<string, mixed>
	 */
	private static function dependency_view( array $item ): array {
		$upstream   = self::items_for( Dependencies::for_item( (string) $item['id'] ), 'depends_on_id' );
		$downstream = self::items_for( Dependencies::waiting_on( (string) $item['id'] ), 'item_id' );

		return array(
			'upstream'   => $upstream,
			'downstream' => $downstream,
			'summary'    => Dependencies::summarise( $upstream ),
		);
	}

	/**
	 * The items a set of dependency rows point at.
	 *
	 * Only the parts a screen shows. A dependency panel wants a title and
	 * whether the thing is moving, not another whole work item each — and
	 * returning the whole record would quietly make the panel a second place
	 * that renders an item.
	 *
	 * @param array<int, array<string, mixed>> $rows The dependency rows.
	 * @param string                           $key  Which end to resolve.
	 * @return array<int, array<string, mixed>>
	 */
	private static function items_for( array $rows, string $key ): array {
		$items = array();

		foreach ( $rows as $row ) {
			$item = Items::get( (string) $row[ $key ] );

			if ( null === $item ) {
				continue;
			}

			$items[] = array(
				'dependency_id'    => (string) $row['id'],
				'id'               => (string) $item['id'],
				'title'            => (string) $item['title'],
				'stage'            => (string) $item['stage'],
				'terminal_outcome' => (string) $item['terminal_outcome'],
				'planned_start'    => (string) $item['planned_start'],
				'planned_due'      => (string) $item['planned_due'],
			);
		}

		return $items;
	}

	/**
	 * Makes one item wait on another (#103).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function add_dependency( WP_REST_Request $request ) {
		$item = Items::get( (string) $request['item_id'] );

		if ( null === $item ) {
			return Boundary::absent( 'work_item' );
		}

		// Changing what work waits on is planning, not definition: it moves
		// dates, and a client cannot move our dates.
		$refused = self::permit( Capabilities::EDIT_PLANNING, $item );

		if ( null !== $refused ) {
			return $refused;
		}

		$body     = (array) $request->get_json_params();
		$upstream = Items::get( (string) ( $body['depends_on_id'] ?? $request->get_param( 'depends_on_id' ) ) );

		/*
		 * Work on another site gets the same answer as work that does not exist.
		 * Saying "that is real but elsewhere" would confirm which ids are live on
		 * a site the caller has nothing to do with, and the dependency itself is
		 * the thing ARCH-3 forbids — an item waiting on another tenant's work is
		 * reachable from two tenants at once.
		 */
		if ( null === $upstream || (string) $upstream['client_site_id'] !== (string) $item['client_site_id'] ) {
			return Boundary::absent( 'work_item' );
		}

		$refusal = Dependencies::refuse(
			(string) $item['id'],
			(string) $upstream['id'],
			Dependencies::chain_on_site( (string) $item['client_site_id'] )
		);

		if ( null !== $refusal ) {
			return Errors::rest(
				'invalid_dependency',
				Dependencies::SELF === $refusal
					? __( 'Work cannot wait on itself.', 'blueworx-forge' )
					: __( 'That would leave the two waiting on each other, and neither could ever start.', 'blueworx-forge' ),
				400,
				array( 'refused_because' => $refusal )
			);
		}

		$added = Dependencies::add(
			(string) $item['id'],
			(string) $upstream['id'],
			(string) $item['client_site_id'],
			get_current_user_id()
		);

		if ( null === $added ) {
			// The unique index refuses a repeat. Saying the same thing twice is
			// not two dependencies, and it is not an error worth a 500 either.
			return Errors::rest(
				'duplicate_dependency',
				__( 'That work already waits on this.', 'blueworx-forge' ),
				409
			);
		}

		self::record_dependency( $item, $upstream, Events::DEPENDENCY_ADDED );

		return rest_ensure_response(
			array(
				'ok'           => true,
				'dependency'   => $added,
				'dependencies' => self::dependency_view( $item ),
			)
		);
	}

	/**
	 * Stops one item waiting on another (#103).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function remove_dependency( WP_REST_Request $request ) {
		$item = Items::get( (string) $request['item_id'] );

		if ( null === $item ) {
			return Boundary::absent( 'work_item' );
		}

		$refused = self::permit( Capabilities::EDIT_PLANNING, $item );

		if ( null !== $refused ) {
			return $refused;
		}

		$dependency = Dependencies::get( (string) $request['dependency_id'] );

		// A dependency belonging to another item is not this item's to remove,
		// and answering "no such dependency" is both true and all the caller
		// needs to know.
		if ( null === $dependency || (string) $dependency['item_id'] !== (string) $item['id'] ) {
			return Errors::rest( 'unknown_dependency', __( 'There is no such dependency.', 'blueworx-forge' ), 404 );
		}

		if ( ! Dependencies::remove( (string) $dependency['id'] ) ) {
			return Errors::rest( 'write_failed', __( 'That could not be removed.', 'blueworx-forge' ), 500 );
		}

		self::record_dependency( $item, Items::get( (string) $dependency['depends_on_id'] ), Events::DEPENDENCY_REMOVED );

		return rest_ensure_response(
			array(
				'ok'           => true,
				'dependencies' => self::dependency_view( $item ),
			)
		);
	}

	/**
	 * Writes the changelog entry a dependency change leaves behind.
	 *
	 * The row can be removed; this cannot. What survives a dependency being
	 * deleted is the record that it was there and that somebody took it away,
	 * which is the part anybody asks about afterwards.
	 *
	 * @param array<string, mixed>      $item     The item that waits.
	 * @param array<string, mixed>|null $upstream The item it waited for.
	 * @param string                    $action   Added or removed.
	 */
	private static function record_dependency( array $item, ?array $upstream, string $action ): void {
		Events::append(
			array(
				'item_id'          => (string) $item['id'],
				'client_site_id'   => (string) $item['client_site_id'],
				'action'           => $action,
				'field'            => 'dependencies',
				'new_value'        => null === $upstream ? '' : (string) $upstream['title'],
				'detail'           => null === $upstream ? '' : (string) $upstream['id'],
				'source_interface' => Capabilities::STUDIO,
				'cycle'            => (int) $item['cycle'],
				'attempt'          => (int) $item['review_attempt'],
				'actor'            => get_current_user_id(),
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
			return Boundary::absent( 'work_item' );
		}

		// D-18. Marking a gate requirement is a workflow act, so it is under the
		// lock with the rest of them.
		$refused = self::permit( Capabilities::COMPLETE_GATE, $item );

		if ( null !== $refused ) {
			return $refused;
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
			return Boundary::absent( 'client_site' );
		}

		if ( 'active' !== (string) $site['status'] ) {
			return Errors::rest(
				'inactive_client_site',
				__( 'That site is closed; reactivate it before adding work.', 'blueworx-forge' ),
				409
			);
		}

		/*
		 * The route asks only for a signed-in person since #92, so the real
		 * question is asked here. Reaching a site is not permission to add work
		 * to it: a client viewer reads their own site and creates nothing on it.
		 */
		$refused = Access::refuse_unless( Capabilities::CREATE_WORK_ITEM, (string) $site['client_id'] );

		if ( null !== $refused ) {
			return $refused;
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

		// Never from the caller: whether the self-review rule is waived is a fact
		// about the person's grants (AUTH-3), not a field they may send.
		$body['self_review_permitted'] = ! empty( Access::context( (string) $site['client_id'] )['principal'] );

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
			return Boundary::absent( 'work_item' );
		}

		$sent  = $request->get_param( Versioning::PARAM );
		$stale = Versioning::check( null === $sent ? null : (int) $sent, $item['record_version'], $item );

		if ( null !== $stale ) {
			return $stale;
		}

		$checked = Validate::item( self::body( $request, (string) $item['client_id'], $item ), true );

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_work_item',
				__( 'That change could not be saved.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		$refused = self::permit_edit( $item, $checked['values'] );

		if ( null !== $refused ) {
			return $refused;
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

		/*
		 * #99. One entry per field that actually changed, written from the
		 * values as they were before the update — which is why $item is used
		 * rather than re-reading, and why this happens after the write rather
		 * than before it. Recording a change that then failed would be worse
		 * than recording none.
		 */
		foreach ( Changelog::for_edit( $item, $checked['values'], self::change_context( $request ) ) as $entry ) {
			Events::append( $entry );
		}

		return rest_ensure_response(
			array(
				'ok'   => true,
				'item' => $updated,
			)
		);
	}

	/**
	 * Who made a change, from where, and why, for the changelog.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private static function change_context( WP_REST_Request $request ): array {
		$body = (array) $request->get_json_params();

		return array(
			'actor'            => get_current_user_id(),

			/*
			 * This controller serves the studio application. When the client
			 * workspace gets an edit route of its own it passes its own value
			 * here rather than this being inferred from anything — an interface
			 * that works out which interface it is gets it wrong the first time
			 * one calls the other.
			 */
			'source_interface' => Capabilities::STUDIO,
			'reason'           => (string) ( $body['reason'] ?? '' ),
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
			return Boundary::absent( 'work_item' );
		}

		$sent  = $request->get_param( Versioning::PARAM );
		$stale = Versioning::check( null === $sent ? null : (int) $sent, $item['record_version'], $item );

		if ( null !== $stale ) {
			return $stale;
		}

		$to = (string) $request->get_param( 'to' );

		/*
		 * Which capability this particular move needs, decided by the workflow
		 * rather than here: nine of the eleven forward moves want ordinary
		 * permission to move work, and two want the person the item names
		 * (#112).
		 */
		$capability = Transitions::capability_for( (string) $item['stage'], $to );
		$refused    = self::permit( $capability, $item );

		if ( null !== $refused ) {
			return $refused;
		}

		$context = Access::context( (string) $item['client_id'], $item );

		return self::answer(
			Transition::move(
				$item,
				$to,
				(int) $sent,
				get_current_user_id(),
				empty( $context['acting_as_substitute'] ) ? '' : Events::VIA_SUBSTITUTE
			)
		);
	}

	/**
	 * Reopens finished work as a new cycle (#113).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function reopen( WP_REST_Request $request ) {
		$ready = self::ready( $request, Capabilities::REOPEN );

		if ( ! is_array( $ready ) ) {
			return $ready;
		}

		$body = (array) $request->get_json_params();

		return self::answer(
			Transition::reopen(
				$ready['item'],
				(string) ( $body['to'] ?? '' ),
				(string) ( $body['reason'] ?? '' ),
				$ready['version'],
				get_current_user_id()
			)
		);
	}

	/**
	 * The WF-5 override (#114).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function override( WP_REST_Request $request ) {
		$ready = self::ready( $request, Capabilities::OVERRIDE );

		if ( ! is_array( $ready ) ) {
			return $ready;
		}

		$body = (array) $request->get_json_params();

		return self::answer(
			Transition::override(
				$ready['item'],
				(string) ( $body['to'] ?? '' ),
				(string) ( $body['reason'] ?? '' ),
				$ready['version'],
				get_current_user_id()
			)
		);
	}

	/**
	 * Sends an item back to a stage it has occupied (#108).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function send_back( WP_REST_Request $request ) {
		$ready = self::ready( $request, Capabilities::RETURN_ITEM );

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
		$ready = self::ready( $request, Capabilities::BLOCK_ITEM );

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
		$ready = self::ready( $request, Capabilities::BLOCK_ITEM );

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
		$ready = self::ready( $request, Capabilities::RECORD_OUTCOME );

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
		$ready = self::ready( $request, Capabilities::RECORD_OUTCOME );

		if ( ! is_array( $ready ) ) {
			return $ready;
		}

		return self::answer( Transition::archive( $ready['item'], $ready['version'], get_current_user_id() ) );
	}

	/**
	 * The item and the version every move route needs, or the refusal that
	 * stops it: no such item, or a write against a version that has moved on.
	 *
	 * @param WP_REST_Request $request    Request.
	 * @param string          $capability What the caller is about to exercise.
	 * @return array{item: array<string, mixed>, version: int}|\WP_Error|WP_REST_Response
	 */
	private static function ready( WP_REST_Request $request, string $capability ) {
		$item = Items::get( (string) $request['item_id'] );

		if ( null === $item ) {
			return Boundary::absent( 'work_item' );
		}

		$sent  = $request->get_param( Versioning::PARAM );
		$stale = Versioning::check( null === $sent ? null : (int) $sent, $item['record_version'], $item );

		if ( null !== $stale ) {
			return $stale;
		}

		$refused = self::permit( $capability, $item );

		if ( null !== $refused ) {
			return $refused;
		}

		return array(
			'item'    => $item,
			'version' => (int) $sent,
		);
	}

	/**
	 * Asks the permission layer, and refuses in the shape every route answers
	 * with.
	 *
	 * Every workflow mutation goes through here, which is what makes the client
	 * transition lock a lock rather than a habit (#115): there is no route that
	 * moves work without asking, so there is no route a client role can reach
	 * by finding the one that forgot.
	 *
	 * @param string               $capability What is being exercised.
	 * @param array<string, mixed> $item       The item it is being exercised on.
	 * @return \WP_Error|null Null when it is allowed.
	 */
	private static function permit( string $capability, array $item ) {
		return Access::refuse_unless( $capability, (string) $item['client_id'], $item );
	}

	/**
	 * The request body, with the facts a caller does not get to assert.
	 *
	 * `self_review_permitted` decides whether the Reviewer may be the same
	 * person as the Primary User (AUTH-3). That is a fact about the person's
	 * grants, not a field — so it is stripped from whatever arrived and set from
	 * the permission layer. A caller that could send it could waive the rule by
	 * saying it did not apply.
	 *
	 * @param WP_REST_Request           $request Request.
	 * @param string                    $client_id The client these records belong to.
	 * @param array<string, mixed>|null $item      The item, where there is one.
	 * @return array<string, mixed>
	 */
	private static function body( WP_REST_Request $request, string $client_id, ?array $item = null ): array {
		$body = (array) $request->get_json_params();

		unset( $body['self_review_permitted'] );

		$context = Access::context( $client_id, $item );

		$body['self_review_permitted'] = ! empty( $context['principal'] );

		return $body;
	}

	/**
	 * Which capability an edit needs, decided by which fields it touches.
	 *
	 * An edit is not one permission. The definition is a client's to write while
	 * the item is still being documented (AUTH-2); the seats and the dates are
	 * ours; the commercial classification is the Primary administrator's alone.
	 * Asking one question for all three would give whoever may write a title the
	 * ability to reclassify what a client is charged (D-20, D-21).
	 *
	 * @param array<string, mixed> $item   The item being edited.
	 * @param array<string, mixed> $values The values the edit would write.
	 * @return \WP_Error|null Null when every field in the edit is permitted.
	 */
	private static function permit_edit( array $item, array $values ) {
		$groups = array(
			Capabilities::EDIT_COMMERCIAL     => Fields::COMMERCIAL,
			Capabilities::EDIT_ACCOUNTABILITY => Fields::ACCOUNTABILITY,
			Capabilities::EDIT_PLANNING       => Fields::PLANNING,
			Capabilities::EDIT_DEFINITION     => Fields::DEFINITION,
		);

		// The substitute seats are the Primary administrator's to assign, and
		// nobody else's — an assignment anybody could make is not a control.
		$groups[ Capabilities::GRANT_CAPABILITY ] = Fields::SUBSTITUTES;

		foreach ( $groups as $capability => $fields ) {
			if ( array() === array_intersect( array_keys( $values ), $fields ) ) {
				continue;
			}

			$refused = Access::refuse_unless(
				$capability,
				(string) $item['client_id'],
				$item,
				array(
					// AUTH-2. A client writes the definition while the item is
					// still in Documentation Period, and comments afterwards.
					'before_documentation_ends' => Stages::position( (string) $item['stage'] )
						<= Stages::position( 'documentation-period' ),
				)
			);

			if ( null !== $refused ) {
				return $refused;
			}
		}

		return null;
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
