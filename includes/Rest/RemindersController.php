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
use Blueworx\Forge\Tenancy\Capabilities;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\PersonReach;
use Blueworx\Forge\Tenancy\Reach;
use Blueworx\Forge\Tenancy\Users;
use WP_REST_Request;

/**
 * Reminders (2026-09-25): a task on a day or over a few, for one or more
 * people, on a client's site. Anyone on the team adds one on a site they
 * reach; its author or an administrator changes or deletes it.
 *
 * "The team" is the studio's. A reminder makes work items, so the route asks
 * for the same create-work capability the work-item route does, and a
 * client's own people, who reach their site, still add nothing to it.
 */
final class RemindersController {

	/**
	 * The refusal for somebody who does not reach the reminder's site.
	 */
	private const OFF_CLIENT = 'Everyone on a reminder must work on its client.';

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

		// #610. One reminder, for the task panel a copy opens in.
		Server::register_route(
			$route_namespace,
			'/reminders/(?P<reminder_id>[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'show' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'The callback refuses a reminder on a site the caller does not reach.',
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

		Server::register_route(
			$route_namespace,
			'/reminders/(?P<reminder_id>[A-Za-z0-9_\-]+)/convert',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'convert' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'The callback refuses a reminder on a site the caller does not reach, and anyone but its author or an administrator.',
				),
			)
		);
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
		$listed  = array();

		foreach ( $sources as $source ) {
			$mine = $copies[ (string) $source['id'] ] ?? array();

			// #612. One turned into a task before a convert ended it is gone too.
			if ( Reminders::converted( $mine ) ) {
				continue;
			}

			$listed[] = self::shape( $source, $mine );
		}

		return rest_ensure_response(
			array(
				'ok'        => true,
				'denied'    => false,
				'reminders' => $listed,
			)
		);
	}

	/**
	 * One reminder (#610), with whether the caller may change it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function show( WP_REST_Request $request ) {
		$source = Sources::get( (string) $request['reminder_id'] );

		if ( null === $source || Sources::REMINDER !== (string) $source['kind'] || Sources::ENDED === (string) $source['status'] ) {
			return Boundary::absent( 'reminder' );
		}

		if ( null === RecurringController::site_for( (string) $source['client_site_id'] ) ) {
			return Boundary::hidden( 'reminder' );
		}

		return self::answer( $source );
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

		if ( null !== $site ) {
			$refused = Access::refuse_unless( Capabilities::CREATE_WORK_ITEM, (string) $site['client_id'] );

			if ( null !== $refused ) {
				return $refused;
			}

			if ( isset( $checked['values']['assignees'] ) && ! self::all_reach( $checked['values']['assignees'], $site ) ) {
				$checked['errors']['assignees'] = self::OFF_CLIENT;
			}
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

		// An edit never moves a reminder's site, so anyone named is checked
		// against the one it is already on.
		if ( isset( $checked['values']['assignees'] ) ) {
			$site = ClientSites::get( (string) $source['client_site_id'] );

			if ( null === $site || ! self::all_reach( $checked['values']['assignees'], $site ) ) {
				$checked['errors']['assignees'] = self::OFF_CLIENT;
			}
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
	 * Turns a reminder into a task (#574): one task for everyone who had not
	 * ticked it yet, carrying its title, client site, people and time. Their
	 * copies close into it, and the reminder leaves reminders (#612).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function convert( WP_REST_Request $request ) {
		$source = self::editable( (string) $request['reminder_id'] );

		if ( ! is_array( $source ) ) {
			return $source;
		}

		$result = Reminders::convert( $source, get_current_user_id() );
		$names  = self::names( (array) ( $result['people'] ?? array() ) );

		switch ( $result['code'] ) {
			case 'nothing_open':
				return Errors::rest( 'nothing_open', __( 'Nobody has this reminder left to do.', 'blueworx-forge' ), 409 );
			case 'stuck':
				return Errors::rest(
					'copy_not_closable',
					/* translators: %s: names of people */
					sprintf( __( 'Nothing was changed: the copy for %s is blocked, or somewhere it cannot be closed from. Unblock it, or take them off the reminder, then try again.', 'blueworx-forge' ), $names ),
					409
				);
			case 'busy':
				return Errors::rest( 'stale_version', __( 'This reminder is already being turned into a task — reload and check.', 'blueworx-forge' ), 409 );
			case 'partial':
				return Errors::rest(
					'partly_converted',
					/* translators: %s: names of people */
					sprintf( __( 'The task was made, but %s\'s copy changed while it was saving, so they stay on the reminder. Reload and try again for them.', 'blueworx-forge' ), $names ),
					409,
					array( 'item' => $result['task'] ?? array() )
				);
			case '':
				break;
			default:
				return Errors::rest( 'write_failed', __( 'That task could not be made, and nothing was changed.', 'blueworx-forge' ), 500 );
		}

		return rest_ensure_response(
			array(
				'ok'       => true,
				'item'     => $result['task'],
				'reminder' => self::answer_shape( $source ),
			)
		);
	}

	/**
	 * People's names, for a message.
	 *
	 * @param array<int, string> $ids Person ids.
	 * @return string
	 */
	private static function names( array $ids ): string {
		$names = array();

		foreach ( $ids as $id ) {
			$person  = Users::get( (string) $id );
			$names[] = null === $person ? __( 'Somebody', 'blueworx-forge' ) : (string) $person['display_name'];
		}

		return implode( ', ', $names );
	}

	/**
	 * A reminder, re-read, with its copies.
	 *
	 * @param array<string, mixed> $source The reminder.
	 * @return array<string, mixed>
	 */
	private static function answer_shape( array $source ): array {
		$fresh = Sources::get( (string) $source['id'] ) ?? $source;

		return self::shape( $fresh, Reminders::copies_for( array( (string) $source['id'] ) )[ (string) $source['id'] ] ?? array() );
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

		// Changing work on a site is no more a client's than adding it.
		$refused = Access::refuse_unless( Capabilities::CREATE_WORK_ITEM, (string) $source['client_id'] );

		if ( null !== $refused ) {
			return $refused;
		}

		if ( ! self::may_edit( $source ) ) {
			return Errors::rest( 'not_yours', __( 'Only whoever added this reminder, or an administrator, can change it.', 'blueworx-forge' ), 403 );
		}

		return $source;
	}

	/**
	 * Whether everybody named reaches the site: the test the boundary puts to
	 * whoever is asking, put to each person instead. Somebody who cannot see
	 * the site would never see their copy, and a reminder nobody sees is the
	 * one thing the feature exists to prevent.
	 *
	 * @param array<int, string>   $people Person ids.
	 * @param array<string, mixed> $site   The site.
	 * @return bool
	 */
	private static function all_reach( array $people, array $site ): bool {
		// The rule itself is shared with the seats on a task (#393).
		foreach ( $people as $id ) {
			if ( ! PersonReach::person_reaches_site( (string) $id, (string) $site['client_id'], (string) $site['id'] ) ) {
				return false;
			}
		}

		return true;
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
			'category'       => '' === (string) $source['category'] ? 'general' : (string) $source['category'],
			'description'    => (string) $source['description'],
			'assignees'      => (array) $source['assignees'],
			'starts_on'      => (string) $source['starts_on'],
			'ends_on'        => (string) $source['ends_on'],
			// Each person's time, in hours (#573); 0 when none is set.
			'hours_each'     => (float) $source['hours_each'],
			'record_version' => (int) $source['record_version'],
			'created_by'     => (int) $source['created_by'],
			'can_edit'       => self::may_edit( $source ),
			'copies'         => array_map(
				static fn( array $item ): array => array(
					'item_id'      => (string) $item['id'],
					'person'       => (string) ( ( (array) $item['assignees'] )[0] ?? '' ),
					// A copy turned into a task (#574) is not done: it moved.
					'done'         => array() !== (array) $item['ticks'] && '' === Reminders::converted_to( $item ),
					'converted_to' => Reminders::converted_to( $item ),
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
