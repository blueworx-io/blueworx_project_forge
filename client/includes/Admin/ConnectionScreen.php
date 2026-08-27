<?php
/**
 * The client site's connection screen.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

use Blueworx\Forge\Client\Connection;
use Blueworx\Forge\Client\Updates;

/**
 * Pointing this site at the studio, in the browser.
 *
 * The studio issues a site id and a key (#195); this is where they are pasted
 * in. Between the two screens, setting a client site up needs no file editing
 * and no API calls.
 *
 * A site cannot enrol itself — ARCH-6 makes registration a manual studio
 * action, and nothing here asks the studio for anything. This screen only
 * stores what an administrator was given, and shows whether it works.
 *
 * Credentials may instead be set in wp-config.php, which is the better home on
 * a real site: a secret in a file is not in the database, so it does not travel
 * in a database export. Where that is done, the fields here say so and are left
 * alone rather than quietly overridden.
 */
final class ConnectionScreen {

	/**
	 * The submenu page slug.
	 */
	public const SLUG = 'blueworx-forge-client-connection';

	/**
	 * Adds the menu entry, under the plugin's own menu.
	 */
	public static function register(): void {
		add_submenu_page(
			Screen::SLUG,
			__( 'Connection', 'blueworx-forge' ),
			__( 'Connection', 'blueworx-forge' ),
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
		echo '<h1>' . esc_html__( 'Forge — connection', 'blueworx-forge' ) . '</h1>';

		Nav::render( self::SLUG );

		self::result_notice();
		self::status();
		self::form();
		self::updates();

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
			'connected'       => array( 'success', __( 'Saved. The connection is checked below.', 'blueworx-forge' ) ),
			'disconnected'    => array( 'success', __( 'This site no longer holds any credentials for the studio.', 'blueworx-forge' ) ),
			'incomplete'      => array( 'error', __( 'The studio address, the site id and the key are all needed.', 'blueworx-forge' ) ),

			// The update token (#200). Its own codes rather than reusing the
			// three above, so the screen never reports a saved token as a saved
			// connection — they are different credentials for different places.
			'token_saved'     => array( 'success', __( 'Saved. Whether updates can be fetched is reported below.', 'blueworx-forge' ) ),
			'token_forgotten' => array( 'success', __( 'This site no longer holds an update token.', 'blueworx-forge' ) ),
			'token_empty'     => array( 'error', __( 'No update token was entered.', 'blueworx-forge' ) ),
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
	 * Whether this site is connected, proven by asking the studio.
	 *
	 * Proven rather than assumed: holding a key and being accepted are different
	 * things, and the difference is the whole point of the screen. A revoked
	 * site still has its credentials and must not be told it is fine.
	 */
	private static function status(): void {
		if ( ! Connection::is_configured() ) {
			echo '<div class="notice notice-warning" data-bwx-connection="not_configured"><p>';
			echo esc_html__( 'This site has not been connected to the studio yet.', 'blueworx-forge' );
			echo '</p></div>';

			return;
		}

		$handshake = Connection::get( '/client/handshake' );

		if ( is_wp_error( $handshake ) ) {
			$data   = $handshake->get_error_data();
			$status = (int) ( is_array( $data ) ? ( $data['status'] ?? 0 ) : 0 );

			echo '<div class="notice notice-error" data-bwx-connection="refused"><p>';
			echo esc_html__( 'The studio did not accept this site.', 'blueworx-forge' ) . ' ';

			// 401 is the studio refusing the credentials — the common causes are
			// a mistyped key, a key that has been replaced, and a site that has
			// been cut off. Anything else is the studio not being reachable,
			// which is a different problem with a different fix.
			echo 401 === $status
				? esc_html__( 'Check the site id and key, or ask for a new key to be issued.', 'blueworx-forge' )
				: esc_html__( 'The studio could not be reached at that address.', 'blueworx-forge' );

			echo '</p></div>';

			return;
		}

		echo '<div class="notice notice-success" data-bwx-connection="ok"><p>';
		printf(
			/* translators: %s: the client name the studio holds for this site. */
			esc_html__( 'Connected to the studio as %s.', 'blueworx-forge' ),
			'<strong data-bwx-client-name="1">' . esc_html( (string) ( $handshake['name'] ?? '' ) ) . '</strong>'
		);
		echo '</p></div>';
	}

	/**
	 * The credentials, and the form that sets them.
	 */
	private static function form(): void {
		$fixed = Connection::fixed();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-connect="1">';
		wp_nonce_field( 'bwx_forge_client_connect' );
		echo '<input type="hidden" name="action" value="bwx_forge_client_connect">';
		echo '<table class="form-table"><tbody>';

		self::field(
			'bwx-studio-url',
			'studio_url',
			__( 'Studio address', 'blueworx-forge' ),
			Connection::studio_url(),
			'url',
			$fixed['studio_url']
		);

		self::field(
			'bwx-site-id',
			'site_id',
			__( 'Site id', 'blueworx-forge' ),
			Connection::site_id(),
			'text',
			$fixed['site_id']
		);

		// Never the key itself, not even to the administrator who pasted it in:
		// there is no reason to read it back, and a field that renders it puts
		// it in the page source of every visit to this screen.
		$stored_key = Connection::key();
		$key_hint   = '' === $stored_key
			? __( 'Issued by the studio, and shown there only once.', 'blueworx-forge' )
			: __( 'A key is stored. Type a new one to replace it; leave blank to keep it.', 'blueworx-forge' );

		self::field(
			'bwx-key',
			'key',
			__( 'Key', 'blueworx-forge' ),
			'',
			'password',
			$fixed['key'],
			$key_hint
		);

		echo '</tbody></table>';
		submit_button( __( 'Save', 'blueworx-forge' ) );
		echo '</form>';

		self::disconnect_button();
	}

	/**
	 * The update token, and whether it works (#200).
	 *
	 * On this screen rather than one of its own because it is the same kind of
	 * thing as everything else here: a credential this site was given, settable
	 * in the browser or fixed in wp-config.php. It points somewhere different —
	 * at the repository releases come from, not at the studio — so it says so,
	 * and it reports its own state separately.
	 */
	private static function updates(): void {
		echo '<h2>' . esc_html__( 'Updates', 'blueworx-forge' ) . '</h2>';
		echo '<p>' . esc_html__( 'This plugin updates itself from a private repository, so the site needs a read-only token to see releases at all. Without one it will never offer an update.', 'blueworx-forge' ) . '</p>';

		$status = Updates::status();
		$class  = 'ok' === $status['state'] ? 'success' : ( 'none' === $status['state'] ? 'warning' : 'error' );

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

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-update-token="1">';
		wp_nonce_field( 'bwx_forge_client_save_update_token' );
		echo '<input type="hidden" name="action" value="bwx_forge_client_save_update_token">';
		echo '<table class="form-table"><tbody>';

		// Never the token itself, for the same reason the key above is never
		// rendered: a field that prints a credential puts it in the page source
		// of every visit to this screen.
		$hint = '' === Updates::stored_token()
			? __( 'A GitHub token with read-only access to the plugin repository.', 'blueworx-forge' )
			: __( 'A token is stored. Type a new one to replace it; leave blank to keep it.', 'blueworx-forge' );

		self::field(
			'bwx-update-token',
			'update_token',
			__( 'Update token', 'blueworx-forge' ),
			'',
			'password',
			Updates::is_fixed(),
			$hint
		);

		echo '</tbody></table>';

		if ( ! Updates::is_fixed() ) {
			submit_button( __( 'Save', 'blueworx-forge' ) );
		}

		echo '</form>';

		self::forget_token_button();
	}

	/**
	 * The button that forgets the stored update token.
	 */
	private static function forget_token_button(): void {
		if ( '' === Updates::stored_token() ) {
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_client_forget_update_token' );
		echo '<input type="hidden" name="action" value="bwx_forge_client_forget_update_token">';
		echo '<button type="submit" class="button" data-bwx-action="bwx_forge_client_forget_update_token">';
		echo esc_html__( 'Remove the stored token', 'blueworx-forge' );
		echo '</button>';
		echo '</form>';
	}

	/**
	 * One row of the form.
	 *
	 * @param string $id          Field id.
	 * @param string $name        Field name.
	 * @param string $label       Field label.
	 * @param string $value       Current value.
	 * @param string $type        Input type.
	 * @param bool   $fixed       Whether wp-config.php sets this one.
	 * @param string $description Optional hint under the field.
	 */
	private static function field( string $id, string $name, string $label, string $value, string $type, bool $fixed, string $description = '' ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';

		if ( $fixed ) {
			// A secret is never printed back, even when wp-config.php is where it
			// came from — saying it is set is the whole of what this row needs
			// to say. The others are addresses and ids, which are worth showing.
			$secret = in_array( $name, array( 'key', 'update_token' ), true );

			echo '<code data-bwx-fixed="' . esc_attr( $name ) . '">' . esc_html( $secret ? __( 'set in wp-config.php', 'blueworx-forge' ) : $value ) . '</code>';
			echo '<p class="description">' . esc_html__( 'Set in wp-config.php, so it cannot be changed here.', 'blueworx-forge' ) . '</p>';
			echo '</td></tr>';

			return;
		}

		printf(
			'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="regular-text" autocomplete="off">',
			esc_attr( $type ),
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $value )
		);

		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}

		echo '</td></tr>';
	}

	/**
	 * The button that forgets the credentials.
	 */
	private static function disconnect_button(): void {
		if ( ! Connection::is_configured() ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Disconnect', 'blueworx-forge' ) . '</h2>';
		echo '<p>' . esc_html__( 'This site forgets its credentials. It does not tell the studio, which can cut this site off itself at any time.', 'blueworx-forge' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_client_disconnect' );
		echo '<input type="hidden" name="action" value="bwx_forge_client_disconnect">';
		echo '<button type="submit" class="button" data-bwx-action="bwx_forge_client_disconnect" onclick="return confirm(';
		echo esc_attr( (string) wp_json_encode( __( 'Forget this site\'s studio credentials?', 'blueworx-forge' ) ) );
		echo ')">' . esc_html__( 'Disconnect this site', 'blueworx-forge' ) . '</button>';
		echo '</form>';
	}
}
