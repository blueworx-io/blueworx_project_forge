<?php
defined( 'ABSPATH' ) || exit;

class Forge_PM_Roles {

	const MANAGER_ROLE = 'forge_manager';
	const USER_ROLE    = 'forge_user';

	public static function add_roles() {
		// Forge Manager — full item CRUD, limited Settings access (brands/categories/releases)
		if ( ! get_role( self::MANAGER_ROLE ) ) {
			add_role(
				self::MANAGER_ROLE,
				__( 'Forge Manager', 'forge-pm' ),
				array(
					'read'         => true,
					'edit_posts'   => true,
					'upload_files' => true,
				)
			);
		}

		// Forge User — view only (same as subscriber/visitor)
		if ( ! get_role( self::USER_ROLE ) ) {
			add_role(
				self::USER_ROLE,
				__( 'Forge User', 'forge-pm' ),
				array(
					'read' => true,
				)
			);
		}
	}

	public static function remove_roles() {
		remove_role( self::MANAGER_ROLE );
		remove_role( self::USER_ROLE );
	}

	/**
	 * Return a role string for the current user:
	 *   'admin'   — WP administrator (manage_options)
	 *   'manager' — Forge Manager (edit_posts but not manage_options)
	 *   'user'    — logged-in Forge User or subscriber (read only)
	 *   'visitor' — not logged in
	 */
	public static function get_user_role(): string {
		if ( ! is_user_logged_in() ) {
			return 'visitor';
		}
		if ( current_user_can( 'manage_options' ) ) {
			return 'admin';
		}
		if ( current_user_can( 'edit_posts' ) ) {
			return 'manager';
		}
		return 'user';
	}
}
