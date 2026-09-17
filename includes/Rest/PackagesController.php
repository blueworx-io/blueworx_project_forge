<?php
/**
 * The support package catalogue, over REST.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Commerce\Packages;
use Blueworx\Forge\Commerce\Terms;
use WP_REST_Request;

/**
 * What the package catalogue admin screen does, as routes, so the studio app
 * can be the one place it is done (PR 2 of spec 2026-09-16). The writes are
 * the ones `Admin\PackageActions` makes; the rules are `Commerce\Packages`'
 * and `Commerce\Terms`' own. Every answer is the whole catalogue, so a
 * screen that has just written never has to read again.
 *
 * COMM-1 is visible in the shape: a package is revised by posting a new
 * version, and no route edits a version that exists.
 *
 * Administrators only, reads included: the admin page it replaces requires
 * the same, and the catalogue is configuration, not work.
 */
final class PackagesController {

	/**
	 * The idempotency operation for adding a package.
	 */
	private const CREATE_OPERATION = 'packages.create';

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		$scope = array(
			'kind'   => Boundary::SCOPE_OPEN,
			'reason' => 'The catalogue is the studio\'s, offered to every site alike. Administrator-only configuration.',
		);

		Server::register_route(
			$route_namespace,
			'/packages',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'read' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => $scope,
			)
		);
	}

	/**
	 * The whole catalogue.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function read( WP_REST_Request $request ) {
		unset( $request );

		return rest_ensure_response( self::answer() );
	}

	/**
	 * What every route answers with: every package, in the catalogue's own
	 * order, each with the version in force and every version there has been.
	 *
	 * @return array<string, mixed>
	 */
	private static function answer(): array {
		$packages = Packages::all();
		$current  = Packages::current_versions( array_column( $packages, 'id' ) );
		$shaped   = array();

		foreach ( $packages as $package ) {
			$shaped[] = self::shape( $package, $current[ (string) $package['id'] ] ?? null );
		}

		return array(
			'ok'       => true,
			'packages' => $shaped,
		);
	}

	/**
	 * One package as the screen shows it.
	 *
	 * @param array<string, mixed>      $package The catalogue row.
	 * @param array<string, mixed>|null $current Its current version, when the caller already has it.
	 * @return array<string, mixed>
	 */
	private static function shape( array $package, ?array $current = null ): array {
		$id = (string) $package['id'];

		return array_merge(
			$package,
			array(
				'current'  => $current ?? Packages::current_version( $id ),
				'versions' => Packages::versions_for( $id ),
			)
		);
	}
}
