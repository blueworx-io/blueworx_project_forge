<?php
/**
 * The client site routes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Tenancy\Clients;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Validate;
use WP_REST_Request;
use WP_REST_Response;

/**
 * A site beneath a client (ARCH-3). Everything scoped to a single engagement —
 * work, hours, packages, onboarding — lives here rather than on the client
 * above it. Every route here is gated to Permissions::manage() — real access
 * roles arrive with issue #91, and every callback below is written so that
 * swap is one line each.
 */
final class ClientSitesController {

	/**
	 * Name this write is remembered under, so an idempotency key used here cannot
	 * answer a replay of some other write.
	 */
	private const CREATE_OPERATION = 'create_client_site';

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		Server::register_route(
			$route_namespace,
			'/clients/(?P<client_id>[A-Za-z0-9_\-]+)/sites',
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
			'/clients/(?P<client_id>[A-Za-z0-9_\-]+)/sites',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
			)
		);

		Server::register_route(
			$route_namespace,
			'/client-sites/(?P<site_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'show' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
			)
		);

		Server::register_route(
			$route_namespace,
			'/client-sites/(?P<site_id>[A-Za-z0-9_\-]+)',
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
	 * Every site under a client, filtered by status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function index( WP_REST_Request $request ) {
		$client_id = (string) $request['client_id'];

		if ( null === Clients::get( $client_id ) ) {
			return Errors::rest( 'unknown_client', __( 'There is no such client.', 'blueworx-forge' ), 404 );
		}

		$status = (string) $request->get_param( 'status' );

		return rest_ensure_response(
			array(
				'ok'    => true,
				'sites' => ClientSites::for_client( $client_id, 'all' === $status ? null : $status ),
			)
		);
	}

	/**
	 * One client site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function show( WP_REST_Request $request ) {
		$site = ClientSites::get( (string) $request['site_id'] );

		if ( null === $site ) {
			return Errors::rest( 'unknown_client_site', __( 'There is no such client site.', 'blueworx-forge' ), 404 );
		}

		return rest_ensure_response(
			array(
				'ok'   => true,
				'site' => $site,
			)
		);
	}

	/**
	 * Creates a site under a client.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$client_id = (string) $request['client_id'];

		// A site under no client is not a site: this is checked before anything
		// else, including the idempotency replay and validation.
		if ( null === Clients::get( $client_id ) ) {
			return Errors::rest( 'unknown_client', __( 'There is no such client.', 'blueworx-forge' ), 404 );
		}

		$key = (string) $request->get_header( Idempotency::HEADER );

		// Scoped by client id: two different clients reusing one key must never
		// answer each other's replays, or a retry against client B could hand
		// back client A's site.
		$operation = self::CREATE_OPERATION . ':' . $client_id;

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

			$replay = Idempotency::replay( $operation, $key );

			if ( null !== $replay ) {
				return rest_ensure_response( $replay );
			}
		}

		$checked = Validate::site( (array) $request->get_json_params(), false );

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_client_site',
				__( 'That site could not be saved.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		$site = ClientSites::create( $client_id, $checked['values'], get_current_user_id() );

		$response = array(
			'ok'   => true,
			'site' => $site,
		);

		if ( '' !== $key ) {
			Idempotency::remember( $operation, $key, $response );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Edits a client site, including deactivating it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function update( WP_REST_Request $request ) {
		$site = ClientSites::get( (string) $request['site_id'] );

		if ( null === $site ) {
			return Errors::rest( 'unknown_client_site', __( 'There is no such client site.', 'blueworx-forge' ), 404 );
		}

		$sent  = $request->get_param( Versioning::PARAM );
		$stale = Versioning::check( null === $sent ? null : (int) $sent, $site['record_version'], $site );

		if ( null !== $stale ) {
			return $stale;
		}

		$checked = Validate::site( (array) $request->get_json_params(), true );

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_client_site',
				__( 'That change could not be saved.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		$updated = 'inactive' === ( $checked['values']['status'] ?? '' )
			? ClientSites::deactivate( $site['id'], (int) $sent )
			: ClientSites::update( $site['id'], $checked['values'], (int) $sent );

		if ( null === $updated ) {
			// The row moved between the check above and the write, or the write
			// itself failed. Re-read and ask Versioning again: a stale version
			// gets the usual 409, but a version that still matches means the
			// database write failed for its own reason and must not be reported
			// as a silent no-op.
			$current = ClientSites::get( $site['id'] );

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
				'site' => $updated,
			)
		);
	}
}
