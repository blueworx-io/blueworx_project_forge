<?php
/**
 * Updating Forge from its own Settings screen.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Updates;
use WP_REST_Response;

/**
 * Checks for and installs a newer Forge from the app (#603), so an
 * administrator never has to go into WordPress admin for it.
 *
 * The install is WordPress's own plugin upgrader, driven the way the Plugins
 * screen drives it, so the release, its zip and the checks on both are the
 * ones every other update goes through. Studio only: client sites update as
 * they always have.
 */
final class UpdatesController {

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		$scope = array(
			'kind'   => Boundary::SCOPE_OPEN,
			'reason' => 'The plugin\'s own version and its updates. There is no client record in it.',
		);

		Server::register_route(
			$route_namespace,
			'/updates',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'show' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => $scope,
			)
		);

		Server::register_route(
			$route_namespace,
			'/updates/check',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'check' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => $scope,
			)
		);

		Server::register_route(
			$route_namespace,
			'/updates/install',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'install' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => $scope,
			)
		);
	}

	/**
	 * What is installed, what the latest release is, and whether it is newer.
	 *
	 * @return WP_REST_Response
	 */
	public static function show(): WP_REST_Response {
		return rest_ensure_response( self::answer( Updates::status(), BWX_FORGE_VERSION ) );
	}

	/**
	 * Asks again now, rather than answering from the last few minutes, and
	 * tells WordPress too so an install can find the update.
	 *
	 * @return WP_REST_Response
	 */
	public static function check(): WP_REST_Response {
		delete_transient( Updates::CACHE_KEY );
		self::checker_asks();

		return self::show();
	}

	/**
	 * Installs the latest release over this one.
	 *
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function install() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return Errors::rest( 'cannot_update', __( 'This account cannot update plugins on this site.', 'blueworx-forge' ), 403 );
		}

		$status = self::answer( Updates::status(), BWX_FORGE_VERSION );

		if ( ! $status['update_available'] ) {
			return Errors::rest( 'up_to_date', __( 'Forge is already up to date.', 'blueworx-forge' ), 409 );
		}

		// A copy linked in from a development folder is updated there, never
		// overwritten by a release.
		if ( is_link( untrailingslashit( BWX_FORGE_PATH ) ) ) {
			return Errors::rest( 'linked_copy', __( 'This copy of Forge is linked from a development folder, so it is not updated from here.', 'blueworx-forge' ), 409 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		self::checker_asks();

		$plugin   = plugin_basename( BWX_FORGE_FILE );
		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->bulk_upgrade( array( $plugin ) );

		if ( is_wp_error( $skin->result ) ) {
			return Errors::rest( 'update_failed', $skin->result->get_error_message(), 500 );
		}

		if ( $skin->get_errors()->has_errors() ) {
			return Errors::rest( 'update_failed', implode( ' ', $skin->get_error_messages() ), 500 );
		}

		if ( ! is_array( $result ) || empty( $result[ $plugin ] ) ) {
			return Errors::rest( 'update_failed', __( 'WordPress could not write the update here. Update Forge from the Plugins page instead.', 'blueworx-forge' ), 500 );
		}

		wp_clean_plugins_cache();
		$now = get_plugin_data( BWX_FORGE_FILE, false, false );

		return rest_ensure_response( self::answer( Updates::status(), (string) ( $now['Version'] ?? BWX_FORGE_VERSION ) ) );
	}

	/**
	 * The answer the Settings screen draws.
	 *
	 * @param array{state: string, message: string, release: string} $status    From Updates::status().
	 * @param string                                                 $installed The version installed.
	 * @return array<string, mixed>
	 */
	public static function answer( array $status, string $installed ): array {
		$latest = ltrim( (string) $status['release'], 'vV' );

		return array(
			'ok'               => true,
			'installed'        => $installed,
			'latest'           => $latest,
			'state'            => (string) $status['state'],
			'message'          => (string) $status['message'],
			'update_available' => '' !== $latest && version_compare( $latest, $installed, '>' ),
		);
	}

	/**
	 * Has the update checker ask GitHub now, so WordPress knows what it found.
	 */
	private static function checker_asks(): void {
		global $bwx_forge_update_checker;

		if ( is_object( $bwx_forge_update_checker ) && method_exists( $bwx_forge_update_checker, 'checkForUpdates' ) ) {
			$bwx_forge_update_checker->checkForUpdates();
		}
	}
}
