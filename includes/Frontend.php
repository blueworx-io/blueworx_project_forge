<?php
/**
 * The front-end app page.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge;

/**
 * Owns the page the app runs on: creating it, serving it through the plugin's
 * own full-page template, and handing the built bundle the data it needs.
 */
final class Frontend {

	/**
	 * Option holding the generated page's ID.
	 */
	public const PAGE_OPTION = 'bwx_forge_app_page_id';

	/**
	 * The generated page's slug, and the template's name.
	 */
	public const PAGE_SLUG = 'blueworx-forge';

	/**
	 * The single instance.
	 *
	 * @var Frontend|null
	 */
	private static ?Frontend $instance = null;

	/**
	 * Whether the app's assets have been queued already.
	 *
	 * The template asks for them rather than waiting for wp_head, so the same
	 * call can arrive twice — once from the head, once from the body.
	 *
	 * @var bool
	 */
	private bool $enqueued = false;

	/**
	 * Returns the single instance.
	 *
	 * @return Frontend
	 */
	public static function instance(): Frontend {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hooks the front end up.
	 */
	public function boot(): void {
		add_filter( 'template_include', array( $this, 'use_app_template' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_shortcode( 'blueworx_forge', array( $this, 'render_mount_point' ) );
	}

	/**
	 * Creates the app page if it is missing. Runs on activation.
	 */
	public function create_app_page(): void {
		if ( 0 !== $this->app_page_id() ) {
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Blueworx Forge', 'blueworx-forge' ),
				'post_name'    => self::PAGE_SLUG,
				'post_content' => '[blueworx_forge]',
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
	 *
	 * @return int
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
	 *
	 * @return string
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
	 * @return string
	 */
	public function use_app_template( string $template ): string {
		if ( ! $this->is_app_page() ) {
			return $template;
		}

		$own = BWX_FORGE_PATH . 'templates/app-page.php';

		return file_exists( $own ) ? $own : $template;
	}

	/**
	 * Loads the built bundle and the data it needs, on the app page only.
	 */
	public function enqueue(): void {
		if ( ! $this->is_app_page() || $this->enqueued ) {
			return;
		}

		$this->enqueued = true;

		$script = BWX_FORGE_PATH . 'assets/js/blueworx-forge.js';
		$style  = BWX_FORGE_PATH . 'assets/css/blueworx-forge.css';

		if ( ! file_exists( $script ) ) {
			return;
		}

		if ( file_exists( $style ) ) {
			wp_enqueue_style(
				'blueworx-forge',
				BWX_FORGE_URL . 'assets/css/blueworx-forge.css',
				array(),
				(string) filemtime( $style )
			);
		}

		wp_enqueue_script(
			'blueworx-forge',
			BWX_FORGE_URL . 'assets/js/blueworx-forge.js',
			array(),
			(string) filemtime( $script ),
			true
		);

		$data = array(
			'restUrl'    => rest_url( 'blueworx-forge/v1' ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'isLoggedIn' => is_user_logged_in(),
			'canEdit'    => current_user_can( 'edit_posts' ),
			'canManage'  => current_user_can( 'manage_options' ),
			'siteUrl'    => get_site_url(),
			'loginUrl'   => wp_login_url( $this->app_page_url() ),
			'logoutUrl'  => wp_logout_url( $this->app_page_url() ),
			'version'    => BWX_FORGE_VERSION,
		);

		/*
		 * Not wp_localize_script(): it casts every value to a string, so the four
		 * booleans above would reach the front end as "1" and "" — and "" is
		 * truthy-looking enough in review to pass, while `false` is what the
		 * ForgeData type in src/App.tsx promises. wp_json_encode keeps the types.
		 */
		wp_add_inline_script(
			'blueworx-forge',
			'window.bwxForgeData = ' . wp_json_encode( $data ) . ';',
			'before'
		);
	}

	/**
	 * Prints the app's own stylesheets, and nothing else.
	 *
	 * The app page does not call wp_head() (#193). That is the whole isolation:
	 * wp_head() is an open door — the active theme, its fonts, the block
	 * library's presets and every other plugin's global CSS all arrive through
	 * it, and almost all of it arrives as inline <style> rather than as a link,
	 * so it does not look like anything is being loaded.
	 *
	 * Dequeuing them one by one was the alternative and it is a losing game:
	 * each WordPress release adds another inline block, and the page silently
	 * starts inheriting again. Printing only our own handles cannot drift.
	 *
	 * The cost is that nothing a site adds through wp_head reaches this page —
	 * analytics, cookie banners, a theme's fonts. On an application screen that
	 * is the intended answer rather than a regrettable side effect.
	 */
	public function print_styles(): void {
		$this->enqueue();
		wp_print_styles( 'blueworx-forge' );
	}

	/**
	 * Prints the app bundle and the data it needs, at the end of the body.
	 *
	 * At the end, not in the head: the bundle mounts into #bwx-forge-app as it
	 * runs, so the element has to exist first.
	 */
	public function print_scripts(): void {
		$this->enqueue();
		wp_print_scripts( 'blueworx-forge' );
	}

	/**
	 * The mount point, for the shortcode.
	 *
	 * @return string
	 */
	public function render_mount_point(): string {
		return '<div id="bwx-forge-app"></div>';
	}

	/**
	 * Whether the current request is the app page.
	 *
	 * @return bool
	 */
	private function is_app_page(): bool {
		$page_id = $this->app_page_id();

		return 0 !== $page_id && is_page( $page_id );
	}
}
