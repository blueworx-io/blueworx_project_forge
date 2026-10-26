<?php
/**
 * Google Calendar, over REST.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Google\Connections;
use Blueworx\Forge\Google\Hours;
use Blueworx\Forge\Google\Meetings;
use Blueworx\Forge\Google\OAuth;
use Blueworx\Forge\Google\SeriesChoice;
use Blueworx\Forge\Google\Settings;
use Blueworx\Forge\Google\Sync;
use Blueworx\Forge\Meetings\Occurrence;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Reach;
use Blueworx\Forge\Tenancy\Studio;
use Blueworx\Forge\Tenancy\Users;
use WP_REST_Request;

/**
 * #606. Three kinds of route:
 *
 * - The studio's OAuth client, for an administrator: entered once, the secret
 *   never read back. An administrator also sees who is connected and can
 *   disconnect anyone — never their tokens.
 * - A person's own connection: read, connect, disconnect, sync. The person is
 *   always the signed-in account, never a request parameter.
 * - Settling one synced meeting: an administrator, or somebody it belongs to,
 *   picks its client site (one in their reach) and whether its time comes off
 *   support, and can say what became of it.
 */
final class GoogleController {

	/**
	 * Registers the routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		$admin = array(
			'kind'   => Boundary::SCOPE_OPEN,
			'reason' => 'The studio\'s own Google client and who has connected: administrators only, and no token is ever in the answer.',
		);
		$own   = array(
			'kind'   => Boundary::SCOPE_OPEN,
			'reason' => 'The caller\'s own Google connection and nobody else\'s: the person is read from the signed-in account, never from the request.',
		);

		$routes = array(
			array( '/google/settings', 'GET', 'settings', array( Permissions::class, 'manage' ), $admin ),
			array( '/google/settings', 'POST', 'save_settings', array( Permissions::class, 'manage' ), $admin ),
			array( '/google/connections/(?P<user_id>[A-Za-z0-9_\-]+)', 'DELETE', 'cut', array( Permissions::class, 'manage' ), $admin ),
			array( '/me/google', 'GET', 'mine', array( Permissions::class, 'signed_in' ), $own ),
			array( '/me/google', 'DELETE', 'disconnect', array( Permissions::class, 'signed_in' ), $own ),
			array( '/me/google/connect', 'POST', 'connect', array( Permissions::class, 'signed_in' ), $own ),
			array( '/me/google/sync', 'POST', 'sync', array( Permissions::class, 'signed_in' ), $own ),
			array(
				'/google-meetings/(?P<meeting_id>[A-Za-z0-9_\-]+)/settle',
				'POST',
				'settle',
				array( Permissions::class, 'signed_in' ),
				array(
					'kind'   => Boundary::SCOPE_OPEN,
					'reason' => 'A synced meeting has no site until it is settled; the callback admits an administrator or somebody on the meeting, and only to a site the caller reaches.',
				),
			),
		);

		foreach ( $routes as list( $path, $method, $callback, $permission, $scope ) ) {
			Server::register_route(
				$route_namespace,
				$path,
				array(
					'methods'             => $method,
					'callback'            => array( self::class, $callback ),
					'permission_callback' => $permission,
					'scope'               => $scope,
				)
			);
		}
	}

	/**
	 * The studio's client and who is connected.
	 *
	 * @return \WP_REST_Response
	 */
	public static function settings() {
		return rest_ensure_response( self::settings_answer() );
	}

	/**
	 * Saves the studio's client. A blank secret keeps the saved one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save_settings( WP_REST_Request $request ) {
		$body   = (array) $request->get_json_params();
		$id     = trim( sanitize_text_field( (string) ( $body['client_id'] ?? '' ) ) );
		$secret = trim( sanitize_text_field( (string) ( $body['client_secret'] ?? '' ) ) );

		if ( '' === $id ) {
			return Errors::rest(
				'invalid_google_client',
				__( 'Enter the OAuth client id from Google Cloud.', 'blueworx-forge' ),
				400,
				array( 'fields' => array( 'client_id' => __( 'Enter the OAuth client id from Google Cloud.', 'blueworx-forge' ) ) )
			);
		}

		if ( '' === $secret && null === Settings::secret() ) {
			return Errors::rest(
				'invalid_google_client',
				__( 'Enter the OAuth client secret from Google Cloud.', 'blueworx-forge' ),
				400,
				array( 'fields' => array( 'client_secret' => __( 'Enter the OAuth client secret from Google Cloud.', 'blueworx-forge' ) ) )
			);
		}

		Settings::save( $id, $secret );

		return rest_ensure_response( self::settings_answer() );
	}

	/**
	 * Disconnects somebody, as an administrator.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function cut( WP_REST_Request $request ) {
		self::let_go( (string) $request['user_id'] );

		return rest_ensure_response( self::settings_answer() );
	}

	/**
	 * The caller's own connection.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function mine() {
		$me = self::me();

		return null === $me ? self::nobody() : rest_ensure_response( self::mine_answer( (string) $me['id'] ) );
	}

	/**
	 * Starts connecting: Google's consent address, for the browser to open.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function connect() {
		$me = self::me();

		if ( null === $me ) {
			return self::nobody();
		}

		if ( ! Settings::configured() ) {
			return Errors::rest( 'google_not_set_up', __( 'Google Calendar is not set up yet. An administrator adds the studio\'s Google client in Settings.', 'blueworx-forge' ), 409 );
		}

		return rest_ensure_response(
			array(
				'ok'  => true,
				'url' => OAuth::begin( (string) $me['id'], get_current_user_id() ),
			)
		);
	}

	/**
	 * Disconnects the caller.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function disconnect() {
		$me = self::me();

		if ( null === $me ) {
			return self::nobody();
		}

		self::let_go( (string) $me['id'] );

		return rest_ensure_response( self::mine_answer( (string) $me['id'] ) );
	}

	/**
	 * Brings the caller's calendar into step, if it is time, and the hours of
	 * every settled meeting with it. The app calls this when it opens.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function sync( WP_REST_Request $request ) {
		$me = self::me();

		if ( null === $me ) {
			return self::nobody();
		}

		$body = (array) $request->get_json_params();
		$ran  = Sync::run( (string) $me['id'], ! empty( $body['force'] ) );

		Hours::reconcile( get_current_user_id() );

		return rest_ensure_response( array_merge( self::mine_answer( (string) $me['id'] ), array( 'ran' => $ran['ran'] ) ) );
	}

	/**
	 * Settles one synced meeting.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function settle( WP_REST_Request $request ) {
		$meeting = Meetings::get( (string) $request['meeting_id'] );

		if ( null === $meeting ) {
			return Errors::rest( 'unknown_google_meeting', __( 'That meeting is not in Forge any more.', 'blueworx-forge' ), 404 );
		}

		if ( ! self::may_settle( $meeting ) ) {
			return Errors::rest( 'forbidden', __( 'Only an administrator or somebody on this meeting can settle it.', 'blueworx-forge' ), 403 );
		}

		$body   = (array) $request->get_json_params();
		$asked  = sanitize_text_field( (string) ( $body['client_site_id'] ?? '' ) );
		$status = isset( $body['status'] ) ? sanitize_text_field( (string) $body['status'] ) : null;

		if ( null !== $status && ! Occurrence::exists( $status ) ) {
			return Errors::rest( 'invalid_status', __( 'A meeting is scheduled, held, cancelled or a no-show; nothing else.', 'blueworx-forge' ), 400 );
		}

		// "No client" is the studio's own site, so it stays inside the rules.
		$site_id = '' === $asked ? Studio::site_id() : $asked;
		$site    = '' === $site_id ? null : ClientSites::get( $site_id );

		if ( null === $site || ! Reach::reaches_site( Boundary::current(), (string) $site['client_id'], (string) $site['id'] ) ) {
			return Errors::rest( 'invalid_client_site', __( 'Pick a client you work on, or no client.', 'blueworx-forge' ), 400 );
		}

		// Time never comes off the studio's own hours.
		$off_support = ! empty( $body['off_support'] ) && ! Studio::is_studio_site( (string) $site['id'] );

		if ( ! Hours::may_change( $meeting, Hours::entries_for( (string) $meeting['id'] ), (string) $site['id'], $off_support ) ) {
			return Errors::rest( 'meeting_already_charged', __( 'This meeting has already used the client\'s hours, so its client and support choice cannot change.', 'blueworx-forge' ), 409 );
		}

		$settled = Meetings::settle( $meeting, (string) $site['id'], (string) $site['client_id'], $off_support, $status, get_current_user_id() );

		if ( null === $settled ) {
			return Errors::rest( 'stale', __( 'That meeting changed while you were settling it. Try again.', 'blueworx-forge' ), 409 );
		}

		Hours::settle_one( $settled, get_current_user_id() );

		// #657. The later meetings in its series take the same client.
		SeriesChoice::remember( $settled, (string) $site['id'], (string) $site['client_id'], $off_support, get_current_user_id() );

		return rest_ensure_response(
			array(
				'ok'      => true,
				'meeting' => self::shown( (array) Meetings::get( (string) $settled['id'] ) ),
			)
		);
	}

	/**
	 * Whether the caller may settle a meeting: an administrator, or one of
	 * the people whose calendars hold it.
	 *
	 * @param array<string, mixed> $meeting Hydrated.
	 * @return bool
	 */
	private static function may_settle( array $meeting ): bool {
		if ( Permissions::manage() ) {
			return true;
		}

		$me = self::me();

		return null !== $me && in_array( (string) $me['id'], array_column( (array) $meeting['people'], 'user_id' ), true );
	}

	/**
	 * Disconnects a person: their tokens go, and their unsettled meetings.
	 *
	 * @param string $user_id Forge person id.
	 */
	private static function let_go( string $user_id ): void {
		Meetings::forget_unsettled( $user_id );
		Connections::disconnect( $user_id );
	}

	/**
	 * A meeting as a settle answer shows it.
	 *
	 * @param array<string, mixed> $meeting Hydrated.
	 * @return array<string, mixed>
	 */
	private static function shown( array $meeting ): array {
		return array(
			'id'             => (string) ( $meeting['id'] ?? '' ),
			'title'          => (string) ( $meeting['title'] ?? '' ),
			'on'             => (string) ( $meeting['on'] ?? '' ),
			'client_site_id' => (string) ( $meeting['client_site_id'] ?? '' ),
			'off_support'    => ! empty( $meeting['off_support'] ),
			'status'         => (string) ( $meeting['status'] ?? '' ),
			'ledger_state'   => (string) ( $meeting['ledger_state'] ?? '' ),
		);
	}

	/**
	 * What the administrator's panel shows.
	 *
	 * @return array<string, mixed>
	 */
	private static function settings_answer(): array {
		$names  = array_column( Users::all( null ), 'display_name', 'id' );
		$people = array();

		foreach ( Connections::all() as $connection ) {
			$people[] = array(
				'user_id'      => (string) $connection['user_id'],
				'display_name' => (string) ( $names[ (string) $connection['user_id'] ] ?? '' ),
				'email'        => (string) $connection['email'],
				'synced_at'    => (int) $connection['synced_at'],
				'last_error'   => (string) $connection['last_error'],
			);
		}

		return array(
			'ok'           => true,
			'configured'   => Settings::configured(),
			'client_id'    => Settings::client_id(),
			'secret_hint'  => Settings::secret_hint(),
			'redirect_uri' => Settings::redirect_uri(),
			'people'       => $people,
		);
	}

	/**
	 * What a person's own panel shows.
	 *
	 * @param string $user_id Forge person id.
	 * @return array<string, mixed>
	 */
	private static function mine_answer( string $user_id ): array {
		$connection = Connections::get( $user_id );

		return array(
			'ok'         => true,
			'configured' => Settings::configured(),
			'connected'  => null !== $connection,
			'email'      => null === $connection ? '' : (string) $connection['email'],
			'synced_at'  => null === $connection ? 0 : (int) $connection['synced_at'],
			'last_error' => null === $connection ? '' : (string) $connection['last_error'],
		);
	}

	/**
	 * The Forge person behind the signed-in account.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function me(): ?array {
		return Users::by_wp_user( get_current_user_id() );
	}

	/**
	 * The answer for an account that is not a person in Forge.
	 *
	 * @return \WP_Error
	 */
	private static function nobody() {
		return Errors::rest( 'not_a_person', __( 'Your account is not a person in Forge yet.', 'blueworx-forge' ), 403 );
	}
}
