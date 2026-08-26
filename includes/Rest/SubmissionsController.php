<?php
/**
 * The studio's request review queue.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Tenancy\Capabilities;
use Blueworx\Forge\Tenancy\Clients;
use Blueworx\Forge\Tenancy\Reach;
use Blueworx\Forge\Work\Queue;
use Blueworx\Forge\Work\Submissions;
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
		$row = Submissions::get( $id );

		if ( null === $row || ! Reach::reaches_site( Boundary::current(), (string) $row['client_id'], (string) $row['client_site_id'] ) ) {
			return Errors::rest(
				'no_such_submission',
				__( 'There is no such request.', 'blueworx-forge' ),
				404
			);
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
