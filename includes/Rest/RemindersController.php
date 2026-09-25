<?php
/**
 * The reminder routes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Recurring\Reminders;
use Blueworx\Forge\Recurring\Sources;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Reach;
use WP_REST_Request;

/**
 * Reminders (2026-09-25): a task on a day or over a few, for one or more
 * people, on a client's site. Anyone on the team adds one on a site they
 * reach; its author or an administrator changes or deletes it.
 */
final class RemindersController {

	/**
	 * Registers this controller's routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		Server::register_route(
			$route_namespace,
			'/reminders',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'index' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_LIST,
					'reason' => 'Lists only the reminders on sites the caller reaches.',
				),
			)
		);

		Server::register_route(
			$route_namespace,
			'/reminders',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'The callback refuses a site the caller does not reach.',
				),
			)
		);

		foreach ( array(
			'PATCH'  => 'update',
			'DELETE' => 'remove',
		) as $method => $callback ) {
			Server::register_route(
				$route_namespace,
				'/reminders/(?P<reminder_id>[A-Za-z0-9_\-]+)',
				array(
					'methods'             => $method,
					'callback'            => array( self::class, $callback ),
					'permission_callback' => array( Permissions::class, 'signed_in' ),
					'scope'               => array(
						'kind'   => Boundary::SCOPE_OPEN,
						'reason' => 'The callback refuses a reminder on a site the caller does not reach, and anyone but its author or an administrator.',
					),
				)
			);
		}
	}

	/**
	 * Every reminder on the sites the caller reaches.
	 *
	 * @return \WP_REST_Response
	 */
	public static function index() {
		$reach = Boundary::current();

		if ( Reach::is_nothing( $reach ) ) {
			return rest_ensure_response(
				array(
					'ok'        => true,
					'denied'    => true,
					'reminders' => array(),
				)
			);
		}

		$sites   = array_column( Reach::keep_sites( $reach, ClientSites::all( 'active' ), 'id' ), 'id' );
		$sources = Sources::for_sites( $sites, array( Sources::REMINDER ) );
		$copies  = Reminders::copies_for( array_column( $sources, 'id' ) );

		return rest_ensure_response(
			array(
				'ok'        => true,
				'denied'    => false,
				'reminders' => array_map( static fn( array $source ): array => self::shape( $source, $copies[ (string) $source['id'] ] ?? array() ), $sources ),
			)
		);
	}

	/**
	 * Adds a reminder and makes its copies.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$input   = (array) $request->get_json_params();
		$site    = RecurringController::site_for( (string) ( $input['client_site_id'] ?? '' ) );
		$checked = Reminders::validate( $input, false );

		if ( null === $site ) {
			$checked['errors']['client_site_id'] = 'Choose a client.';
		}

		if ( array() !== $checked['errors'] || null === $site ) {
			return self::invalid( $checked['errors'] );
		}

		$source = Sources::create( (string) $site['id'], (string) $site['client_id'], Sources::REMINDER, $checked['values'], get_current_user_id() );

		if ( null === $source ) {
			return Errors::rest( 'write_failed', __( 'That reminder could not be saved.', 'blueworx-forge' ), 500 );
		}

		Reminders::make( $source );

		return self::answer( $source );
	}

	/**
	 * Changes a reminder and the copies nobody has ticked.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update( WP_REST_Request $request ) {
		$source = self::editable( (string) $request['reminder_id'] );

		if ( ! is_array( $source ) ) {
			return $source;
		}

		$input   = (array) $request->get_json_params();
		$checked = Reminders::validate( $input, true );

		// An edit that moves one date is still checked against the other. A
		// more specific error from validate() itself is kept over this one.
		$starts = (string) ( $checked['values']['starts_on'] ?? $source['starts_on'] );
		$ends   = (string) ( $checked['values']['ends_on'] ?? $source['ends_on'] );

		if ( ! isset( $checked['errors']['ends_on'] ) && '' !== $ends && $ends < $starts ) {
			$checked['errors']['ends_on'] = Reminders::ENDS_EARLY;
		}

		if ( array() !== $checked['errors'] ) {
			return self::invalid( $checked['errors'] );
		}

		$version = (int) ( $input[ Versioning::PARAM ] ?? $source['record_version'] );
		$updated = Sources::update( (string) $source['id'], $checked['values'], $version );

		if ( null === $updated ) {
			return Errors::rest( 'stale_version', __( 'That reminder changed elsewhere first — reload and try again.', 'blueworx-forge' ), 409 );
		}

		if ( ! Reminders::sync( $updated ) ) {
			return self::mid_save();
		}

		return self::answer( $updated );
	}

	/**
	 * Deletes a reminder. Ticked copies stay.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function remove( WP_REST_Request $request ) {
		$source = self::editable( (string) $request['reminder_id'] );

		if ( ! is_array( $source ) ) {
			return $source;
		}

		if ( ! Reminders::remove( $source ) ) {
			return self::mid_save();
		}

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * The reminder, if the caller may change it; otherwise the refusal.
	 *
	 * @param string $id Reminder id.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function editable( string $id ) {
		$source = Sources::get( $id );

		if ( null === $source || Sources::REMINDER !== (string) $source['kind'] || Sources::ENDED === (string) $source['status'] ) {
			return Boundary::absent( 'reminder' );
		}

		if ( null === RecurringController::site_for( (string) $source['client_site_id'] ) ) {
			return Boundary::hidden( 'reminder' );
		}

		if ( ! self::may_edit( $source ) ) {
			return Errors::rest( 'not_yours', __( 'Only whoever added this reminder, or an administrator, can change it.', 'blueworx-forge' ), 403 );
		}

		return $source;
	}

	/**
	 * The author or an administrator.
	 *
	 * @param array<string, mixed> $source The reminder.
	 * @return bool
	 */
	private static function may_edit( array $source ): bool {
		$me = get_current_user_id();

		return Permissions::manage() || ( 0 !== $me && (int) $source['created_by'] === $me );
	}

	/**
	 * A reminder as the page reads it.
	 *
	 * @param array<string, mixed>             $source The reminder.
	 * @param array<int, array<string, mixed>> $copies Its copies.
	 * @return array<string, mixed>
	 */
	private static function shape( array $source, array $copies ): array {
		return array(
			'id'             => (string) $source['id'],
			'client_site_id' => (string) $source['client_site_id'],
			'client_id'      => (string) $source['client_id'],
			'title'          => (string) $source['title'],
			'description'    => (string) $source['description'],
			'assignees'      => (array) $source['assignees'],
			'starts_on'      => (string) $source['starts_on'],
			'ends_on'        => (string) $source['ends_on'],
			'record_version' => (int) $source['record_version'],
			'created_by'     => (int) $source['created_by'],
			'can_edit'       => self::may_edit( $source ),
			'copies'         => array_map(
				static fn( array $item ): array => array(
					'item_id' => (string) $item['id'],
					'person'  => (string) ( ( (array) $item['assignees'] )[0] ?? '' ),
					'done'    => array() !== (array) $item['ticks'],
				),
				$copies
			),
		);
	}

	/**
	 * The reminder, read back with its copies.
	 *
	 * @param array<string, mixed> $source The reminder.
	 * @return \WP_REST_Response
	 */
	private static function answer( array $source ) {
		return rest_ensure_response(
			array(
				'ok'       => true,
				'reminder' => self::shape( $source, Reminders::copies_for( array( (string) $source['id'] ) )[ (string) $source['id'] ] ?? array() ),
			)
		);
	}

	/**
	 * A refusal naming the fields at fault.
	 *
	 * @param array<string, string> $fields Field to message.
	 * @return \WP_Error
	 */
	private static function invalid( array $fields ) {
		return Errors::rest( 'invalid_reminder', __( 'That reminder could not be saved.', 'blueworx-forge' ), 400, array( 'fields' => $fields ) );
	}

	/**
	 * The refusal for a copy that changed between being read and being
	 * written — a stale write on one of the reminder's tasks rather than on
	 * the reminder itself.
	 *
	 * @return \WP_Error
	 */
	private static function mid_save() {
		return Errors::rest(
			'stale_version',
			__( 'Somebody changed one of this reminder\'s tasks while it was saving — reload and try again.', 'blueworx-forge' ),
			409
		);
	}
}
