<?php
/**
 * Network Admin → Settings → Forge network.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

use Blueworx\Forge\Client\Network\Key;

/**
 * #621. Where a network administrator makes the key the studio signs its
 * create-site calls with, and reads the address to give the studio with it.
 * The key is shown once, straight after it is made, and never again; making
 * a new one cuts the studio off until the new one is entered in Forge's
 * Connections.
 *
 * Only on a network, and only for those who may manage it.
 */
final class NetworkKeyScreen {

	/**
	 * Page slug.
	 */
	public const SLUG = 'blueworx-forge-network';

	/**
	 * The action the form posts to.
	 */
	public const ACTION = 'bwx_forge_network_key';

	/**
	 * Who may see the screen and make a key.
	 */
	private const CAPABILITY = 'manage_network_options';

	/**
	 * Hooks the screen and its form up.
	 */
	public static function boot(): void {
		if ( ! is_multisite() ) {
			return;
		}

		add_action( 'network_admin_menu', array( self::class, 'register' ) );
		add_action( 'network_admin_edit_' . self::ACTION, array( self::class, 'generate' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * Adds the screen under Network Admin's Settings.
	 */
	public static function register(): void {
		add_submenu_page( 'settings.php', __( 'Forge network', 'blueworx-forge' ), __( 'Forge network', 'blueworx-forge' ), self::CAPABILITY, self::SLUG, array( self::class, 'render' ) );
	}

	/**
	 * Makes a new key, keeps it to show once, and goes back to the screen.
	 */
	public static function generate(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You may not do that.', 'blueworx-forge' ), 403 );
		}

		check_admin_referer( self::ACTION );

		if ( ! Key::fixed() ) {
			set_site_transient( self::shown_once(), Key::generate(), 5 * MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( network_admin_url( 'settings.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Draws the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$fresh = (string) get_site_transient( self::shown_once() );
		delete_site_transient( self::shown_once() );

		Page::open( __( 'Forge network', 'blueworx-forge' ), '', __( 'Blueworx Forge can create client sites on this network. In Forge, open Settings, then Connections, and connect the WordPress multisite network with this address and key.', 'blueworx-forge' ) );
		Page::panel_open( __( 'Network key', 'blueworx-forge' ), 'network-key' );

		echo '<div class="bw-formrow">';
		printf( '<span class="bw-formrow__label">%s</span>', esc_html__( 'Network address', 'blueworx-forge' ) );
		printf( '<div class="bw-formrow__control"><code class="bw-input--mono" data-testid="bwx-network-address">%s</code></div>', esc_html( untrailingslashit( network_home_url() ) ) );
		echo '</div>';

		echo '<div class="bw-formrow">';
		printf( '<span class="bw-formrow__label">%s</span>', esc_html__( 'Key', 'blueworx-forge' ) );
		echo '<div class="bw-formrow__control">';

		if ( '' !== $fresh ) {
			echo '<code class="bw-input--mono" data-testid="bwx-network-key">' . esc_html( $fresh ) . '</code><p class="bw-formrow__help">' . esc_html__( 'Copy it now: it is not shown again.', 'blueworx-forge' ) . '</p>';
		} elseif ( Key::fixed() ) {
			echo '<p class="bw-formrow__help">' . esc_html__( 'Set in wp-config.php (BWX_FORGE_NETWORK_KEY).', 'blueworx-forge' ) . '</p>';
		} else {
			echo '<p class="bw-formrow__help">' . esc_html( '' === Key::get() ? __( 'No key yet.', 'blueworx-forge' ) : __( 'A key is set. Make a new one to replace it; Forge stops creating sites until the new one is entered there.', 'blueworx-forge' ) ) . '</p>';
		}

		if ( ! Key::fixed() ) {
			echo '<form method="post" action="' . esc_url( network_admin_url( 'edit.php?action=' . self::ACTION ) ) . '">';
			wp_nonce_field( self::ACTION );
			printf( '<button type="submit" class="bw-btn bw-btn--primary">%s</button>', esc_html( '' === Key::get() ? __( 'Make a key', 'blueworx-forge' ) : __( 'Make a new key', 'blueworx-forge' ) ) );
			echo '</form>';
		}

		echo '</div></div>';

		Page::panel_close();
		Page::close();
	}

	/**
	 * Loads the design system here. This is a network screen, so the site
	 * screens' own loader never sees it.
	 *
	 * @param string $hook The screen's hook suffix.
	 */
	public static function enqueue( string $hook ): void {
		if ( 'settings_page_' . self::SLUG !== $hook || ! function_exists( 'blueworx_admin_design_enqueue' ) ) {
			return;
		}

		blueworx_admin_design_enqueue();
	}

	/**
	 * Where the new key waits to be shown, per person.
	 *
	 * @return string
	 */
	private static function shown_once(): string {
		return 'bwx_forge_network_key_shown_' . get_current_user_id();
	}
}
