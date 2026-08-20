<?php
/**
 * The membership routes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Tenancy\Clients;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Memberships;
use Blueworx\Forge\Tenancy\Users;
use Blueworx\Forge\Tenancy\Validate;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Who works with which client, and as what (#90).
 *
 * The check that matters here is the one on line-of-sight between a client and
 * a site: a membership naming a site that belongs to some other client would
 * grant one client's person access to another's work, and every scoped query
 * built on top of it in #92 would honour that faithfully. It is refused at the
 * only door that creates one.
 *
 * Roles are stored, not enforced. #91 implements the capability map.
 */
final class MembershipsController {

	/**
	 * Name this write is remembered under. Scoped per client at the call site,
	 * so a retry against one client cannot answer with another's membership.
	 */
	private const CREATE_OPERATION = 'create_membership';

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		Server::register_route(
			$route_namespace,
			'/clients/(?P<client_id>[A-Za-z0-9_\-]+)/memberships',
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
			'/clients/(?P<client_id>[A-Za-z0-9_\-]+)/memberships',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
			)
		);

		Server::register_route(
			$route_namespace,
			'/memberships/(?P<membership_id>[A-Za-z0-9_\-]+)',
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
	 * Everyone who holds something on one client.
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
				'ok'          => true,
				'memberships' => Memberships::for_client( $client_id, 'all' === $status ? null : $status ),
			)
		);
	}

	/**
	 * Gives somebody a role on a client, or on one site beneath it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$client_id = (string) $request['client_id'];
		$client    = Clients::get( $client_id );

		if ( null === $client ) {
			return Errors::rest( 'unknown_client', __( 'There is no such client.', 'blueworx-forge' ), 404 );
		}

		if ( 'active' !== (string) $client['status'] ) {
			return Errors::rest(
				'inactive_client',
				__( 'That client is closed; reactivate it before giving anybody access.', 'blueworx-forge' ),
				409
			);
		}

		$key = (string) $request->get_header( Idempotency::HEADER );

		// Scoped by client, for the reason #88 fixed the hard way: two clients
		// reusing one retry key must never answer each other's replays.
		$operation = self::CREATE_OPERATION . ':' . $client_id;

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

		$body = (array) $request->get_json_params();
		$user = Users::get( (string) ( $body['user_id'] ?? '' ) );

		if ( null === $user ) {
			return Errors::rest( 'unknown_user', __( 'There is no such person.', 'blueworx-forge' ), 404 );
		}

		if ( 'active' !== (string) $user['status'] ) {
			return Errors::rest(
				'inactive_user',
				__( 'That person has been offboarded; reactivate them first.', 'blueworx-forge' ),
				409
			);
		}

		$checked = Validate::membership( $body, false );

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_membership',
				__( 'That access could not be granted.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		$scope_error = self::site_error( (string) $checked['values']['client_site_id'], $client_id );

		if ( null !== $scope_error ) {
			return $scope_error;
		}

		$domain_error = Validate::domain_error(
			(string) $user['email'],
			(string) $checked['values']['role'],
			(array) $client['email_domains']
		);

		if ( null !== $domain_error ) {
			return Errors::rest(
				'invalid_membership',
				__( 'That access could not be granted.', 'blueworx-forge' ),
				400,
				array( 'fields' => array( 'user_id' => $domain_error ) )
			);
		}

		$membership = Memberships::create( $user['id'], $client_id, $checked['values'], get_current_user_id() );

		if ( null === $membership ) {
			return Errors::rest(
				'membership_exists',
				__( 'That person already holds a role there.', 'blueworx-forge' ),
				409
			);
		}

		$response = array(
			'ok'         => true,
			'membership' => $membership,
		);

		if ( '' !== $key ) {
			Idempotency::remember( $operation, $key, $response );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Changes a role, moves the scope, or ends the access.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function update( WP_REST_Request $request ) {
		$membership = Memberships::get( (string) $request['membership_id'] );

		if ( null === $membership ) {
			return Errors::rest( 'unknown_membership', __( 'There is no such membership.', 'blueworx-forge' ), 404 );
		}

		$sent  = $request->get_param( Versioning::PARAM );
		$stale = Versioning::check( null === $sent ? null : (int) $sent, $membership['record_version'], $membership );

		if ( null !== $stale ) {
			return $stale;
		}

		$checked = Validate::membership( (array) $request->get_json_params(), true );

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_membership',
				__( 'That change could not be saved.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		// The same cross-client check as on the way in. An edit that moved the
		// scope to another client's site would be exactly the grant creation
		// refuses, arriving by the other door.
		if ( array_key_exists( 'client_site_id', $checked['values'] ) ) {
			$scope_error = self::site_error(
				(string) $checked['values']['client_site_id'],
				(string) $membership['client_id']
			);

			if ( null !== $scope_error ) {
				return $scope_error;
			}
		}

		if ( array_key_exists( 'role', $checked['values'] ) ) {
			$user   = Users::get( (string) $membership['user_id'] );
			$client = Clients::get( (string) $membership['client_id'] );

			$domain_error = Validate::domain_error(
				null === $user ? '' : (string) $user['email'],
				(string) $checked['values']['role'],
				null === $client ? array() : (array) $client['email_domains']
			);

			if ( null !== $domain_error ) {
				return Errors::rest(
					'invalid_membership',
					__( 'That change could not be saved.', 'blueworx-forge' ),
					400,
					array( 'fields' => array( 'role' => $domain_error ) )
				);
			}
		}

		$updated = Memberships::update( $membership['id'], $checked['values'], (int) $sent );

		if ( null === $updated ) {
			$current = Memberships::get( $membership['id'] );

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
				'ok'         => true,
				'membership' => $updated,
			)
		);
	}

	/**
	 * Refuses a site that is not this client's.
	 *
	 * @param string $client_site_id The site named, or '' for the whole client.
	 * @param string $client_id      The client the membership is on.
	 * @return \WP_Error|null Null when there is nothing to refuse.
	 */
	private static function site_error( string $client_site_id, string $client_id ) {
		if ( '' === $client_site_id ) {
			return null;
		}

		$site = ClientSites::get( $client_site_id );

		if ( null === $site ) {
			return Errors::rest( 'unknown_client_site', __( 'There is no such client site.', 'blueworx-forge' ), 404 );
		}

		if ( (string) $site['client_id'] !== $client_id ) {
			/*
			 * Deliberately the same refusal as a site that does not exist. From
			 * outside, "that is not your site" and "there is no such site" have
			 * to look identical, or the API confirms which ids are real for
			 * clients the caller has nothing to do with.
			 */
			return Errors::rest( 'unknown_client_site', __( 'There is no such client site.', 'blueworx-forge' ), 404 );
		}

		return null;
	}
}
