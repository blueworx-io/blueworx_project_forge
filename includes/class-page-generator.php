<?php
defined( 'ABSPATH' ) || exit;

class Forge_PM_Page_Generator {

	const TEMPLATE_SLUG = 'forge-pm-full';

	public static function activate() {
		Forge_PM_Post_Types::register();
		self::register_template();
		self::create_page();
		Forge_PM_Sample_Data::maybe_seed();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	public static function register_template() {
		// Register our custom page template from the plugin directory
		add_filter( 'theme_page_templates', [ __CLASS__, 'add_template_to_list' ] );
		add_filter( 'template_include',     [ __CLASS__, 'load_template' ] );
	}

	public static function add_template_to_list( $templates ) {
		$templates[ self::TEMPLATE_SLUG ] = 'Forge PM — Full Page';
		return $templates;
	}

	public static function load_template( $template ) {
		if ( ! is_page() ) return $template;

		$page_template = get_post_meta( get_the_ID(), '_wp_page_template', true );
		if ( self::TEMPLATE_SLUG === $page_template ) {
			$plugin_template = FORGE_PM_DIR . 'templates/forge-pm-full.php';
			if ( file_exists( $plugin_template ) ) {
				return $plugin_template;
			}
		}

		return $template;
	}

	/**
	 * URL of the generated Forge PM page, falling back to the site home.
	 */
	public static function page_url() {
		$page_id = (int) get_option( 'forge_pm_page_id' );
		$url     = $page_id ? get_permalink( $page_id ) : '';
		return $url ?: home_url( '/' );
	}

	/**
	 * Send users to the Forge PM app page after logging in instead of wp-admin (#27).
	 * Honours an explicit redirect_to only when it points somewhere other than the
	 * WordPress backend, so deep links into the app still work.
	 */
	public static function login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		// Login failures pass a WP_Error here — leave those untouched.
		if ( ! is_a( $user, 'WP_User' ) ) {
			return $redirect_to;
		}

		$requested = is_string( $requested_redirect_to ) ? $requested_redirect_to : '';
		if ( $requested && false === strpos( $requested, '/wp-admin' ) ) {
			return $requested;
		}

		return self::page_url();
	}

	private static function create_page() {
		$page_id = get_option( 'forge_pm_page_id' );

		if ( $page_id && get_post_status( $page_id ) === 'publish' ) {
			// Ensure the template is assigned even if the page already exists
			if ( get_post_meta( $page_id, '_wp_page_template', true ) !== self::TEMPLATE_SLUG ) {
				update_post_meta( $page_id, '_wp_page_template', self::TEMPLATE_SLUG );
			}
			return;
		}

		$page_id = wp_insert_post( [
			'post_title'   => 'Forge Project Management',
			'post_content' => '[forge_project_management]',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		] );

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( 'forge_pm_page_id', $page_id );
			update_post_meta( $page_id, '_wp_page_template', self::TEMPLATE_SLUG );
		}
	}
}
