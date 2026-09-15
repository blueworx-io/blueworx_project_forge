<?php
/**
 * Slack, over REST: sending the morning message now.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Slack\Morning;

/**
 * A person's own connection is made on their WordPress profile
 * (Admin\ProfileSlack), not here. The one route is for administrators, so the
 * morning message can be sent on demand — and so a test can make it happen.
 */
final class SlackController {

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		Server::register_route(
			$route_namespace,
			'/slack/morning',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'morning' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Sends the morning message now; administrators only, and it reads nothing back to the caller.',
				),
			)
		);
	}

	/**
	 * Sends the morning message now.
	 *
	 * @return \WP_REST_Response
	 */
	public static function morning() {
		return rest_ensure_response(
			array(
				'ok'   => true,
				'sent' => Morning::run(),
			)
		);
	}
}
