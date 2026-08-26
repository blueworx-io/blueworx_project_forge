<?php
/**
 * The studio's request review queue.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Tenancy\Capabilities;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Clients;
use Blueworx\Forge\Tenancy\Reach;
use Blueworx\Forge\Work\Conversion;
use Blueworx\Forge\Work\Items;
use Blueworx\Forge\Work\Queue;
use Blueworx\Forge\Work\Stages;
use Blueworx\Forge\Work\Submissions;
use Blueworx\Forge\Work\Transition;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Studio request review queue (#131).
 *
 * Two routes: read everything clients have asked for, and write the studio's
 * answer to one of them. They are separate because they are guarded
 * differently — reading the queue is what any staff member with reach can do,
 * and answering is a capability (`review_submission`).
 *
 * The read declares itself a list scope, which is the boundary's way of saying
 * "this callback filters by reach itself". It has to: a queue names no site, so
 * there is no one record for the boundary to check. That makes
 * {@see Queue::visible()} the tenant check for this whole screen, and it runs
 * before the caller's filters rather than beside them.
 *
 * A third route turns an answered request into work (#132). It sits here rather
 * than on the work-items controller for one reason, and it is the reason the
 * whole issue exists: the client site the work lands on comes off the
 * submission, and nothing in the request body can name one. A conversion route
 * hung off `/work-items` would have taken a `client_site_id` like every other
 * write there, and D-40 would then be a validation rule rather than a missing
 * parameter.
 */
final class SubmissionsController {

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		Server::register_route(
			$route_namespace,
			'/submissions',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'index' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				/*
				 * A list, filtered by the callback. The filters themselves are
				 * not declared here for the same reason the board's are not:
				 * they are a closed list decided by Work\Queue, and a second
				 * copy of that list in this file would be wrong the first time
				 * one of them changed.
				 */
				'scope'               => array(
					'kind'   => Boundary::SCOPE_LIST,
					'reason' => 'The queue spans clients by design; Queue::visible() scopes it.',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/submissions/(?P<submission_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( self::class, 'respond' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_LIST,
					'reason' => 'The submission is fetched and reach-checked in the callback, which needs the row to know its client.',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/submissions/(?P<submission_id>[A-Za-z0-9_\-]+)/conversion',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'convert' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_LIST,
					'reason' => 'The submission is fetched and reach-checked in the callback, which needs the row to know its client; the work it becomes inherits that client rather than naming one.',
				),

				/*
				 * No client and no site among the arguments, and that absence is
				 * the D-40 guarantee rather than an oversight. Both are read off
				 * the submission, which got them from the signature that carried
				 * it (#129), so there is no parameter here to edit into another
				 * client's pipeline.
				 */
				'args'                => array(
					'entry_stage' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * The queue.
	 *
	 * Somebody who reaches nothing is told so rather than shown an empty queue
	 * (#125). The two look identical in a list and mean completely different
	 * things — "not yours to see" and "nobody has asked for anything".
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public static function index( WP_REST_Request $request ): WP_REST_Response {
		$reach = Boundary::current();

		if ( Reach::is_nothing( $reach ) ) {
			return rest_ensure_response(
				array(
					'ok'          => true,
					'denied'      => true,
					'submissions' => array(),
					'states'      => self::states(),
				)
			);
		}

		$visible = Queue::visible( $reach, Submissions::all() );
		$rows    = Queue::keep( $visible, Queue::sanitise( $request->get_query_params() ) );

		return rest_ensure_response(
			array(
				'ok'          => true,
				'denied'      => false,
				'generated'   => bwx_forge_now(),
				'states'      => self::states(),
				'total'       => count( $visible ),
				'submissions' => Queue::rows( $rows, array( Clients::class, 'get' ) ),
			)
		);
	}

	/**
	 * Records the studio's answer to one request.
	 *
	 * The row is read before anything else, because its client is what both
	 * the reach check and the capability check are about — neither can be
	 * answered from the id alone.
	 *
	 * A submission out of reach is answered as absent rather than refused
	 * (D-1, D-2): a 403 here would confirm that an id belongs to a real client
	 * to somebody with no business knowing it.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function respond( WP_REST_Request $request ) {
		$id  = (string) $request->get_param( 'submission_id' );
		$row = self::reachable( $id );

		if ( ! is_array( $row ) ) {
			return $row;
		}

		$refusal = Access::refuse_unless( Capabilities::REVIEW_SUBMISSION, (string) $row['client_id'] );

		if ( null !== $refusal ) {
			return $refusal;
		}

		$answered = Submissions::respond(
			$id,
			array(
				'intake_state' => $request->get_param( 'intake_state' ),
				'response'     => $request->get_param( 'response' ),
			)
		);

		if ( null === $answered ) {
			return Errors::rest(
				'not_saved',
				__( 'That could not be saved.', 'blueworx-forge' ),
				500
			);
		}

		return rest_ensure_response(
			array(
				'ok'         => true,
				'submission' => Queue::rows( array( $answered ), array( Clients::class, 'get' ) )[0],
			)
		);
	}

	/**
	 * Turns a request into work, in the pipeline it was asked from (#132).
	 *
	 * The order of the four things this does is the security argument, so it is
	 * worth reading as an order rather than as steps:
	 *
	 * 1. **The submission is fetched and reach-checked**, exactly as the triage
	 *    write is, and answered as absent when it is somebody else's (D-1, D-2).
	 * 2. **The client and the site are taken from it**, and from nowhere else.
	 *    The body is never consulted for either, and the route declares no
	 *    argument that could carry one — which is what makes converting into
	 *    another client's pipeline impossible rather than merely refused (D-40).
	 * 3. **Every id the body *does* carry is checked against that site.** A
	 *    parent or an item on another site is answered as one that does not
	 *    exist, so the queue — the one studio screen that spans clients — cannot
	 *    be used to find out which ids are real elsewhere.
	 * 4. **Only then is anything written**, and the submission's own text is not
	 *    among it. Work\Submissions::mark_converted() writes the link and the
	 *    state; what the client wrote is not on its list (REQ-1).
	 *
	 * Two capabilities rather than one, and only where each is exercised.
	 * Answering a request is `review_submission`; making work is
	 * `create_work_item`, and a conversion that only links to work somebody
	 * already made does not create any. Asking for both regardless would be
	 * asking for a permission the action does not use.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function convert( WP_REST_Request $request ) {
		$id  = (string) $request->get_param( 'submission_id' );
		$row = self::reachable( $id );

		if ( ! is_array( $row ) ) {
			return $row;
		}

		$client  = (string) $row['client_id'];
		$refusal = Access::refuse_unless( Capabilities::REVIEW_SUBMISSION, $client );

		if ( null !== $refusal ) {
			return $refusal;
		}

		$asked = Conversion::read( (array) $request->get_json_params() );
		$links = Conversion::links( $asked );

		if ( ! $links ) {
			$refusal = Access::refuse_unless( Capabilities::CREATE_WORK_ITEM, $client );

			if ( null !== $refusal ) {
				return $refusal;
			}

			/*
			 * Entering at Triage is a move, so it is asked for as one. The
			 * client transition lock already makes this unreachable from a
			 * client role — review_submission is `no` for both of them, on both
			 * interfaces, so nobody locked out of moving work gets this far —
			 * but the rule is that every stage change asks, and a route that
			 * moved work without asking would be the exception that makes the
			 * lock a habit (#115).
			 */
			if ( Stages::FIRST !== (string) $asked['entry_stage'] ) {
				$refusal = Access::refuse_unless( Capabilities::MOVE_FORWARD, $client );

				if ( null !== $refusal ) {
					return $refusal;
				}
			}
		}

		$refused = Conversion::refuse(
			$row,
			$asked,
			'' === $asked['parent_id'] ? null : Items::get( (string) $asked['parent_id'] ),
			'' === $asked['item_id'] ? null : Items::get( (string) $asked['item_id'] )
		);

		if ( Conversion::ALLOWED !== $refused ) {
			return Errors::rest( $refused, Conversion::reason( $refused ), Conversion::status( $refused ) );
		}

		$closed = $links ? null : self::refuse_closed_site( (string) $row['client_site_id'] );

		if ( null !== $closed ) {
			return $closed;
		}

		$item = $links ? Items::get( (string) $asked['item_id'] ) : self::make( $row, $asked );

		if ( null === $item ) {
			return Errors::rest( 'write_failed', __( 'That work could not be saved.', 'blueworx-forge' ), 500 );
		}

		Transition::record_conversion( $item, $row, get_current_user_id() );

		$linked = Submissions::mark_converted( $id, (string) $item['id'] );

		if ( null === $linked ) {
			/*
			 * The work exists and the link does not. Answering with the item's
			 * id rather than a bare failure, because the alternative is work
			 * sitting on a board with nothing saying what it was for and
			 * nobody able to find it from the request. Somebody can finish the
			 * link by hand; nobody can recover a card they were never told
			 * about.
			 */
			return Errors::rest(
				'not_linked',
				__( 'The work was created, but the request could not be linked to it.', 'blueworx-forge' ),
				500,
				array( 'item_id' => (string) $item['id'] )
			);
		}

		return rest_ensure_response(
			array(
				'ok'         => true,
				'item'       => Items::get( (string) $item['id'] ),
				'submission' => Queue::rows( array( $linked ), array( Clients::class, 'get' ) )[0],
			)
		);
	}

	/**
	 * The submission, if this caller may see it at all.
	 *
	 * Shared by the triage write and the conversion so the two answer
	 * identically. Two routes each writing their own 404 is how one of them
	 * ends up saying something the other is careful not to.
	 *
	 * @param string $id Submission id.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function reachable( string $id ) {
		$row = Submissions::get( $id );

		if ( null === $row || ! Reach::reaches_site( Boundary::current(), (string) $row['client_id'], (string) $row['client_site_id'] ) ) {
			return Errors::rest(
				'no_such_submission',
				__( 'There is no such request.', 'blueworx-forge' ),
				404
			);
		}

		return $row;
	}

	/**
	 * Refuses new work on a site that has been closed.
	 *
	 * The same rule the create route applies, for the same reason: a closed
	 * site is one nobody is working on, and quietly adding work to it is how a
	 * board comes back from the dead. Linking to work that already exists is
	 * not caught by this — that work was made while the site was open, and
	 * recording what it answers changes nothing about whether anybody is
	 * working on it.
	 *
	 * @param string $client_site_id The submission's own site.
	 * @return \WP_Error|null Null when there is nothing to refuse.
	 */
	private static function refuse_closed_site( string $client_site_id ) {
		$site = ClientSites::get( $client_site_id );

		if ( null !== $site && 'active' === (string) $site['status'] ) {
			return null;
		}

		return Errors::rest(
			'inactive_client_site',
			__( 'That site is closed; reactivate it before adding work.', 'blueworx-forge' ),
			409
		);
	}

	/**
	 * Creates the work a request becomes, and the parent it hangs under.
	 *
	 * The site and the client are the submission's, passed positionally into
	 * Items::create() exactly as the create route passes a site's own. There is
	 * no branch here where they could be anything else.
	 *
	 * A parent created along the way is created first and separately, with its
	 * own creation entry, because it is an item in its own right — other work
	 * will hang under it later, and a parent with no history of being made is a
	 * row that appeared from nowhere.
	 *
	 * @param array<string, mixed> $submission The request being converted.
	 * @param array<string, mixed> $asked      Already through Conversion::read().
	 * @return array<string, mixed>|null Null when a write failed.
	 */
	private static function make( array $submission, array $asked ): ?array {
		$site      = (string) $submission['client_site_id'];
		$client    = (string) $submission['client_id'];
		$actor     = get_current_user_id();
		$parent_id = (string) $asked['parent_id'];

		if ( Conversion::creates_parent( $asked ) ) {
			$parent = Items::create( $site, $client, Conversion::parent_values( $asked ), $actor );

			if ( null === $parent ) {
				return null;
			}

			Transition::record_creation( $parent, $actor );

			$parent_id = (string) $parent['id'];
		}

		$item = Items::create( $site, $client, Conversion::values( $submission, $asked, $parent_id ), $actor );

		if ( null === $item ) {
			return null;
		}

		Transition::record_creation( $item, $actor );

		if ( Stages::FIRST === (string) $asked['entry_stage'] ) {
			return $item;
		}

		return self::into_triage( $item, $submission, $actor );
	}

	/**
	 * Moves new work to Triage, through the gate rather than around it.
	 *
	 * Conversion has genuinely answered what G-FUTURE-IDEA asks, so the answers
	 * are recorded as gate records with the converting person's name on them
	 * and the ordinary move is then made. Nothing here skips a check: if the
	 * move is refused after all, the work stays at Future Idea and is returned
	 * as it stands rather than the conversion failing. The request has still
	 * become work — which is what was asked for — and where it sits is a
	 * question somebody can answer on the board.
	 *
	 * @param array<string, mixed> $item       The work just created.
	 * @param array<string, mixed> $submission The request it answers.
	 * @param int                  $actor      WordPress user id of the converter.
	 * @return array<string, mixed> The item, moved or not.
	 */
	private static function into_triage( array $item, array $submission, int $actor ): array {
		Transition::record_intake_gate( $item, $submission, $actor );

		$moved = Transition::move( $item, 'triage', (int) $item['record_version'], $actor );

		return is_wp_error( $moved ) ? $item : $moved;
	}

	/**
	 * The intake states, each with the words a person reads.
	 *
	 * Sent with the queue rather than built in the browser, the same rule the
	 * board's stages follow: a screen that turned 'in-review' into English
	 * itself would be a second copy of that vocabulary that nobody updates the
	 * day a state is added.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function states(): array {
		return array_map(
			static fn( string $state ): array => array(
				'slug'  => $state,
				'label' => Submissions::label( $state ),
			),
			Submissions::STATES
		);
	}
}
