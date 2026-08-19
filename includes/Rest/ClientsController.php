<?php
/**
 * The client routes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Tenancy\Clients;
use Blueworx\Forge\Tenancy\Validate;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Clients: identity, people and memberships (ARCH-3). Every route here is
 * gated to Permissions::manage() — real access roles arrive with issue #91,
 * and every callback below is written so that swap is one line each.
 */
final class ClientsController {

	/**
	 * Name this write is remembered under, so an idempotency key used here cannot
	 * answer a replay of some other write.
	 */
	private const CREATE_OPERATION = 'create_client';

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		Server::register_route(
			$route_namespace,
			'/clients',
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
			'/clients',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
			)
		);

		Server::register_route(
			$route_namespace,
			'/clients/(?P<client_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'show' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
			)
		);

		Server::register_route(
			$route_namespace,
			'/clients/(?P<client_id>[A-Za-z0-9_\-]+)',
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
	}

	/**
	 * Every client, filtered by status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function index( WP_REST_Request $request ): WP_REST_Response {
		$status = (string) $request->get_param( 'status' );

		return rest_ensure_response(
			array(
				'ok'      => true,
				'clients' => Clients::all( 'all' === $status ? null : $status ),
			)
		);
	}

	/**
	 * One client.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function show( WP_REST_Request $request ) {
		$client = Clients::get( (string) $request['client_id'] );

		if ( null === $client ) {
			return Errors::rest( 'unknown_client', __( 'There is no such client.', 'blueworx-forge' ), 404 );
		}

		return rest_ensure_response(
			array(
				'ok'     => true,
				'client' => $client,
			)
		);
	}

	/**
	 * Creates a client.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$key = (string) $request->get_header( Idempotency::HEADER );

		// Replay first. A retry costs nothing and, crucially, cannot be refused
		// for being stale against a version its own first attempt moved.
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

		$checked = Validate::client( (array) $request->get_json_params(), false );

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_client',
				__( 'That client could not be saved.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		$client = Clients::create( $checked['values'], get_current_user_id() );

		if ( null === $client ) {
			return Errors::rest(
				'write_failed',
				__( 'That client could not be saved.', 'blueworx-forge' ),
				500
			);
		}

		$response = array(
			'ok'     => true,
			'client' => $client,
		);

		if ( '' !== $key ) {
			Idempotency::remember( self::CREATE_OPERATION, $key, $response );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Edits a client, including deactivating it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function update( WP_REST_Request $request ) {
		$client = Clients::get( (string) $request['client_id'] );

		if ( null === $client ) {
			return Errors::rest( 'unknown_client', __( 'There is no such client.', 'blueworx-forge' ), 404 );
		}

		$sent  = $request->get_param( Versioning::PARAM );
		$stale = Versioning::check( null === $sent ? null : (int) $sent, $client['record_version'], $client );

		if ( null !== $stale ) {
			return $stale;
		}

		$checked = Validate::client( (array) $request->get_json_params(), true );

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_client',
				__( 'That change could not be saved.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		$updated = 'inactive' === ( $checked['values']['status'] ?? '' )
			? Clients::deactivate( $client['id'], (int) $sent, $checked['values'] )
			: Clients::update( $client['id'], $checked['values'], (int) $sent );

		if ( null === $updated ) {
			// The row moved between the check above and the write, or the write
			// itself failed. Re-read and ask Versioning again: a stale version
			// gets the usual 409, but a version that still matches means the
			// database write failed for its own reason and must not be reported
			// as a silent no-op.
			$current = Clients::get( $client['id'] );

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
				'ok'     => true,
				'client' => $updated,
			)
		);
	}
}
