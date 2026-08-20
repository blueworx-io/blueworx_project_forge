<?php
/**
 * The client site integration routes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Sites\Registry;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Integrations;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Issue #89. The connection between a Client Site and the WordPress that
 * belongs to it: its key's state, when it was last heard from, and whether it
 * can send the client an email.
 *
 * The key is issued through `Sites\Registry`, which is still the only place a
 * key is stored and the only thing `Rest\Signature` checks against. What this
 * adds is the record of *which client site* that registry entry belongs to, so
 * a key is no longer a thing floating loose in an option with a name on it.
 *
 * Every route is gated to Permissions::manage(), matching "register or revoke a
 * client site key" in docs/architecture/permission-matrix.md. Real access roles
 * arrive with #91.
 */
final class IntegrationsController {

	/**
	 * Name the key issue is remembered under, scoped per client site so a retry
	 * against one site can never answer with another's key.
	 */
	private const ISSUE_OPERATION = 'issue_site_key';

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		Server::register_route(
			$route_namespace,
			'/client-sites/(?P<site_id>[A-Za-z0-9_\-]+)/integration',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'show' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
			)
		);

		Server::register_route(
			$route_namespace,
			'/client-sites/(?P<site_id>[A-Za-z0-9_\-]+)/integration/key',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'issue_key' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
			)
		);

		Server::register_route(
			$route_namespace,
			'/client-sites/(?P<site_id>[A-Za-z0-9_\-]+)/integration/key',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( self::class, 'revoke_key' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
			)
		);
	}

	/**
	 * One site's integration record, with its derived health.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function show( WP_REST_Request $request ) {
		$site = ClientSites::get( (string) $request['site_id'] );

		if ( null === $site ) {
			return Errors::rest( 'unknown_client_site', __( 'There is no such client site.', 'blueworx-forge' ), 404 );
		}

		$integration = Integrations::ensure( $site['id'], $site['client_id'], get_current_user_id() );

		if ( null === $integration ) {
			return Errors::rest(
				'write_failed',
				__( 'That site\'s connection record could not be read.', 'blueworx-forge' ),
				500
			);
		}

		return rest_ensure_response(
			array(
				'ok'          => true,
				'integration' => $integration,
			)
		);
	}

	/**
	 * Issues this site's first key, or rotates the one it has.
	 *
	 * One route for both because they are one decision from the studio's side —
	 * "this site needs a key I can give somebody" — and because a separate
	 * rotate route would have to answer what it means when the site has no key
	 * yet, which is just issuing.
	 *
	 * The key is in this response and in no other. Nothing reads it back.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function issue_key( WP_REST_Request $request ) {
		$site = ClientSites::get( (string) $request['site_id'] );

		if ( null === $site ) {
			return Errors::rest( 'unknown_client_site', __( 'There is no such client site.', 'blueworx-forge' ), 404 );
		}

		// An inactive site is one nobody works on. Handing out a working key for
		// it would undo the deactivation from a direction deactivation cannot see.
		if ( 'active' !== (string) $site['status'] ) {
			return Errors::rest(
				'inactive_client_site',
				__( 'That site is inactive; reactivate it before issuing a key.', 'blueworx-forge' ),
				409
			);
		}

		$key       = (string) $request->get_header( Idempotency::HEADER );
		$operation = self::ISSUE_OPERATION . ':' . $site['id'];

		// Replay first, and scoped per site: without the scope, a retry against
		// one site could be answered with the key issued for another.
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

		$integration = Integrations::ensure( $site['id'], $site['client_id'], get_current_user_id() );

		if ( null === $integration ) {
			return Errors::rest(
				'write_failed',
				__( 'That site\'s connection record could not be written.', 'blueworx-forge' ),
				500
			);
		}

		$rotating = Integrations::KEY_ACTIVE === $integration['key_state'] && '' !== $integration['registry_site_id'];

		if ( $rotating ) {
			$issued = Registry::rotate( $integration['registry_site_id'] );

			if ( null === $issued ) {
				return Errors::rest(
					'rotate_failed',
					__( 'That site\'s key could not be rotated.', 'blueworx-forge' ),
					500
				);
			}

			$updated = Integrations::note_key_rotated( $site['id'] );
		} else {
			/*
			 * A revoked site is registered afresh rather than reinstated. The
			 * registry deliberately refuses to rotate a revoked key, because
			 * bringing a site back is a registration decision — so this makes
			 * that decision explicitly, with a new site id and a new key, and
			 * the old registry entry stays revoked as the record of what
			 * happened.
			 */
			$registered = Registry::register( $site['name'], $site['url'] );
			$issued     = $registered['key'];
			$updated    = Integrations::note_key_issued( $site['id'], $registered['site_id'] );
		}

		if ( null === $updated ) {
			return Errors::rest(
				'write_failed',
				__( 'The key was issued but could not be recorded.', 'blueworx-forge' ),
				500
			);
		}

		$response = array(
			'ok'          => true,
			'rotated'     => $rotating,
			'key'         => $issued,
			'integration' => $updated,
		);

		if ( '' !== $key ) {
			Idempotency::remember( $operation, $key, $response );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Cuts a site off. The record stays; the key stops working.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function revoke_key( WP_REST_Request $request ) {
		$site = ClientSites::get( (string) $request['site_id'] );

		if ( null === $site ) {
			return Errors::rest( 'unknown_client_site', __( 'There is no such client site.', 'blueworx-forge' ), 404 );
		}

		$integration = Integrations::for_site( $site['id'] );

		if ( null === $integration || '' === $integration['registry_site_id'] ) {
			return Errors::rest(
				'no_key_issued',
				__( 'That site has no key to revoke.', 'blueworx-forge' ),
				409
			);
		}

		// The registry first: it is what the signature check reads, so revoking
		// there is what actually stops the key working. If the record of it
		// fails to write afterwards the site is still cut off, which is the
		// right way round for this pair to fail.
		Registry::revoke( $integration['registry_site_id'] );

		$updated = Integrations::note_key_revoked( $site['id'] );

		if ( null === $updated ) {
			return Errors::rest(
				'write_failed',
				__( 'The key was revoked but the change could not be recorded.', 'blueworx-forge' ),
				500
			);
		}

		return rest_ensure_response(
			array(
				'ok'          => true,
				'integration' => $updated,
			)
		);
	}
}
