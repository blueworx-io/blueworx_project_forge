<?php
/**
 * REST permission callbacks.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use SplObjectStorage;
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
	 * Somebody at all, with the real question asked afterwards.
	 *
	 * Used by the routes that move work. It looks weaker than manage() and is
	 * not: every one of those routes then asks Rest\Access, which refuses
	 * anybody whose membership does not carry the capability — and refuses
	 * every client role outright, which is the transition lock (#115). What it
	 * buys is that a staff member who is not a WordPress administrator can do
	 * their job, which manage() made impossible.
	 *
	 * The reads are deliberately still on manage(). Scoping a read to the sites
	 * a membership grants is #92's job, and opening them before that exists
	 * would be a hole rather than a permission.
	 *
	 * @return bool
	 */
	public static function signed_in(): bool {
		return is_user_logged_in();
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
		/*
		 * WordPress asks about the same request twice: once to decide whether it
		 * may proceed, and again after it has, from rest_send_allow_header(),
		 * which calls every handler's permission callback purely to work out
		 * which methods to name in the Allow header.
		 *
		 * Verifying a signed request is not a question that can be asked twice:
		 * the nonce is single-use, so the second ask was refused as a replay and
		 * written to the security log. The request worked; the log filled with
		 * refusals that never happened, which is the worse failure — a log of
		 * false alarms is one nobody reads when a real alarm arrives.
		 *
		 * So the answer is remembered against the request object itself. Two
		 * genuinely separate requests are two objects, and the second still gets
		 * verified in full: this only stops one request being asked about twice.
		 */
		static $answers = null;

		if ( null === $answers ) {
			$answers = new SplObjectStorage();
		}

		if ( isset( $answers[ $request ] ) ) {
			return $answers[ $request ];
		}

		$answer = Signature::verify(
			(string) $request->get_header( Signature::HEADER_SITE ),
			(string) $request->get_header( Signature::HEADER_SIGNATURE ),
			(string) $request->get_header( Signature::HEADER_TIMESTAMP ),
			(string) $request->get_header( Signature::HEADER_NONCE ),
			(string) $request->get_method(),
			(string) $request->get_route(),
			(string) $request->get_body()
		);

		$answers[ $request ] = $answer;

		return $answer;
	}
}
