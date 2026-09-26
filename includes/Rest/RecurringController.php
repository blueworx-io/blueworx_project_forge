<?php
/**
 * The recurring task routes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Recurring\Materialise;
use Blueworx\Forge\Recurring\Occurrences;
use Blueworx\Forge\Recurring\Sources;
use Blueworx\Forge\Recurring\Validate;
use Blueworx\Forge\Tenancy\Capabilities;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Reach;
use Blueworx\Forge\Tenancy\Studio;
use WP_REST_Request;

/**
 * Recurring tasks sit on a client's site the administrator chooses
 * (2026-09-25); the studio's own site is one of the choices. Reading is the
 * studio's own people's, limited to the sites they reach; writing is the
 * administrator's. Reminders share the table and are none of this route's.
 */
final class RecurringController {

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		Server::register_route(
			$route_namespace,
			'/recurring',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'index' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_LIST,
					'reason' => 'Lists only the recurring tasks on sites the caller reaches, and only to the studio\'s own people.',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/recurring',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Configuration, administrators only; the callback refuses a site the caller does not reach.',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/recurring/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'run' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'Makes today\'s tasks now rather than on the next visit; administrators only, and it reads nothing back.',
				),
			)
		);

		$editing = array(
			'PATCH'  => 'update',
			'DELETE' => 'end',
		);

		foreach ( $editing as $method => $callback ) {
			Server::register_route(
				$route_namespace,
				'/recurring/(?P<recurring_id>[A-Za-z0-9_\-]+)',
				array(
					'methods'             => $method,
					'callback'            => array( self::class, $callback ),
					'permission_callback' => array( Permissions::class, 'manage' ),
					'scope'               => array(
						'kind'   => Boundary::SCOPE_OPEN,
						'reason' => 'Configuration, administrators only; the callback refuses a site the caller does not reach.',
					),
				)
			);
		}
	}

	/**
	 * Every recurring task on a site the caller reaches, with what each last made.
	 *
	 * @return \WP_REST_Response
	 */
	public static function index() {
		$reach = Boundary::current();

		// The studio's own business: a schedule carries its people, hours and
		// internal notes, so a client's own people, who reach their site, get
		// the answer somebody who reaches nothing gets.
		if ( Reach::is_nothing( $reach ) || ! Access::allows_anywhere( Capabilities::VIEW_INTERNAL_NOTES ) ) {
			return rest_ensure_response(
				array(
					'ok'      => true,
					'denied'  => true,
					'sources' => array(),
				)
			);
		}

		// Anything due gets made before the list is read, so "last created"
		// is true as of now rather than as of the last time somebody looked.
		Materialise::maybe();

		$sites   = array_column( Reach::keep_sites( $reach, ClientSites::all( 'active' ), 'id' ), 'id' );
		$sources = Sources::for_sites( $sites, array( Sources::SCHEDULE, Sources::SUBSCRIPTION ) );
		$latest  = Occurrences::latest_for( array_column( $sources, 'id' ) );

		foreach ( $sources as $index => $source ) {
			$sources[ $index ]['last'] = $latest[ (string) $source['id'] ] ?? null;
		}

		return rest_ensure_response(
			array(
				'ok'             => true,
				'denied'         => false,
				// The form's first choice.
				'studio_site_id' => Studio::site_id(),
				'sources'        => $sources,
			)
		);
	}

	/**
	 * Adds a recurring task.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$input   = (array) $request->get_json_params();
		$site    = self::site_for( (string) ( $input['client_site_id'] ?? '' ) );
		$checked = Validate::source( $input, false );

		if ( null === $site ) {
			$checked['errors']['client_site_id'] = 'Choose a client.';
		}

		if ( array() !== $checked['errors'] || null === $site ) {
			return Errors::rest(
				'invalid_recurring',
				__( 'That recurring task could not be saved.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		$source = Sources::create( (string) $site['id'], (string) $site['client_id'], Sources::SCHEDULE, $checked['values'], get_current_user_id() );

		if ( null === $source ) {
			return Errors::rest( 'write_failed', __( 'That recurring task could not be saved.', 'blueworx-forge' ), 500 );
		}

		return rest_ensure_response(
			array(
				'ok'     => true,
				'source' => $source,
			)
		);
	}

	/**
	 * Changes a recurring task, including pausing and resuming it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update( WP_REST_Request $request ) {
		$source = Sources::get( (string) $request['recurring_id'] );

		if ( null === $source || Sources::ENDED === (string) $source['status'] || Sources::REMINDER === (string) $source['kind'] ) {
			return Boundary::absent( 'recurring' );
		}

		$input   = (array) $request->get_json_params();
		$checked = Validate::source( $input, true );

		if ( array_key_exists( 'client_site_id', $input ) ) {
			$site = self::site_for( (string) $input['client_site_id'] );

			if ( null === $site ) {
				$checked['errors']['client_site_id'] = 'Choose a client.';
			} else {
				$checked['values']['client_site_id'] = (string) $site['id'];
				$checked['values']['client_id']      = (string) $site['client_id'];
			}
		}

		if ( array() !== $checked['errors'] ) {
			return Errors::rest(
				'invalid_recurring',
				__( 'That recurring task could not be saved.', 'blueworx-forge' ),
				400,
				array( 'fields' => $checked['errors'] )
			);
		}

		$version = (int) ( $input[ Versioning::PARAM ] ?? $source['record_version'] );
		$updated = Sources::update( (string) $source['id'], $checked['values'], $version );

		if ( null === $updated ) {
			return Errors::rest(
				'stale_version',
				__( 'That recurring task changed elsewhere first — reload and try again.', 'blueworx-forge' ),
				409
			);
		}

		return rest_ensure_response(
			array(
				'ok'     => true,
				'source' => $updated,
			)
		);
	}

	/**
	 * Ends a recurring task. The tasks it made stay.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function end( WP_REST_Request $request ) {
		$source = Sources::get( (string) $request['recurring_id'] );

		// A reminder shares the table and is changed only through /reminders,
		// which keeps its copies in step; here it is not a recurring task.
		if ( null === $source || Sources::REMINDER === (string) $source['kind'] ) {
			return Boundary::absent( 'recurring' );
		}

		Sources::end( (string) $source['id'] );

		return rest_ensure_response(
			array(
				'ok'     => true,
				'source' => Sources::get( (string) $source['id'] ),
			)
		);
	}

	/**
	 * Makes everything due today, now.
	 *
	 * @return \WP_REST_Response
	 */
	public static function run() {
		return rest_ensure_response(
			array(
				'ok'      => true,
				'created' => Materialise::run( wp_date( 'Y-m-d' ) ),
			)
		);
	}

	/**
	 * A client site the caller reaches, or null (2026-09-25): where a
	 * recurring task or a reminder may be put.
	 *
	 * @param string $site_id Site id.
	 * @return array<string, mixed>|null
	 */
	public static function site_for( string $site_id ): ?array {
		if ( '' === $site_id ) {
			return null;
		}

		$site = ClientSites::get( $site_id );

		return null !== $site && Reach::reaches_site( Boundary::current(), (string) $site['client_id'], (string) $site['id'] ) ? $site : null;
	}
}
