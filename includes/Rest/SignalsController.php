<?php
/**
 * The in-product signals.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Signals\Inbox;
use Blueworx\Forge\Signals\Kinds;
use Blueworx\Forge\Signals\Seen;
use Blueworx\Forge\Tenancy\Reach;
use WP_REST_Request;
use WP_REST_Response;

/**
 * #175. What has happened lately, to records this person may read.
 *
 * A list scope, like the daily list's and the request queue's, and for the same
 * reason: the route names no record, so there is nothing for the boundary to
 * check, and {@see Inbox::for_reach()} is the tenant check for the whole
 * answer. It runs before anything is read.
 *
 * The one write is the smallest write in the product — a person saying they
 * have looked. It carries the moment the list they read was worked out rather
 * than taking the clock here, because anything that happened while they were
 * reading is still new to them.
 */
final class SignalsController {

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		Server::register_route(
			$route_namespace,
			'/signals',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'index' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_LIST,
					'reason' => 'The signal list spans clients by design (#175): it is what has happened lately to everything one person holds. It names no record, and Signals\Inbox scopes it by reach before anything is read.',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/signals/seen',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'seen' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Marking your own signals read touches nothing but your own user meta (#175). There is no record to scope it to, and no way to write anybody else\'s marker: the id it writes against is the signed-in user\'s, never a parameter.',
				),
				'args'                => array(
					'at' => array(
						'type'     => 'integer',
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * What has happened lately.
	 *
	 * Somebody who reaches nothing is told so rather than shown an empty list
	 * (#125). The two look identical and want different things done about them.
	 *
	 * @return WP_REST_Response
	 */
	public static function index(): WP_REST_Response {
		$reach = Boundary::current();

		if ( Reach::is_nothing( $reach ) ) {
			return rest_ensure_response(
				array(
					'ok'      => true,
					'denied'  => true,
					'signals' => array(),
					'unread'  => 0,
				)
			);
		}

		$now     = bwx_forge_now();
		$user    = get_current_user_id();
		$seen_at = Seen::at( $user );
		$signals = Inbox::for_reach( $reach, $user, $now, $seen_at );

		return rest_ensure_response(
			array(
				'ok'        => true,
				'denied'    => false,

				/*
				 * The moment this answer was worked out, sent so the screen can
				 * hand it back when somebody says they have read it. Taking the
				 * clock at that point instead would mark seen whatever arrived
				 * while they were reading.
				 */
				'generated' => $now,
				'seen_at'   => $seen_at,
				'unread'    => Inbox::unread( $signals ),
				'kinds'     => Kinds::WORTH_SAYING,
				'signals'   => $signals,
			)
		);
	}

	/**
	 * Records that this person has read up to a moment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function seen( WP_REST_Request $request ): WP_REST_Response {
		$body = (array) $request->get_json_params();
		$at   = (int) ( $body['at'] ?? $request->get_param( 'at' ) ?? 0 );
		$now  = bwx_forge_now();

		/*
		 * A moment in the future is refused by being clamped rather than by an
		 * error: a browser clock a few minutes fast is common and harmless, and
		 * honouring it would mark unread everything that happens in the next few
		 * minutes. Zero or missing means now, which is what a plain "mark all
		 * read" with nothing to say for itself means.
		 */
		$at = $at > 0 ? min( $at, $now ) : $now;

		return rest_ensure_response(
			array(
				'ok'      => true,
				'seen_at' => Seen::mark( get_current_user_id(), $at ),
			)
		);
	}
}
