<?php
/**
 * The client site's connection screen.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

use Blueworx\Forge\Client\Access;
use Blueworx\Forge\Client\Connection;
use Blueworx\Forge\Client\Updates;
use Blueworx\Forge\Client\Workspace;

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
 *
 * This screen is drawn by hand rather than through the shared page editor
 * library: the library has no masked field kind (the key would render as
 * plain text) and its screen callback leaves nowhere for the disconnect
 * action to live. Both are gaps in the library, not something this screen
 * can work around — so this is the documented fallback, design system markup
 * over the same save handling.
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
			Access::CONNECT,
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
		if ( ! current_user_can( Access::CONNECT ) ) {
			return;
		}

		// Not a new read: it is cached the same as every other read-through
		// view, and it is only used here for the eyebrow — the same reasoning
		// AskScreen and AskedScreen rest on for theirs.
		$workspace = Workspace::view( false );

		Page::open( __( 'Connection', 'blueworx-forge' ), Nav::scope_text( $workspace ) );

		self::result_notice();

		Page::panel_open( __( 'Connection', 'blueworx-forge' ), 'status' );
		self::status();
		Page::panel_close();

		Page::panel_open( __( 'Studio credentials', 'blueworx-forge' ), 'credentials' );
		self::form();
		Page::panel_close();

		Page::panel_open( __( 'Updates', 'blueworx-forge' ), 'updates' );
		self::updates();
		Page::panel_close();

		self::destructive_actions();

		Page::close();
	}

	/**
	 * One design system Notice.
	 *
	 * Both of the ways a caller can hand this markup are escaped here rather
	 * than trusted: the extra attributes arrive as names and values and are
	 * escaped one at a time, and text carrying its own tags goes through
	 * wp_kses_post. A helper that prints whatever it is given, on the promise
	 * that every caller escaped first, only holds until somebody adds a caller.
	 *
	 * @param string                $tone       success | warning | danger | info.
	 * @param string                $text       The notice text.
	 * @param array<string, string> $attributes Extra attributes for the wrapping element, name => value.
	 * @param bool                  $html       Whether $text carries its own markup.
	 */
	private static function notice( string $tone, string $text, array $attributes = array(), bool $html = false ): void {
		Page::notice( $tone, $text, $attributes, $html );
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
			'connected'    => array( 'success', __( 'Saved. The connection is checked below.', 'blueworx-forge' ) ),
			'disconnected' => array( 'success', __( 'This site no longer holds any credentials for the studio.', 'blueworx-forge' ) ),
			'incomplete'   => array( 'danger', __( 'The studio address, the site id and the key are all needed.', 'blueworx-forge' ) ),
		);

		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}

		list( $tone, $text ) = $messages[ $result ];

		self::notice( $tone, $text, array( 'data-bwx-result' => $result ) );
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
			self::notice(
				'warning',
				__( 'This site has not been connected to the studio yet.', 'blueworx-forge' ),
				array( 'data-bwx-connection' => 'not_configured' )
			);

			return;
		}

		$handshake = Connection::get( '/client/handshake' );

		if ( is_wp_error( $handshake ) ) {
			$data   = $handshake->get_error_data();
			$status = (int) ( is_array( $data ) ? ( $data['status'] ?? 0 ) : 0 );

			// 401 is the studio refusing the credentials — the common causes are
			// a mistyped key, a key that has been replaced, and a site that has
			// been cut off. Anything else is the studio not being reachable,
			// which is a different problem with a different fix.
			$text = __( 'The studio did not accept this site.', 'blueworx-forge' ) . ' ' . ( 401 === $status
				? __( 'Check the site id and key, or ask for a new key to be issued.', 'blueworx-forge' )
				: __( 'The studio could not be reached at that address.', 'blueworx-forge' ) );

			self::notice( 'danger', $text, array( 'data-bwx-connection' => 'refused' ) );

			return;
		}

		$text = sprintf(
			/* translators: %s: the client name the studio holds for this site. */
			esc_html__( 'Connected to the studio as %s.', 'blueworx-forge' ),
			'<strong data-bwx-client-name="1">' . esc_html( (string) ( $handshake['name'] ?? '' ) ) . '</strong>'
		);

		self::notice( 'success', $text, array( 'data-bwx-connection' => 'ok' ), true );
	}

	/**
	 * The credentials, and the form that sets them.
	 */
	private static function form(): void {
		$fixed = Connection::fixed();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-connect="1">';
		wp_nonce_field( 'bwx_forge_client_connect' );
		echo '<input type="hidden" name="action" value="bwx_forge_client_connect">';

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

		echo '<div class="bwx-formactions">';
		printf(
			'<input type="submit" name="submit" class="bw-btn bw-btn--primary" value="%s">',
			esc_attr__( 'Save', 'blueworx-forge' )
		);
		echo '</div>';

		echo '</form>';
	}

	/**
	 * Whether this site can fetch its own updates (#200, #340).
	 *
	 * On this screen rather than one of its own because it is about the same
	 * thing as everything else here: where this site is joined to. Releases
	 * come from a public repository now, so there is nothing to set — the
	 * panel only reports.
	 */
	private static function updates(): void {
		printf(
			'<p class="bw-card__note">%s</p>',
			esc_html__( 'This plugin updates itself from its public releases, the same way as any other plugin. Nothing needs setting up on this site.', 'blueworx-forge' )
		);

		$status = Updates::status();
		$tone   = 'ok' === $status['state'] ? 'success' : ( 'limited' === $status['state'] ? 'warning' : 'danger' );
		$text   = esc_html( $status['message'] );

		if ( '' !== $status['release'] ) {
			$text .= ' ' . sprintf(
				/* translators: %s: the latest release tag, such as v2.31.0. */
				esc_html__( 'The latest release is %s.', 'blueworx-forge' ),
				'<strong data-bwx-latest-release="1">' . esc_html( $status['release'] ) . '</strong>'
			);
		}

		self::notice( $tone, $text, array( 'data-bwx-updates' => (string) $status['state'] ), true );

		printf(
			'<p class="bw-card__note" data-bwx-installed="%1$s">%2$s</p>',
			esc_attr( BWX_FORGE_CLIENT_VERSION ),
			sprintf(
				/* translators: %s: the installed version, such as 2.126.0. */
				esc_html__( 'This site runs Forge %s. WordPress checks for a newer release twice a day and offers it under Plugins.', 'blueworx-forge' ),
				'<strong>' . esc_html( BWX_FORGE_CLIENT_VERSION ) . '</strong>'
			)
		);
	}

	/**
	 * The panel that holds the one destructive action, apart from the ordinary
	 * save action above.
	 *
	 * Disconnecting is not a save — it drops the connection outright — so it
	 * does not share a panel with a form that saves. A destructive control
	 * sitting next to a save control is how somebody disconnects a live client
	 * site by accident.
	 */
	private static function destructive_actions(): void {
		if ( ! Connection::is_configured() ) {
			return;
		}

		Page::panel_open( __( 'Destructive actions', 'blueworx-forge' ), 'destructive' );

		printf(
			'<p class="bw-fieldnote"><i class="bw-icon" data-lucide="triangle-alert"></i>%s</p>',
			esc_html__( 'This cannot be undone from here.', 'blueworx-forge' )
		);

		echo '<div class="bw-formrow">';
		printf( '<span class="bw-formrow__label">%s</span>', esc_html__( 'Disconnect', 'blueworx-forge' ) );
		echo '<div class="bw-formrow__control">';
		printf( '<p class="bw-formrow__help">%s</p>', esc_html__( 'This site forgets its credentials. It does not tell the studio, which can cut this site off itself at any time.', 'blueworx-forge' ) );
		self::disconnect_button();
		echo '</div></div>';

		Page::panel_close();
	}

	/**
	 * One row of the form.
	 *
	 * @param string $id          Field id.
	 * @param string $name        Field name.
	 * @param string $label       Field label.
	 * @param string $value       Current value.
	 * @param string $type        Input type — passed straight through to the
	 *                            markup, never hardcoded, which is what keeps
	 *                            the key and the update token masked rather
	 *                            than becoming plain text.
	 * @param bool   $fixed       Whether wp-config.php sets this one.
	 * @param string $description Optional hint under the field.
	 */
	private static function field( string $id, string $name, string $label, string $value, string $type, bool $fixed, string $description = '' ): void {
		echo '<div class="bw-formrow">';
		printf( '<label class="bw-formrow__label" for="%1$s">%2$s</label>', esc_attr( $id ), esc_html( $label ) );
		echo '<div class="bw-formrow__control">';

		if ( $fixed ) {
			// A secret is never printed back, even when wp-config.php is where it
			// came from — saying it is set is the whole of what this row needs
			// to say. The others are addresses and ids, which are worth showing.
			$secret = in_array( $name, array( 'key' ), true );

			printf(
				'<code class="bw-input--mono" data-bwx-fixed="%1$s">%2$s</code>',
				esc_attr( $name ),
				esc_html( $secret ? __( 'set in wp-config.php', 'blueworx-forge' ) : $value )
			);
			printf( '<p class="bw-formrow__help">%s</p>', esc_html__( 'Set in wp-config.php, so it cannot be changed here.', 'blueworx-forge' ) );
			echo '</div></div>';

			return;
		}

		printf(
			'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="bw-input" autocomplete="off">',
			esc_attr( $type ),
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $value )
		);

		if ( '' !== $description ) {
			printf( '<p class="bw-formrow__help">%s</p>', esc_html( $description ) );
		}

		echo '</div></div>';
	}

	/**
	 * The button that forgets the credentials.
	 */
	private static function disconnect_button(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_client_disconnect' );
		echo '<input type="hidden" name="action" value="bwx_forge_client_disconnect">';
		echo '<button type="submit" class="bw-btn bw-btn--danger" data-bwx-action="bwx_forge_client_disconnect" onclick="return confirm(';
		echo esc_attr( (string) wp_json_encode( __( 'Forget this site\'s studio credentials?', 'blueworx-forge' ) ) );
		echo ')">' . esc_html__( 'Disconnect this site', 'blueworx-forge' ) . '</button>';
		echo '</form>';
	}
}
