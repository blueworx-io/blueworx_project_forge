<?php
/**
 * Connecting a person's Google Calendar.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Google;

use Blueworx\Forge\Frontend;
use Blueworx\Forge\Tenancy\Users;

/**
 * #606. The two ends of Google's consent screen.
 *
 * {@see self::begin()} makes a single-use state bound to the signed-in
 * account and answers with Google's address. Google sends the browser back
 * to `admin-post.php?action=bwx_forge_google` — a WordPress admin request,
 * because it arrives with the person's cookie and no REST nonce — and
 * {@see self::callback()} refuses anything whose state is unknown, used, or
 * was started by somebody else. The state is what a nonce would be: it
 * proves the person who arrives back is the one who set off.
 */
final class OAuth {

	/**
	 * The admin-post action.
	 */
	public const ACTION = 'bwx_forge_google';

	/**
	 * How long a state lasts, in seconds.
	 */
	private const STATE_TTL = 600;

	/**
	 * Hooks the callback up.
	 */
	public static function boot(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'callback' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( self::class, 'signed_out' ) );
	}

	/**
	 * Starts a connection for a person.
	 *
	 * @param string $user_id    Forge person id.
	 * @param int    $wp_user_id The signed-in account.
	 * @return string Google's consent address.
	 */
	public static function begin( string $user_id, int $wp_user_id ): string {
		$state = bin2hex( random_bytes( 24 ) );

		set_transient(
			self::key( $state ),
			array(
				'user_id'    => $user_id,
				'wp_user_id' => $wp_user_id,
			),
			self::STATE_TTL
		);

		return Settings::auth_url( $state );
	}

	/**
	 * Where Google sends the browser back.
	 */
	public static function callback(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The single-use state, bound to this account, is the check; see the class comment.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$started = '' === $state ? false : get_transient( self::key( $state ) );

		if ( '' !== $state ) {
			delete_transient( self::key( $state ) );
		}

		$outcome = self::finish( is_array( $started ) ? $started : null, get_current_user_id(), $code, $error );

		self::back( $outcome );
	}

	/**
	 * Arriving back signed out: sign in first, then try again.
	 */
	public static function signed_out(): void {
		wp_safe_redirect( wp_login_url( Frontend::instance()->app_page_url() . '#screen=profile' ) );
		exit;
	}

	/**
	 * Does the work of the callback, and says how it went.
	 *
	 * @param array<string, mixed>|null $started    What begin() kept, or null.
	 * @param int                       $wp_user_id Who arrived back.
	 * @param string                    $code       Google's code.
	 * @param string                    $error      Google's error, if any.
	 * @return string 'connected', or why not.
	 */
	public static function finish( ?array $started, int $wp_user_id, string $code, string $error ): string {
		if ( null === $started || $wp_user_id <= 0 || (int) ( $started['wp_user_id'] ?? 0 ) !== $wp_user_id ) {
			return 'expired';
		}

		if ( '' !== $error || '' === $code ) {
			return 'declined';
		}

		$person = Users::by_wp_user( $wp_user_id );

		$wanted = (string) ( $started['user_id'] ?? '' );

		if ( null === $person || $wanted !== (string) $person['id'] || ! Settings::configured() ) {
			return 'expired';
		}

		$tokens = Client::exchange( $code );

		if ( is_wp_error( $tokens ) ) {
			return 'failed';
		}

		$email = Client::primary_email( $tokens['access_token'] );

		if ( is_wp_error( $email ) ) {
			return 'failed';
		}

		Connections::connect( (string) $person['id'], $wp_user_id, $email, $tokens );
		Starts::record( (string) $person['id'] );
		Sync::run( (string) $person['id'], true );

		return 'connected';
	}

	/**
	 * Back to the person's own page in the app.
	 *
	 * @param string $outcome How it went.
	 */
	private static function back( string $outcome ): void {
		wp_safe_redirect( Frontend::instance()->app_page_url() . '#screen=profile&google=' . rawurlencode( $outcome ) );
		exit;
	}

	/**
	 * Where a state is kept.
	 *
	 * @param string $state The state.
	 * @return string
	 */
	private static function key( string $state ): string {
		return 'bwx_forge_google_state_' . substr( hash( 'sha256', $state ), 0, 32 );
	}
}
