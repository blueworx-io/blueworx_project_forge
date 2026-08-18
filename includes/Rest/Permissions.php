<?php
/**
 * REST permission callbacks.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use WP_REST_Request;

/**
 * The permission callbacks, in one place and as plain static methods, so each
 * can be tested without a WordPress runtime and so every route's answer to "who
 * may call this" is visible in a single file.
 */
final class Permissions {

	/**
	 * Reading is deliberately public: the app serves a read-only view to
	 * logged-out visitors. Any route returning something a visitor must not see
	 * uses manage() instead — this is not a default, it is a decision per route.
	 *
	 * @return bool
	 */
	public static function read(): bool {
		return true;
	}

	/**
	 * Anything that changes state, or reads configuration, requires the site's
	 * administrator capability.
	 *
	 * @return bool
	 */
	public static function manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * A request from a registered client site, proven by its signature.
	 *
	 * This is the only callback that authenticates something other than a logged-in
	 * WordPress user: a client site is a machine, and it proves which client it is
	 * with a per-site key (ARCH-6) rather than by having a user account here.
	 *
	 * The path signed is the REST route the server resolved, not the raw URL, so
	 * the two ends cannot disagree about a prefix or a trailing slash.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function client_site( WP_REST_Request $request ) {
		return Signature::verify(
			(string) $request->get_header( Signature::HEADER_SITE ),
			(string) $request->get_header( Signature::HEADER_SIGNATURE ),
			(string) $request->get_header( Signature::HEADER_TIMESTAMP ),
			(string) $request->get_header( Signature::HEADER_NONCE ),
			(string) $request->get_method(),
			(string) $request->get_route(),
			(string) $request->get_body()
		);
	}
}
