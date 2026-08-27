<?php
/**
 * The screen that tells this site how to fetch its own updates.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Updates;

/**
 * Setting the update token, and saying whether it works (#200).
 *
 * Before this screen the token could only be set by editing wp-config.php on
 * the server, and a site missing it behaved exactly like a site that was up to
 * date. Both halves of that are addressed here: the token can be set in the
 * browser, and the screen states whether updates can currently be fetched
 * rather than leaving it to be discovered months later.
 */
final class UpdatesScreen {

	/**
	 * The submenu page slug.
	 */
	public const SLUG = 'blueworx-forge-updates';

	/**
	 * Adds the menu entry, under the Forge menu.
	 */
	public static function register(): void {
		add_submenu_page(
			SitesScreen::SLUG,
			__( 'Updates', 'blueworx-forge' ),
			__( 'Updates', 'blueworx-forge' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * This screen's URL, optionally carrying a result to report.
	 *
	 * @param string $result A result code, or an empty string.
	 * @return string
	 */
	public static function url( string $result = '' ): string {
		$url = admin_url( 'admin.php?page=' . self::SLUG );

		return '' === $result ? $url : add_query_arg( 'bwx-result', $result, $url );
	}

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Forge — updates', 'blueworx-forge' ) . '</h1>';

		self::result_notice();
		self::status();
		self::form();

		echo '</div>';
	}

	/**
	 * The outcome of the last action, if there was one.
	 */
	private static function result_notice(): void {
		// Chosen from the fixed list below, never free text: it comes off the
		// URL, so anything it can say is something anyone can make an
		// administrator's screen say.
		$result = isset( $_GET['bwx-result'] ) ? sanitize_key( wp_unslash( $_GET['bwx-result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reporting the outcome of an action that carried its own nonce.

		$messages = array(
			'saved'     => array( 'success', __( 'Saved. Whether it works is reported below.', 'blueworx-forge' ) ),
			'forgotten' => array( 'success', __( 'This site no longer holds an update token.', 'blueworx-forge' ) ),
			'empty'     => array( 'error', __( 'No token was entered.', 'blueworx-forge' ) ),
		);

		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s" data-bwx-result="%2$s"><p>%3$s</p></div>',
			esc_attr( $messages[ $result ][0] ),
			esc_attr( $result ),
			esc_html( $messages[ $result ][1] )
		);
	}

	/**
	 * Whether updates can currently be fetched.
	 */
	private static function status(): void {
		self::render_status( Updates::status() );
	}

	/**
	 * Draws one status answer. Shared with the client plugin's own screen only
	 * by shape, not by code — the two plugins ship separately.
	 *
	 * @param array{state: string, message: string, release: string} $status The answer.
	 */
	private static function render_status( array $status ): void {
		$class = 'ok' === $status['state'] ? 'success' : ( 'none' === $status['state'] ? 'warning' : 'error' );

		echo '<div class="notice notice-' . esc_attr( $class ) . '" data-bwx-updates="' . esc_attr( $status['state'] ) . '"><p>';
		echo esc_html( $status['message'] );

		if ( '' !== $status['release'] ) {
			echo ' ';
			printf(
				/* translators: %s: the latest release tag, such as v2.31.0. */
				esc_html__( 'The latest release is %s.', 'blueworx-forge' ),
				'<strong data-bwx-latest-release="1">' . esc_html( $status['release'] ) . '</strong>'
			);
		}

		echo '</p></div>';
	}

	/**
	 * The token, and the form that sets it.
	 */
	private static function form(): void {
		echo '<p>' . esc_html__( 'Forge updates itself from a private repository, so this site needs a read-only token to see releases at all. Without one it will never offer an update.', 'blueworx-forge' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-update-token="1">';
		wp_nonce_field( 'bwx_forge_save_update_token' );
		echo '<input type="hidden" name="action" value="bwx_forge_save_update_token">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row"><label for="bwx-update-token">' . esc_html__( 'Update token', 'blueworx-forge' ) . '</label></th><td>';

		if ( Updates::is_fixed() ) {
			// Never the token itself. It is a credential, and a screen that
			// prints it puts it in the page source of every visit.
			echo '<code data-bwx-fixed="update_token">' . esc_html__( 'set in wp-config.php', 'blueworx-forge' ) . '</code>';
			echo '<p class="description">' . esc_html__( 'Set in wp-config.php, so it cannot be changed here.', 'blueworx-forge' ) . '</p>';
			echo '</td></tr></tbody></table>';
			echo '</form>';

			return;
		}

		echo '<input type="password" id="bwx-update-token" name="update_token" value="" class="regular-text" autocomplete="off">';
		echo '<p class="description">';
		echo esc_html(
			'' === Updates::stored_token()
				? __( 'A fine-grained GitHub token with read-only access to the plugin repository.', 'blueworx-forge' )
				: __( 'A token is stored. Type a new one to replace it; leave blank to keep it.', 'blueworx-forge' )
		);
		echo '</p>';
		echo '</td></tr></tbody></table>';

		submit_button( __( 'Save', 'blueworx-forge' ) );
		echo '</form>';

		self::forget_button();
	}

	/**
	 * The button that forgets the stored token.
	 */
	private static function forget_button(): void {
		if ( '' === Updates::stored_token() ) {
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_forget_update_token' );
		echo '<input type="hidden" name="action" value="bwx_forge_forget_update_token">';
		echo '<button type="submit" class="button" data-bwx-action="bwx_forge_forget_update_token">';
		echo esc_html__( 'Remove the stored token', 'blueworx-forge' );
		echo '</button>';
		echo '</form>';
	}
}
