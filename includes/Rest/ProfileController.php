<?php
/**
 * The signed-in person's own page.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use Blueworx\Forge\Slack\Morning;
use Blueworx\Forge\Slack\Notify;
use Blueworx\Forge\Slack\People;
use Blueworx\Forge\Tenancy\Users;
use WP_REST_Request;

/**
 * What a staff member reads and sets about themself (Luke, 2026-09-17: "a
 * profile page for all staff to use … their slack, their availability, their
 * leave … edit their details … logout").
 *
 * Their details stay WordPress's: the page links to the WordPress profile
 * rather than copying its form. Slack is answered here with the same rules
 * the WordPress profile section applies (Admin\ProfileSlack). Hours and time
 * off use the availability routes, which admit the person themself as well as
 * an administrator (Permissions::manage_or_self()).
 *
 * Every route is for a signed-in account that is a Forge person. An account
 * without a person behind it is told so, in the denied shape, rather than
 * shown an empty page.
 */
final class ProfileController {

	/**
	 * Registers the routes.
	 *
	 * @param string $route_namespace REST namespace.
	 */
	public static function register_routes( string $route_namespace ): void {
		$scope = array(
			'kind'   => Boundary::SCOPE_OPEN,
			'reason' => 'The caller\'s own record and nobody else\'s: the person is read from the signed-in account, never from the request.',
		);

		Server::register_route(
			$route_namespace,
			'/me',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'read' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => $scope,
			)
		);

		Server::register_route(
			$route_namespace,
			'/me/slack',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'slack' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => $scope,
			)
		);

		Server::register_route(
			$route_namespace,
			'/me/slack',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( self::class, 'disconnect' ),
				'permission_callback' => array( Permissions::class, 'signed_in' ),
				'scope'               => $scope,
			)
		);
	}

	/**
	 * Who is signed in, their account, and their Slack.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function read() {
		$me = self::me();

		if ( null === $me ) {
			return self::nobody();
		}

		return rest_ensure_response( self::answer( $me ) );
	}

	/**
	 * Connects Slack with a webhook, or changes what the person hears about.
	 *
	 * A webhook is only written when one is sent, and saving one sends a test
	 * message; a webhook Slack will not take is reported by field and the
	 * connection kept, since the address was Slack's even if the channel
	 * refused it. Preferences are saved whether or not a webhook came with
	 * them — they are all on until the person says otherwise, and saying so
	 * should not need a webhook pasted again.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function slack( WP_REST_Request $request ) {
		$me = self::me();

		if ( null === $me ) {
			return self::nobody();
		}

		$user_id = (string) $me['id'];
		$body    = (array) $request->get_json_params();
		$url     = trim( sanitize_text_field( (string) ( $body['url'] ?? '' ) ) );
		$warning = '';

		if ( '' !== $url ) {
			if ( ! People::acceptable( $url ) ) {
				return Errors::rest(
					'invalid_webhook',
					__( 'That is not a Slack incoming webhook. It starts with https://hooks.slack.com/.', 'blueworx-forge' ),
					400,
					array( 'fields' => array( 'url' => __( 'That is not a Slack incoming webhook. It starts with https://hooks.slack.com/.', 'blueworx-forge' ) ) )
				);
			}

			People::connect( $user_id, $url );

			$sent = Notify::test( $user_id, $url );

			if ( true !== $sent ) {
				/* translators: %s: what went wrong */
				$warning = sprintf( __( 'Connected, but Slack could not be reached with that webhook: %s', 'blueworx-forge' ), $sent->get_error_message() );
			}
		}

		if ( isset( $body['prefs'] ) && is_array( $body['prefs'] ) && null !== People::get( $user_id ) ) {
			$current = People::get( $user_id );

			People::set_prefs( $user_id, People::prefs_from( $body['prefs'], (array) ( $current['prefs'] ?? array() ) ) );
		}

		return rest_ensure_response( array_merge( self::answer( $me ), array( 'warning' => $warning ) ) );
	}

	/**
	 * Forgets the webhook and stops messaging the person.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function disconnect() {
		$me = self::me();

		if ( null === $me ) {
			return self::nobody();
		}

		People::disconnect( (string) $me['id'] );

		return rest_ensure_response( self::answer( $me ) );
	}

	/**
	 * The Forge person behind the signed-in account, if there is one.
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

	/**
	 * Everything the page shows.
	 *
	 * @param array<string, mixed> $me The person.
	 * @return array<string, mixed>
	 */
	private static function answer( array $me ): array {
		$account = wp_get_current_user();
		$slack   = People::get( (string) $me['id'] );

		return array(
			'ok'      => true,
			'person'  => array(
				'id'           => (string) $me['id'],
				'display_name' => (string) $me['display_name'],
			),
			'account' => array(
				'name'  => (string) $account->display_name,
				'email' => (string) $account->user_email,
				'login' => (string) $account->user_login,
			),
			'urls'    => array(
				'profile' => admin_url( 'profile.php' ),
				'logout'  => wp_logout_url( home_url( '/' ) ),
			),
			'slack'   => array(
				'connected'    => null !== $slack,
				'prefs'        => null === $slack ? People::prefs_from( array() ) : $slack['prefs'],
				'last_ok_at'   => null === $slack ? 0 : (int) $slack['last_ok_at'],
				'last_error'   => null === $slack ? '' : (string) $slack['last_error'],
				'morning_time' => Morning::time(),
				'labels'       => array(
					'assigned' => __( 'Work assigned to me, or arriving for me', 'blueworx-forge' ),
					'ready'    => __( 'Something ready for my review or delivery', 'blueworx-forge' ),
					'comment'  => __( 'Comments on tasks I hold a seat on', 'blueworx-forge' ),
					'morning'  => __( 'A morning message of what is due today', 'blueworx-forge' ),
				),
			),
		);
	}
}
