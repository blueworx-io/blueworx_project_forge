<?php
/**
 * What the client app reads.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Rest;

use Blueworx\Forge\Client\Board;
use Blueworx\Forge\Client\Checklist;
use Blueworx\Forge\Client\Digest;
use Blueworx\Forge\Client\Sales;
use Blueworx\Forge\Client\Submissions;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The read routes the client app is built on (#298): the board, the hours
 * and packages, the launch checklist and the submissions, each answered by
 * the same class the wp-admin screen for it already calls.
 *
 * Nothing here works anything out. Each route hands back the view the
 * screen renders, through the same cache, so the app and the screen beside
 * it can never disagree about what this site last heard from the studio.
 * The browser cannot ask the studio itself: the signing key lives on this
 * server and stays there.
 */
final class ReadController {

	/**
	 * The routes, and the class whose view answers each.
	 *
	 * @var array<string, class-string>
	 */
	private const ROUTES = array(
		'/board'       => Board::class,
		'/sales'       => Sales::class,
		'/checklist'   => Checklist::class,
		'/submissions' => Submissions::class,
	);

	/**
	 * Registers the routes.
	 */
	public static function register_routes(): void {
		foreach ( self::ROUTES as $route => $source ) {
			register_rest_route(
				WorkspaceController::NAMESPACE,
				$route,
				array(
					'methods'             => 'GET',
					'callback'            => static function ( WP_REST_Request $request ) use ( $source ): WP_REST_Response {
						$view = $source::view( (bool) $request->get_param( 'refresh' ) );

						// The two lists the overview screen draws from the board
						// (#299), worked out by the same code, so the page and
						// the screen name the same work as wanting attention.
						if ( Board::class === $source && $view['ok'] ) {
							$today             = gmdate( 'Y-m-d' );
							$view['attention'] = Digest::attention( (array) $view['items'], $today );
							$view['upcoming']  = Digest::upcoming( (array) $view['items'], $today );
						}

						// Every step that is the client's to do now (#299). The view
						// names one — the next — for the screen that asks one thing at
						// a time; the page lists them all, by the same rule.
						if ( Checklist::class === $source ) {
							$view['yours'] = array_values( array_filter( (array) $view['steps'], array( Checklist::class, 'is_theirs' ) ) );
						}

						return rest_ensure_response( $view );
					},
					// Never public, for the same reason as the workspace: every
					// one of these names the client and describes their work.
					'permission_callback' => array( WorkspaceController::class, 'can_manage' ),
					'args'                => array(
						'refresh' => array(
							'type'     => 'boolean',
							'required' => false,
							'default'  => false,
						),
					),
				)
			);
		}
	}
}
