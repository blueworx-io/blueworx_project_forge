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
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Reach;
use Blueworx\Forge\Tenancy\Studio;
use WP_REST_Request;

/**
 * Recurring tasks belong to the studio's own site: they are the studio's
 * housekeeping, and the renewal reminders SureCart feeds in are the studio's
 * too. So every route here is about that one site, and a person who does not
 * reach it is told so in the same words the standup uses rather than shown a
 * screen with nothing on it.
 *
 * Reading is open to anyone who reaches the studio's site. Writing is the
 * administrator's, like every other piece of configuration.
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
					'reason' => 'Recurring tasks are the studio\'s own, on its own site; the callback refuses anyone whose reach does not include it.',
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
					'reason' => 'Configuration, administrators only; it names the studio\'s own site and no other.',
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

		foreach ( array( 'PATCH' => 'update', 'DELETE' => 'end' ) as $method => $callback ) {
			Server::register_route(
				$route_namespace,
				'/recurring/(?P<recurring_id>[A-Za-z0-9_\-]+)',
				array(
					'methods'             => $method,
					'callback'            => array( self::class, $callback ),
					'permission_callback' => array( Permissions::class, 'manage' ),
					'scope'               => array(
						'kind'   => Boundary::SCOPE_OPEN,
						'reason' => 'Configuration, administrators only; a source always sits on the studio\'s own site.',
					),
				)
			);
		}
	}

	/**
	 * Every recurring task on the studio's site, with what each last made.
	 *
	 * @return \WP_REST_Response
	 */
	public static function index() {
		$site = self::studio_site();

		if ( null === $site || ! Reach::reaches_site( Boundary::current(), (string) $site['client_id'], (string) $site['id'] ) ) {
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

		$sources = Sources::for_site( (string) $site['id'] );
		$latest  = Occurrences::latest_for( array_column( $sources, 'id' ) );

		foreach ( $sources as $index => $source ) {
			$sources[ $index ]['last'] = $latest[ (string) $source['id'] ] ?? null;
		}

		return rest_ensure_response(
			array(
				'ok'      => true,
				'denied'  => false,
				'site'    => array(
					'id'   => (string) $site['id'],
					'name' => (string) $site['name'],
				),
				'sources' => $sources,
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
		$site = self::studio_site();

		if ( null === $site ) {
			return Boundary::absent( 'client_site' );
		}

		$checked = Validate::source( (array) $request->get_json_params(), false );

		if ( array() !== $checked['errors'] ) {
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

		if ( null === $source || Sources::ENDED === (string) $source['status'] ) {
			return Boundary::absent( 'recurring' );
		}

		$input   = (array) $request->get_json_params();
		$checked = Validate::source( $input, true );

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

		if ( null === $source ) {
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
	 * The studio's own site, if it has been made.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function studio_site(): ?array {
		$id = Studio::site_id();

		return '' === $id ? null : ClientSites::get( $id );
	}
}
