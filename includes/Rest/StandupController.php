<?php
/**
 * The daily list.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Standup\Board;
use Blueworx\Forge\Standup\Rules;
use Blueworx\Forge\Tenancy\Reach;
use WP_REST_Response;

/**
 * #169. One read: what needs attention today.
 *
 * A list scope, like the request queue's and the onboarding board's, and for
 * the same reason: the route names no record, so there is nothing for the
 * boundary to check, and {@see Board::for_reach()} is the tenant check for the
 * whole screen. It runs before any rule does.
 *
 * There is no write here and there is not going to be one. Dismissing a card is
 * the obvious next route to ask for and it is exactly what #169 exists to
 * refuse: a card is on this list because a condition is true, and the only way
 * to clear it is to make the condition false. Acting on a card is #171, and
 * every action there goes through the route that already governs the thing
 * being acted on — so the permission that applies is the one that always
 * applies, rather than a second copy of it living here.
 */
final class StandupController {

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		Server::register_route(
			$route_namespace,
			'/standup',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'index' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_LIST,
					'reason' => 'The day\'s list spans clients by design (#169): it is what one person has to deal with today, across everything they hold. It names no record, and Standup\Board scopes every part of it by reach before any rule is evaluated.',
				),
			)
		);
	}

	/**
	 * What needs attention today.
	 *
	 * Somebody who reaches nothing is told so rather than shown an empty list
	 * (#125). "Not yours to see" and "nothing needs doing" are the same picture
	 * and want completely different things done about them — and of the two,
	 * being wrongly told there is nothing to do is the one that costs a day.
	 *
	 * Takes no parameters, and there is nothing to add. The list is everything
	 * true about everything this person reaches; narrowing it is the board's
	 * job (#170), done over an answer that is already scoped, so a filter here
	 * could only ever hide work somebody is meant to see.
	 *
	 * @return WP_REST_Response
	 */
	public static function index(): WP_REST_Response {
		$reach = Boundary::current();

		if ( Reach::is_nothing( $reach ) ) {
			return rest_ensure_response(
				array(
					'ok'     => true,
					'denied' => true,
					'cards'  => array(),
					'rules'  => Rules::ALL,
				)
			);
		}

		$today = gmdate( 'Y-m-d', bwx_forge_now() );
		$cards = Board::for_reach( $reach, $today );

		return rest_ensure_response(
			array(
				'ok'        => true,
				'denied'    => false,

				/*
				 * The day it was worked out for travels with the answer. A board
				 * left open overnight is a board showing yesterday's "due today",
				 * and the only way a screen can know that is to be told which day
				 * it was given.
				 */
				'today'     => $today,
				'generated' => bwx_forge_now(),
				'rules'     => Rules::ALL,
				'cards'     => $cards,
			)
		);
	}
}
