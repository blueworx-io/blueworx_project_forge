<?php
defined( 'ABSPATH' ) || exit;

class Forge_PM_Enqueue {

	// Styles that must never be removed — admin bar, dashicons, block styles, WP media uploader
	const PRESERVE_STYLES = [
		'admin-bar', 'dashicons', 'wp-block-library',
		// WP media library modal (wp_enqueue_media)
		'media-views', 'thickbox', 'wp-color-picker', 'imgareaselect',
	];

	public static function enqueue() {
		if ( ! self::is_forge_page() ) return;

		$js_file  = FORGE_PM_DIR . 'assets/js/forge-app.js';
		$css_file = FORGE_PM_DIR . 'assets/css/forge-app.css';

		if ( ! file_exists( $js_file ) ) return;

		$js_ver  = filemtime( $js_file );
		$css_ver = file_exists( $css_file ) ? filemtime( $css_file ) : FORGE_PM_VERSION;

		// Strip theme/plugin stylesheets but preserve WP core ones (admin bar, dashicons, etc.)
		add_action( 'wp_print_styles', [ __CLASS__, 'dequeue_theme_styles' ], 100 );

		if ( file_exists( $css_file ) ) {
			wp_enqueue_style( 'forge-pm-app', FORGE_PM_URL . 'assets/css/forge-app.css', [], $css_ver );
		}

		// Load WP media uploader for users who can access the media library
		if ( current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}

		wp_enqueue_script( 'forge-pm-app', FORGE_PM_URL . 'assets/js/forge-app.js', [], $js_ver, true );

		$redirect = get_permalink();
		if ( ! $redirect ) {
			$redirect = home_url( '/' );
		}

		wp_localize_script( 'forge-pm-app', 'forgePMData', [
			'apiUrl'      => rest_url( 'forge/v1' ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'isAdmin'     => current_user_can( 'edit_posts' ),
			'isLoggedIn'  => is_user_logged_in(),
			'siteUrl'     => get_site_url(),
			'loginUrl'    => wp_login_url( $redirect ),
			'logoutUrl'   => wp_logout_url( $redirect ),
			'settings'    => Forge_PM_Settings::get(),
		] );
	}

	public static function dequeue_theme_styles() {
		global $wp_styles;
		if ( empty( $wp_styles->queue ) ) return;

		foreach ( $wp_styles->queue as $handle ) {
			// Keep our app CSS and all core WP styles we need
			if ( $handle === 'forge-pm-app' ) continue;
			if ( in_array( $handle, self::PRESERVE_STYLES, true ) ) continue;
			wp_dequeue_style( $handle );
		}
	}

	public static function render_app() {
		return '<div id="forge-pm-app"></div>';
	}

	private static function is_forge_page() {
		$page_id = (int) get_option( 'forge_pm_page_id' );
		if ( $page_id && is_page( $page_id ) ) return true;
		if ( is_page() && get_post_meta( get_the_ID(), '_wp_page_template', true ) === Forge_PM_Page_Generator::TEMPLATE_SLUG ) return true;
		return false;
	}
}
