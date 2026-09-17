<?php
/**
 * The client routes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Tenancy\Clients;
use Blueworx\Forge\Tenancy\Contacts;
use Blueworx\Forge\Tenancy\Reach;
use Blueworx\Forge\Tenancy\Studio;
use Blueworx\Forge\Tenancy\Users;
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
	 * Name a contact assignment is remembered under, scoped per client so a
	 * retry against one client can never answer with another's contact.
	 */
	private const CONTACT_OPERATION = 'assign_contact';

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

				/*
				 * Reading which clients you work with is not administration.
				 * The set is scoped to what the person reaches (#92), so
				 * somebody with no membership at all sees an empty list rather
				 * than a refusal — the difference matters, because a refusal
				 * would say there is something to be refused.
				 */
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => array(
					'kind' => Boundary::SCOPE_LIST,
				),
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
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Creating a client is the act that makes a tenant. There is no tenant yet to scope it to.',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/clients/(?P<client_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'show' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_CLIENT,
					'param'  => 'client_id',
					'record' => 'client',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/clients/(?P<client_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( self::class, 'update' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_CLIENT,
					'param'  => 'client_id',
					'record' => 'client',
				),
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
			'/clients/(?P<client_id>[A-Za-z0-9_\-]+)/contact',
			array(
				'methods'             => 'PUT',
				'callback'            => array( self::class, 'assign_contact' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_CLIENT,
					'param'  => 'client_id',
					'record' => 'client',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/studio',
			array(
				'methods'             => 'PUT',
				'callback'            => array( self::class, 'rename_studio' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'The studio\'s own client is ours, not a tenant\'s: there is exactly one, and Tenancy\Studio names it. Administrator-only configuration.',
				),
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

		/*
		 * #92, and the reason this route is declared SCOPE_LIST: a set is
		 * filtered rather than refused. Somebody asking for the clients they
		 * work with should get them, not a refusal because the studio has
		 * others — and a client that never appears is a client whose existence
		 * was never disclosed (D-1).
		 */
		$clients = Reach::keep_clients( Boundary::current(), Clients::all( 'all' === $status ? null : $status ) );

		/*
		 * Two facts the clients screen draws per client, joined here so the
		 * screen is one read: which client is the studio's own, and who our
		 * contact is (#95). The contacts and the people are read once for the
		 * whole list rather than per client, for the reason the admin page
		 * gives — asked per client it is a query a row, twice.
		 */
		$studio   = Studio::client_id();
		$contacts = Contacts::current_by_client();
		$people   = array();

		foreach ( Users::all( null ) as $person ) {
			$people[ (string) $person['id'] ] = $person;
		}

		foreach ( $clients as $index => $client ) {
			$assignment = $contacts[ (string) $client['id'] ] ?? null;
			$person     = null === $assignment || '' === (string) $assignment['user_id']
				? null
				: ( $people[ (string) $assignment['user_id'] ] ?? null );

			$clients[ $index ]['is_studio'] = '' !== $studio && $studio === (string) $client['id'];
			$clients[ $index ]['contact']   = Contacts::resolve( $assignment, $person );
		}

		return rest_ensure_response(
			array(
				'ok'      => true,
				'clients' => $clients,
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
			return Boundary::absent( 'client' );
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
			return Boundary::absent( 'client' );
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

	/**
	 * Names our point of contact for a client (#95).
	 *
	 * Appends rather than overwrites, as the admin page does: the previous
	 * contacts stay, so the answer to "who was looking after this in March"
	 * survives the person moving on. Naming nobody (`user_id: ''`) is one of
	 * the answers, and a real one — it is the difference between a client that
	 * has never had a contact and one whose contact left.
	 *
	 * Somebody who has left cannot be made the contact. Recording it would
	 * create the exact state the screen then flags as needing reassignment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function assign_contact( WP_REST_Request $request ) {
		$client = Clients::get( (string) $request['client_id'] );

		if ( null === $client ) {
			return Boundary::absent( 'client' );
		}

		$key       = (string) $request->get_header( Idempotency::HEADER );
		$operation = self::CONTACT_OPERATION . ':' . $client['id'];

		// Replay first, and scoped per client: every assignment is a new row,
		// so a retry that ran twice would name the same person twice over.
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

		$body    = (array) $request->get_json_params();
		$user_id = trim( (string) ( $body['user_id'] ?? '' ) );
		$person  = null;

		if ( '' !== $user_id ) {
			$person = Users::get( $user_id );

			if ( null === $person ) {
				return Errors::rest(
					'invalid_contact',
					__( 'There is no such person to be the contact.', 'blueworx-forge' ),
					400
				);
			}

			if ( 'active' !== (string) $person['status'] ) {
				return Errors::rest(
					'invalid_contact',
					__( 'Somebody who has left cannot be the contact.', 'blueworx-forge' ),
					400
				);
			}
		}

		$assignment = Contacts::assign( $client['id'], $user_id, get_current_user_id() );

		if ( null === $assignment ) {
			return Errors::rest(
				'write_failed',
				__( 'That contact could not be saved.', 'blueworx-forge' ),
				500
			);
		}

		$response = array(
			'ok'         => true,
			'client'     => $client,
			'contact'    => Contacts::resolve( $assignment, $person ),
			'assignment' => $assignment,
		);

		if ( '' !== $key ) {
			Idempotency::remember( $operation, $key, $response );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Renames the studio's own client.
	 *
	 * The ordinary client update, narrowed to the one field the studio panel
	 * shows. Going through Clients::update rather than around it keeps the
	 * version check: a rename racing an edit of the same client loses honestly.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function rename_studio( WP_REST_Request $request ) {
		$client_id = Studio::client_id();
		$client    = '' === $client_id ? null : Clients::get( $client_id );

		if ( null === $client ) {
			return Boundary::absent( 'client' );
		}

		$sent  = $request->get_param( Versioning::PARAM );
		$stale = Versioning::check( null === $sent ? null : (int) $sent, $client['record_version'], $client );

		if ( null !== $stale ) {
			return $stale;
		}

		$body    = (array) $request->get_json_params();
		$checked = Validate::client( array( 'display_name' => $body['display_name'] ?? '' ), true );

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_client',
				__( 'That name could not be saved.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		$updated = Clients::update( $client['id'], $checked['values'], (int) $sent );

		if ( null === $updated ) {
			// The row moved between the check above and the write, or the write
			// itself failed. Re-read and ask Versioning again — see update().
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
				__( 'That name could not be saved.', 'blueworx-forge' ),
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
