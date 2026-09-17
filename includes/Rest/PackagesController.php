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

		Server::register_route(
			$route_namespace,
			'/packages',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'add' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => $scope,
			)
		);

		Server::register_route(
			$route_namespace,
			'/packages/order',
			array(
				'methods'             => 'PUT',
				'callback'            => array( self::class, 'reorder' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => $scope,
			)
		);

		Server::register_route(
			$route_namespace,
			'/packages/(?P<package_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( self::class, 'set_status' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => $scope,
			)
		);

		Server::register_route(
			$route_namespace,
			'/packages/(?P<package_id>[A-Za-z0-9_\-]+)/versions',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'revise' ),
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
	 * Adds a package, and its first version.
	 *
	 * Replay-safe under an idempotency key: a resend that made a second
	 * package would put two identical offers on the shelf.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function add( WP_REST_Request $request ) {
		$key = (string) $request->get_header( Idempotency::HEADER );

		if ( '' !== $key ) {
			if ( ! Idempotency::is_valid_key( $key ) ) {
				return Errors::rest( 'invalid_idempotency_key', __( 'That retry key cannot be used.', 'blueworx-forge' ), 400 );
			}

			$replay = Idempotency::replay( self::CREATE_OPERATION, $key );

			if ( null !== $replay ) {
				return rest_ensure_response( $replay );
			}
		}

		$terms  = self::submitted( $request );
		$reason = Terms::refuse( Terms::sanitise( $terms ) );

		if ( '' !== $reason ) {
			return Errors::rest( 'invalid_package', $reason, 400 );
		}

		$package = Packages::create( $terms, get_current_user_id() );

		if ( null === $package ) {
			return Errors::rest( 'write_failed', __( 'That package could not be saved.', 'blueworx-forge' ), 500 );
		}

		$response = array_merge( array( 'package' => self::shape( $package ) ), self::answer() );

		if ( '' !== $key ) {
			Idempotency::remember( self::CREATE_OPERATION, $key, $response );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Writes the next version of a package.
	 *
	 * Always an append, as {@see Packages::revise()} is: no route edits a
	 * version that exists, which is COMM-1 made visible. Not idempotency-keyed
	 * because a replay is already a no-op — the same terms twice write nothing
	 * the second time, and the answer says so in `changed`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function revise( WP_REST_Request $request ) {
		$package_id = (string) $request['package_id'];
		$before     = Packages::current_version( $package_id );

		if ( null === Packages::get( $package_id ) || null === $before ) {
			return self::unknown_package();
		}

		$terms  = self::submitted( $request );
		$reason = Terms::refuse( Terms::sanitise( $terms ) );

		if ( '' !== $reason ) {
			return Errors::rest( 'invalid_package', $reason, 400 );
		}

		$package = Packages::revise( $package_id, $terms, get_current_user_id() );

		if ( null === $package ) {
			return Errors::rest( 'write_failed', __( 'That version could not be saved.', 'blueworx-forge' ), 500 );
		}

		$shaped = self::shape( $package );

		return rest_ensure_response(
			array_merge(
				array(
					'package' => $shaped,
					'changed' => null !== $shaped['current'] && (int) $shaped['current']['version'] > (int) $before['version'],
				),
				self::answer()
			)
		);
	}

	/**
	 * Takes a package off the shelf, or puts it back. Nothing else about a
	 * package is edited in place; the rest is a version.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function set_status( WP_REST_Request $request ) {
		$package_id = (string) $request['package_id'];

		if ( null === Packages::get( $package_id ) ) {
			return self::unknown_package();
		}

		$body   = (array) $request->get_json_params();
		$status = sanitize_key( (string) ( $body['status'] ?? '' ) );

		if ( ! Terms::is_status( $status ) ) {
			return Errors::rest( 'invalid_status', __( 'A package is on the shelf or retired; nothing else.', 'blueworx-forge' ), 400 );
		}

		$package = Packages::set_status( $package_id, $status );

		if ( null === $package ) {
			return Errors::rest( 'write_failed', __( 'That package could not be changed.', 'blueworx-forge' ), 500 );
		}

		return rest_ensure_response( array_merge( array( 'package' => self::shape( $package ) ), self::answer() ) );
	}

	/**
	 * Puts the catalogue in the given order. Anything left out keeps its place
	 * after everything named ({@see Packages::reorder()}).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function reorder( WP_REST_Request $request ) {
		$body  = (array) $request->get_json_params();
		$order = $body['order'] ?? null;

		if ( ! is_array( $order ) ) {
			return Errors::rest( 'invalid_order', __( 'Say which packages go in which order.', 'blueworx-forge' ), 400 );
		}

		Packages::reorder( array_map( 'sanitize_text_field', array_map( 'strval', $order ) ) );

		return rest_ensure_response( self::answer() );
	}

	/**
	 * The refusal for a package that is not there.
	 *
	 * @return \WP_Error
	 */
	private static function unknown_package() {
		return Errors::rest( 'unknown_package', __( 'There is no such package.', 'blueworx-forge' ), 404 );
	}

	/**
	 * The terms as posted, untouched beyond what WordPress does to text.
	 *
	 * Cleaning up is {@see Terms::sanitise()}'s job and is not repeated here,
	 * for the reason the admin actions give: two places that tidy the same
	 * values are two places that can disagree about what a valid one is.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private static function submitted( WP_REST_Request $request ): array {
		$body = (array) $request->get_json_params();

		return array(
			'name'            => sanitize_text_field( (string) ( $body['name'] ?? '' ) ),
			'hours'           => (float) ( $body['hours'] ?? 0 ),
			'price'           => (float) ( $body['price'] ?? 0 ),
			'currency'        => sanitize_text_field( (string) ( $body['currency'] ?? 'GBP' ) ),
			'validity_months' => (int) ( $body['validity_months'] ?? Terms::DEFAULT_VALIDITY_MONTHS ),
			'terms'           => sanitize_textarea_field( (string) ( $body['terms'] ?? '' ) ),
		);
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
