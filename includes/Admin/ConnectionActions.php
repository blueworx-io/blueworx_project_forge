<?php
/**
 * What the connections screen's forms do.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Commerce\SureCart\Client;
use Blueworx\Forge\Commerce\SureCart\Connections;
use Blueworx\Forge\Commerce\SureCart\Sync;
use Blueworx\Forge\Slack\Morning;
use Blueworx\Forge\Slack\People;

/**
 * Connect, edit, test, refresh and remove a store. The same three guards as
 * every other admin action: the capability, the nonce, then the work.
 */
final class ConnectionActions {

	/**
	 * Hooks the handlers up.
	 */
	public static function boot(): void {
		add_action( 'admin_post_bwx_forge_add_connection', array( self::class, 'add' ) );
		add_action( 'admin_post_bwx_forge_edit_connection', array( self::class, 'edit' ) );
		add_action( 'admin_post_bwx_forge_test_connection', array( self::class, 'test' ) );
		add_action( 'admin_post_bwx_forge_refresh_connection', array( self::class, 'refresh' ) );
		add_action( 'admin_post_bwx_forge_remove_connection', array( self::class, 'remove' ) );
		add_action( 'admin_post_bwx_forge_slack_time', array( self::class, 'slack_time' ) );
		add_action( 'admin_post_bwx_forge_slack_morning_now', array( self::class, 'slack_morning_now' ) );
		add_action( 'admin_post_bwx_forge_slack_disconnect', array( self::class, 'slack_disconnect' ) );
	}

	/**
	 * Sets the morning message's time and reschedules it.
	 */
	public static function slack_time(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_slack_time' );

		$time = self::field( 'time' );

		if ( 1 !== preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			self::back( 'invalid' );
		}

		Morning::schedule( $time );
		self::back( 'timed' );
	}

	/**
	 * Sends the morning message now.
	 */
	public static function slack_morning_now(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_slack_morning_now' );

		self::back( 'morning', Morning::run() );
	}

	/**
	 * Cuts a person off from Slack.
	 */
	public static function slack_disconnect(): void {
		$user_id = isset( $_GET['user_id'] ) ? sanitize_text_field( wp_unslash( $_GET['user_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked on the next line.

		self::require_admin();
		check_admin_referer( 'bwx_forge_slack_disconnect_' . $user_id );

		People::disconnect( $user_id );
		self::back( 'cut' );
	}

	/**
	 * Connects a store.
	 */
	public static function add(): void {
		self::require_admin();
		check_admin_referer( 'bwx_forge_add_connection' );

		$name  = self::field( 'name' );
		$token = isset( $_POST['token'] ) ? trim( (string) wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A secret, sealed as given; sanitising it would change it.

		$settings = Connections::settings_from( self::seat_fields() );

		if ( '' === $name || '' === $token || array() !== $settings['errors'] ) {
			self::back( 'invalid' );
		}

		$made = Connections::create( $name, $token, $settings['values'], get_current_user_id() );

		self::back( null === $made ? 'invalid' : 'added' );
	}

	/**
	 * Changes a store's name, seats, hours and — if one was typed — token.
	 */
	public static function edit(): void {
		$id = self::field( 'connection_id' );

		self::require_admin();
		check_admin_referer( 'bwx_forge_edit_connection_' . $id );

		$store = Connections::get( $id );

		if ( null === $store ) {
			self::back( 'unknown' );
		}

		$name     = self::field( 'name' );
		$settings = Connections::settings_from( self::seat_fields() );

		if ( '' === $name || array() !== $settings['errors'] ) {
			self::back( 'invalid' );
		}

		$saved = Connections::update( $id, $name, $settings['values'], (int) self::field( 'record_version' ) );

		if ( null === $saved ) {
			self::back( 'stale' );
		}

		$token = isset( $_POST['token'] ) ? trim( (string) wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A secret, sealed as given.

		if ( '' !== $token ) {
			Connections::set_token( $id, $token );
		}

		self::back( 'saved' );
	}

	/**
	 * Asks SureCart whether the token works.
	 */
	public static function test(): void {
		$id = self::field( 'connection_id' );

		self::require_admin();
		check_admin_referer( 'bwx_forge_test_connection_' . $id );

		$store = Connections::get( $id );

		if ( null === $store ) {
			self::back( 'unknown' );
		}

		$token  = Connections::token( $id );
		$answer = null === $token ? new \WP_Error( 'bwx_forge_no_token', __( 'The token could not be read — enter it again.', 'blueworx-forge' ) ) : Client::test( $token );

		if ( is_wp_error( $answer ) ) {
			Connections::mark( $id, false, $answer->get_error_message(), 0 );
			self::back( 'failed' );
		}

		Connections::mark( $id, true, '', (int) $answer );
		self::back( 'connected', (int) $answer );
	}

	/**
	 * Reads the store's subscriptions now.
	 */
	public static function refresh(): void {
		$id = self::field( 'connection_id' );

		self::require_admin();
		check_admin_referer( 'bwx_forge_refresh_connection_' . $id );

		$store = Connections::get( $id );

		if ( null === $store ) {
			self::back( 'unknown' );
		}

		$result = Sync::refresh( $store );

		self::back( $result['ok'] ? 'refreshed' : 'failed', (int) $result['count'] );
	}

	/**
	 * Removes a store.
	 */
	public static function remove(): void {
		$id = self::field( 'connection_id' );

		self::require_admin();
		check_admin_referer( 'bwx_forge_remove_connection_' . $id );

		if ( null === Connections::get( $id ) ) {
			self::back( 'unknown' );
		}

		Connections::remove( $id );

		self::back( 'removed' );
	}

	/**
	 * The seat and hours fields as posted.
	 *
	 * @return array<string, string>
	 */
	private static function seat_fields(): array {
		$fields = array();

		foreach ( array( 'primary_user_id', 'reviewer_id', 'deliverer_id', 'hours_primary', 'hours_review', 'hours_delivery' ) as $name ) {
			$fields[ $name ] = self::field( $name );
		}

		return $fields;
	}

	/**
	 * A posted field, cleaned.
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	private static function field( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- names the nonce the caller then verifies.
		return isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
	}

	/**
	 * Refuses anyone who is not an administrator.
	 */
	private static function require_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to manage connections.', 'blueworx-forge' ),
				'',
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Returns to the screen with the outcome, and stops.
	 *
	 * @param string $result One of the result codes the screen knows.
	 * @param int    $count  A number the message may quote.
	 */
	private static function back( string $result, int $count = 0 ): void {
		$url = ConnectionsScreen::url( $result );

		if ( 0 < $count ) {
			$url = add_query_arg( 'bwx-count', $count, $url );
		}

		wp_safe_redirect( $url );
		exit;
	}
}
