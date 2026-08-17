<?php
/**
 * REST permission callbacks.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

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
}
