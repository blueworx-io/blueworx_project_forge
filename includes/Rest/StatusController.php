<?php
/**
 * The status routes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use WP_REST_Request;
use WP_REST_Response;

/**
 * The skeleton's only routes, and the reference implementation of the
 * conventions in this directory.
 *
 * `GET /status` is the public read. `POST /status/echo` is the gated write, and
 * it is deliberately the smallest write that can still demonstrate all of it:
 * it is capability-gated, it carries the record version it was made against, and
 * it accepts an idempotency key. Every later write endpoint is built the same
 * way, so this is the file to copy.
 */
final class StatusController {

	/**
	 * Option holding the echo record. It exists so the write has something with a
	 * version to be stale against — there is no data model yet.
	 */
	public const RECORD_OPTION = 'bwx_forge_status_record';

	/**
	 * Name this write is remembered under, so an idempotency key used here cannot
	 * answer a replay of some other write.
	 */
	private const OPERATION = 'status.echo';

	/**
	 * Registers this controller's routes.
	 *
	 * Every route goes through Server::register_route(), never
	 * register_rest_route() directly: that is what makes a missing permission
	 * callback impossible rather than merely discouraged.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		Server::register_route(
			$route_namespace,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'status' ),
				// Deliberately public: the app serves a read-only view to
				// logged-out visitors.
				'permission_callback' => array( Permissions::class, 'read' ),
			)
		);

		Server::register_route(
			$route_namespace,
			'/status/echo',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'echo_message' ),
				'permission_callback' => array( Permissions::class, 'manage' ),
				'args'                => array(
					'message'         => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
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
	 * Reports that the plugin is installed and answering, and hands back the
	 * current record version so a caller knows what to write against.
	 *
	 * @return WP_REST_Response
	 */
	public static function status(): WP_REST_Response {
		$record = self::record();

		return rest_ensure_response(
			array(
				'plugin'         => BWX_FORGE_SLUG,
				'version'        => BWX_FORGE_VERSION,
				'ready'          => true,
				'record_version' => $record['version'],
				'record'         => $record,
			)
		);
	}

	/**
	 * Echoes a message back, as the reference write.
	 *
	 * The order matters and is the order every later write uses: replay first, so
	 * a retry costs nothing and cannot be refused for being stale against a
	 * version its own first attempt moved; then the version check; then the work.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function echo_message( WP_REST_Request $request ) {
		$key = (string) $request->get_header( Idempotency::HEADER );

		if ( '' !== $key ) {
			if ( ! Idempotency::is_valid_key( $key ) ) {
				return Errors::rest(
					'invalid_idempotency_key',
					__( 'The idempotency key is not a usable identifier.', 'blueworx-forge' ),
					400
				);
			}

			$replayed = Idempotency::replay( self::OPERATION, $key );

			if ( null !== $replayed ) {
				return rest_ensure_response( $replayed );
			}
		}

		$record = self::record();
		$sent   = $request->has_param( Versioning::PARAM ) ? (int) $request->get_param( Versioning::PARAM ) : null;
		$stale  = Versioning::check( $sent, $record['version'], $record );

		if ( null !== $stale ) {
			return $stale;
		}

		$record = array(
			'version' => $record['version'] + 1,
			'message' => (string) $request->get_param( 'message' ),
			'writes'  => $record['writes'] + 1,
		);

		update_option( self::RECORD_OPTION, $record );

		$response = array(
			'ok'      => true,
			'message' => $record['message'],
			'record'  => $record,
		);

		if ( '' !== $key ) {
			Idempotency::remember( self::OPERATION, $key, $response );
		}

		return rest_ensure_response( $response );
	}

	/**
	 * The echo record, with its defaults.
	 *
	 * @return array{version: int, message: string, writes: int}
	 */
	private static function record(): array {
		$stored = get_option( self::RECORD_OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array(
			'version' => isset( $stored['version'] ) ? (int) $stored['version'] : 1,
			'message' => isset( $stored['message'] ) ? (string) $stored['message'] : '',
			'writes'  => isset( $stored['writes'] ) ? (int) $stored['writes'] : 0,
		);
	}
}
