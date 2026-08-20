<?php
/**
 * The user routes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Tenancy\Memberships;
use Blueworx\Forge\Tenancy\Users;
use Blueworx\Forge\Tenancy\Validate;
use WP_REST_Request;
use WP_REST_Response;

/**
 * People (#90). One person, one account, whatever number of clients they work
 * with (AUTH-6) — so this creates a person, and Memberships decides where they
 * work and as what.
 *
 * Every route here is gated to Permissions::manage(); real access roles arrive
 * with #91.
 */
final class UsersController {

	/**
	 * Name this write is remembered under.
	 */
	private const CREATE_OPERATION = 'create_user';

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		Server::register_route(
			$route_namespace,
			'/users',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'index' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'args'                => array(
					'status' => array(
						'type'    => 'string',
						'default' => 'active',
					),
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/users',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
			)
		);

		Server::register_route(
			$route_namespace,
			'/users/(?P<user_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'show' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
			)
		);

		Server::register_route(
			$route_namespace,
			'/users/(?P<user_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( self::class, 'update' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'args'                => array(
					Versioning::PARAM => array(
						'type'        => 'integer',
						'required'    => false,
						'description' => 'The record version this write was made against.',
					),
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/users/(?P<user_id>[A-Za-z0-9_\-]+)/memberships',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'memberships' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'args'                => array(
					'status' => array(
						'type'    => 'string',
						'default' => 'active',
					),
				),
			)
		);
	}

	/**
	 * Everyone, filtered by status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function index( WP_REST_Request $request ): WP_REST_Response {
		$status = (string) $request->get_param( 'status' );

		return rest_ensure_response(
			array(
				'ok'    => true,
				'users' => Users::all( 'all' === $status ? null : $status ),
			)
		);
	}

	/**
	 * One person.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function show( WP_REST_Request $request ) {
		$user = Users::get( (string) $request['user_id'] );

		if ( null === $user ) {
			return Errors::rest( 'unknown_user', __( 'There is no such person.', 'blueworx-forge' ), 404 );
		}

		return rest_ensure_response(
			array(
				'ok'   => true,
				'user' => $user,
			)
		);
	}

	/**
	 * Everything one person holds, across every client. This is the query #90
	 * exists to make answerable.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function memberships( WP_REST_Request $request ) {
		$user = Users::get( (string) $request['user_id'] );

		if ( null === $user ) {
			return Errors::rest( 'unknown_user', __( 'There is no such person.', 'blueworx-forge' ), 404 );
		}

		$status = (string) $request->get_param( 'status' );

		return rest_ensure_response(
			array(
				'ok'          => true,
				'memberships' => Memberships::for_user( $user['id'], 'all' === $status ? null : $status ),
			)
		);
	}

	/**
	 * Adds a person.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$key = (string) $request->get_header( Idempotency::HEADER );

		if ( '' !== $key ) {
			if ( ! Idempotency::is_valid_key( $key ) ) {
				return Errors::rest(
					'invalid_idempotency_key',
					__( 'That retry key cannot be used.', 'blueworx-forge' ),
					400
				);
			}

			$replay = Idempotency::replay( self::CREATE_OPERATION, $key );

			if ( null !== $replay ) {
				return rest_ensure_response( $replay );
			}
		}

		$checked = Validate::user( (array) $request->get_json_params(), false );

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_user',
				__( 'That person could not be saved.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		/*
		 * Checked before the insert so the answer is a usable one — "that is
		 * already somebody, here they are" rather than a failed write. The
		 * unique index behind the column is what makes it true when two
		 * requests arrive together; this is what makes it explicable.
		 */
		$existing = Users::by_email( (string) $checked['values']['email'] );

		if ( null !== $existing ) {
			return Errors::rest(
				'user_exists',
				__( 'Somebody already has that email address.', 'blueworx-forge' ),
				409,
				array( 'user' => $existing )
			);
		}

		$user = Users::create( $checked['values'], get_current_user_id() );

		if ( null === $user ) {
			return Errors::rest(
				'write_failed',
				__( 'That person could not be saved.', 'blueworx-forge' ),
				500
			);
		}

		$response = array(
			'ok'   => true,
			'user' => $user,
		);

		if ( '' !== $key ) {
			Idempotency::remember( self::CREATE_OPERATION, $key, $response );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Edits a person, including offboarding them.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function update( WP_REST_Request $request ) {
		$user = Users::get( (string) $request['user_id'] );

		if ( null === $user ) {
			return Errors::rest( 'unknown_user', __( 'There is no such person.', 'blueworx-forge' ), 404 );
		}

		$sent  = $request->get_param( Versioning::PARAM );
		$stale = Versioning::check( null === $sent ? null : (int) $sent, $user['record_version'], $user );

		if ( null !== $stale ) {
			return $stale;
		}

		$checked = Validate::user( (array) $request->get_json_params(), true );

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_user',
				__( 'That change could not be saved.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		// Moving somebody to an address that is already somebody else's would
		// merge two people, so it is refused here rather than left to the index.
		if ( array_key_exists( 'email', $checked['values'] ) ) {
			$holder = Users::by_email( (string) $checked['values']['email'] );

			if ( null !== $holder && $holder['id'] !== $user['id'] ) {
				return Errors::rest(
					'user_exists',
					__( 'Somebody else already has that email address.', 'blueworx-forge' ),
					409
				);
			}
		}

		// Offboarding goes through deactivate(), which also ends every
		// membership they hold (AUTH-6). A plain update would leave somebody
		// with a closed account and live access.
		$updated = 'inactive' === (string) ( $checked['values']['status'] ?? '' )
			? Users::deactivate( $user['id'], (int) $sent, $checked['values'] )
			: Users::update( $user['id'], $checked['values'], (int) $sent );

		if ( null === $updated ) {
			// Either the row moved between the check above and the write, or the
			// write itself failed. Re-read and ask Versioning again, so a real
			// failure is never reported as a silent success.
			$current = Users::get( $user['id'] );

			$mismatch = Versioning::check(
				(int) $sent,
				null === $current ? 0 : $current['record_version'],
				null === $current ? array() : $current
			);

			if ( null !== $mismatch ) {
				return $mismatch;
			}

			return Errors::rest(
				'write_failed',
				__( 'That change could not be saved.', 'blueworx-forge' ),
				500
			);
		}

		return rest_ensure_response(
			array(
				'ok'   => true,
				'user' => $updated,
			)
		);
	}
}
