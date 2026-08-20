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
use Blueworx\Forge\Tenancy\Integrations;
use Blueworx\Forge\Tenancy\Reach;
use Blueworx\Forge\Tenancy\Validate;
use WP_REST_Request;
use WP_REST_Response;

/**
 * A site beneath a client (ARCH-3). Everything scoped to a single engagement —
 * work, hours, packages, onboarding — lives here rather than on the client
 * above it.
 *
 * Every route here stays on Permissions::manage(). That is not a leftover: a
 * client site is configuration, and ARCH-7 puts configuration in WordPress
 * admin rather than in the app. Since #92 they are scoped as well as gated, so
 * the listing offers only the sites the person reaches and a named site outside
 * their reach answers as absent.
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
			'/client-sites',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'all' ),

				/*
				 * The one read here the app itself makes: the site picker. It
				 * asks only for a signed-in person because a staff member who
				 * is not a WordPress administrator still has to choose which
				 * site they are working on, and since #92 the answer is scoped
				 * to what they reach rather than to everything.
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
			'/clients/(?P<client_id>[A-Za-z0-9_\-]+)/sites',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'index' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_CLIENT,
					'param'  => 'client_id',
					'record' => 'client',
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
			'/clients/(?P<client_id>[A-Za-z0-9_\-]+)/sites',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create' ),
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
			'/client-sites/(?P<site_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'show' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_SITE,
					'param'  => 'site_id',
					'record' => 'client_site',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/client-sites/(?P<site_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( self::class, 'update' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_SITE,
					'param'  => 'site_id',
					'record' => 'client_site',
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
	 * Every site on every client, each carrying the name of the client above it.
	 *
	 * The board opens on a site, so it has to offer a list of them before it can
	 * draw anything. The client name is joined on here because "Marketing" means
	 * nothing on its own — the same site name appears under several clients.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function all( WP_REST_Request $request ) {
		$reach = Boundary::current();

		/*
		 * #125. "Nothing here" and "not yours to see" look identical as an empty
		 * list and mean completely different things, so somebody who reaches
		 * nothing at all is refused rather than handed an empty array. A studio
		 * with no sites created yet is a different case and gets the empty
		 * array, because its administrator reaches everything — there simply is
		 * not anything.
		 */
		if ( Reach::is_nothing( $reach ) ) {
			return Errors::rest(
				'no_access',
				__( 'You do not have access to any client sites.', 'blueworx-forge' ),
				403
			);
		}

		$status = (string) $request->get_param( 'status' );
		$status = 'all' === $status ? null : $status;

		$names = array();

		foreach ( Clients::all( null ) as $client ) {
			$names[ (string) $client['id'] ] = (string) $client['display_name'];
		}

		$sites = array();

		/*
		 * #92, and the reason this route is declared SCOPE_LIST. The site picker
		 * is built from this, so what it filters here is exactly what somebody
		 * can choose between — a site out of reach is not offered rather than
		 * offered and then refused.
		 */
		foreach ( Reach::keep_sites( $reach, ClientSites::all( $status ), 'id' ) as $site ) {
			$site['client_name'] = $names[ (string) $site['client_id'] ] ?? '';
			$sites[]             = $site;
		}

		return rest_ensure_response(
			array(
				'ok'    => true,
				'sites' => $sites,
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
			return Boundary::absent( 'client' );
		}

		$status = (string) $request->get_param( 'status' );
		$sites  = ClientSites::for_client( $client_id, 'all' === $status ? null : $status );

		/*
		 * Every site's connection comes back with it, from one query rather than
		 * one per site: the studio's answer to "is this client's estate healthy"
		 * should not cost a request each. A site nobody has connected yet has no
		 * integration row, and says so with a null rather than by being absent.
		 */
		$integrations = Integrations::for_client( $client_id );

		foreach ( $sites as $index => $site ) {
			$sites[ $index ]['integration'] = $integrations[ $site['id'] ] ?? null;
		}

		return rest_ensure_response(
			array(
				'ok'    => true,
				'sites' => $sites,
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
			return Boundary::absent( 'client_site' );
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
		$client = Clients::get( $client_id );

		if ( null === $client ) {
			return Boundary::absent( 'client' );
		}

		// A closed client has no site anybody works on. This is the state
		// deactivation's cascade exists to produce, so creation must not be able
		// to undo it from underneath.
		if ( 'active' !== (string) $client['status'] ) {
			return Errors::rest(
				'inactive_client',
				__( 'That client is inactive; reactivate it before adding a site.', 'blueworx-forge' ),
				409
			);
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

		if ( null === $site ) {
			return Errors::rest(
				'write_failed',
				__( 'That site could not be saved.', 'blueworx-forge' ),
				500
			);
		}

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
			return Boundary::absent( 'client_site' );
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

		// Unlike a client's deactivation, a site's has no cascade to trigger —
		// ClientSites::deactivate() is byte-for-byte what update() already does
		// with a validated 'status' => 'inactive', so there is one path here,
		// not two ways of doing the one thing.
		$updated = ClientSites::update( $site['id'], $checked['values'], (int) $sent );

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
