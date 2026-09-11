<?php
/**
 * The client workspace page.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * Owns the page the client app runs on (#297): creating it, serving it
 * through the plugin's own full-page template, and handing the bundle what it
 * needs to start.
 *
 * The same shape as the studio's Frontend, on purpose. A client's workspace
 * is a page on their own site rather than a screen in wp-admin, because the
 * design is the plugin's own and the admin belongs to the shared admin design
 * system — the two cannot share a page. The wp-admin screens stay where they
 * are for now; this page is reached from a link beside them.
 */
final class Frontend {

	/**
	 * Option holding the generated page's ID.
	 */
	public const PAGE_OPTION = 'bwx_forge_client_app_page_id';

	/**
	 * The generated page's slug.
	 */
	public const PAGE_SLUG = 'forge';

	/**
	 * The script and style handle.
	 */
	public const HANDLE = 'blueworx-forge-client';

	/**
	 * The single instance.
	 *
	 * @var Frontend|null
	 */
	private static ?Frontend $instance = null;

	/**
	 * Whether the app's assets have been queued already.
	 *
	 * @var bool
	 */
	private bool $enqueued = false;

	/**
	 * Returns the single instance.
	 */
	public static function instance(): Frontend {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hooks the page up.
	 */
	public function boot(): void {
		add_filter( 'template_include', array( $this, 'use_app_template' ) );
		add_action( 'template_redirect', array( $this, 'require_sign_in' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_shortcode( 'blueworx_forge_client', array( $this, 'render_mount_point' ) );

		// A site that already had the plugin when this page arrived never runs
		// activation again, so the page is also made the first time an admin
		// screen loads without one. It is one option read on every admin load,
		// and one insert ever.
		add_action( 'admin_init', array( $this, 'create_app_page' ) );
		add_action( 'admin_menu', array( $this, 'add_menu_link' ), 20 );
	}

	/**
	 * A link to the page, at the top of the plugin's wp-admin menu.
	 *
	 * A URL as the slug is how WordPress makes a menu entry that goes
	 * somewhere rather than rendering something. Late, so it lands under the
	 * overview rather than before it.
	 */
	public function add_menu_link(): void {
		if ( 0 === $this->app_page_id() ) {
			return;
		}

		add_submenu_page(
			Admin\Screen::SLUG,
			__( 'Workspace', 'blueworx-forge' ),
			__( 'Open workspace', 'blueworx-forge' ),
			'manage_options',
			$this->app_page_url()
		);
	}

	/**
	 * Creates the app page if it is missing.
	 */
	public function create_app_page(): void {
		if ( 0 !== $this->app_page_id() ) {
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Forge', 'blueworx-forge' ),
				'post_name'    => self::PAGE_SLUG,
				'post_content' => '[blueworx_forge_client]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);

		if ( is_wp_error( $page_id ) || 0 === $page_id ) {
			return;
		}

		update_option( self::PAGE_OPTION, (int) $page_id );
	}

	/**
	 * The generated page's ID, or 0 when it no longer exists.
	 */
	public function app_page_id(): int {
		$page_id = (int) get_option( self::PAGE_OPTION, 0 );

		if ( 0 === $page_id || 'publish' !== get_post_status( $page_id ) ) {
			return 0;
		}

		return $page_id;
	}

	/**
	 * The generated page's URL, falling back to the site home.
	 */
	public function app_page_url(): string {
		$page_id = $this->app_page_id();
		$url     = 0 !== $page_id ? get_permalink( $page_id ) : '';

		return is_string( $url ) && '' !== $url ? $url : home_url( '/' );
	}

	/**
	 * Serves the plugin's own full-page template on the app page.
	 *
	 * @param string $template Template WordPress resolved.
	 */
	public function use_app_template( string $template ): string {
		if ( ! $this->is_app_page() ) {
			return $template;
		}

		$own = BWX_FORGE_CLIENT_PATH . 'templates/app-page.php';

		return file_exists( $own ) ? $own : $template;
	}

	/**
	 * Sends anybody who cannot use the workspace to sign in first.
	 *
	 * The same capability as the wp-admin screens, so the page never shows
	 * somebody more than the screens beside it would. Who else may use a
	 * client's workspace is a decision for later, not a default taken here.
	 */
	public function require_sign_in(): void {
		if ( ! $this->is_app_page() || current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_safe_redirect( wp_login_url( $this->app_page_url() ) );
		exit;
	}

	/**
	 * Loads the built bundle and the data it needs, on the app page only.
	 */
	public function enqueue(): void {
		if ( ! $this->is_app_page() || $this->enqueued ) {
			return;
		}

		$this->enqueued = true;

		$script = BWX_FORGE_CLIENT_PATH . 'assets/js/blueworx-forge-client.js';
		$style  = BWX_FORGE_CLIENT_PATH . 'assets/css/blueworx-forge-client.css';

		if ( ! file_exists( $script ) ) {
			return;
		}

		if ( file_exists( $style ) ) {
			wp_enqueue_style(
				self::HANDLE,
				BWX_FORGE_CLIENT_URL . 'assets/css/blueworx-forge-client.css',
				array(),
				(string) filemtime( $style )
			);
		}

		wp_enqueue_script(
			self::HANDLE,
			BWX_FORGE_CLIENT_URL . 'assets/js/blueworx-forge-client.js',
			array(),
			(string) filemtime( $script ),
			true
		);

		$view = Workspace::view();
		$user = wp_get_current_user();

		$data = array(
			'restUrl'   => rest_url( 'blueworx-forge-client/v1' ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'siteUrl'   => get_site_url(),
			'adminUrl'  => admin_url( 'admin.php?page=blueworx-forge-client' ),
			'logoutUrl' => wp_logout_url( $this->app_page_url() ),
			'version'   => BWX_FORGE_CLIENT_VERSION,
			'client'    => array(
				'name'      => (string) ( $view['record']['name'] ?? '' ),
				'connected' => is_array( $view['record'] ?? null ),
			),
			'user'      => array(
				'name' => $user->display_name,
			),
		);

		// wp_json_encode rather than wp_localize_script, which would turn every
		// value into a string.
		wp_add_inline_script(
			self::HANDLE,
			'window.bwxForgeClientData = ' . wp_json_encode( $data ) . ';',
			'before'
		);
	}

	/**
	 * Prints the app's own stylesheet, and nothing else. The page does not
	 * call wp_head(): that is how the theme stays out (#193).
	 */
	public function print_styles(): void {
		$this->enqueue();
		wp_print_styles( self::HANDLE );
	}

	/**
	 * Prints the bundle and its data at the end of the body, after the mount
	 * point exists.
	 */
	public function print_scripts(): void {
		$this->enqueue();
		wp_print_scripts( self::HANDLE );
	}

	/**
	 * The mount point, for the shortcode.
	 */
	public function render_mount_point(): string {
		return '<div id="bwx-forge-client-app"></div>';
	}

	/**
	 * Whether the current request is the app page.
	 */
	private function is_app_page(): bool {
		$page_id = $this->app_page_id();

		return 0 !== $page_id && is_page( $page_id );
	}
}
