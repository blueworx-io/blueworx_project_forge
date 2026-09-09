<?php
namespace Blueworx\PageEditor\v1;

/**
 * The admin page itself: a mount point, the design system, and the editor.
 * WordPress supplies the menu and the admin bar, so the screen is full-bleed
 * within them — that is the only chrome the plugin overrides, and only here.
 */
final class Screen {

	/**
	 * slug => the hook suffix add_menu_page()/add_submenu_page() returned for
	 * it. That is the exact string WordPress will later pass back into
	 * admin_enqueue_scripts and use as the current screen id — matching
	 * against it is exact, where matching the hook against the slug with
	 * strpos() is not: "sport" and "sport-archive" would both match the same
	 * hook, and the wrong one could win.
	 */
	private static $hooks = [];

	public static function boot(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_filter( 'admin_body_class', [ __CLASS__, 'bodyClass' ] );
	}

	public static function menu(): void {
		foreach ( Editor::all() as $slug => $screen ) {
			$render = static function () use ( $slug ) {
				self::render( $slug );
			};
			if ( ! empty( $screen['parent'] ) ) {
				$hook = add_submenu_page( $screen['parent'], $screen['title'], $screen['menu_title'] ?? $screen['title'], $screen['capability'], $slug, $render );
			} else {
				$hook = add_menu_page( $screen['title'], $screen['menu_title'] ?? $screen['title'], $screen['capability'], $slug, $render, $screen['icon'] ?? 'dashicons-edit' );
			}
			if ( is_string( $hook ) && '' !== $hook ) {
				self::$hooks[ $slug ] = $hook;
			}
		}
	}

	public static function render( string $slug ): void {
		$screen = Editor::get( $slug );

		if ( ! Editor::ready( $slug ) ) {
			printf(
				'<div class="wrap bw-admin"><div class="bw-notice bw-notice--danger"><p>%s</p></div></div>',
				esc_html( Editor::problem( $slug ) )
			);
			return;
		}

		printf(
			'<div class="wrap bw-wrap bw-admin"><div id="bw-page-editor" data-screen="%s" data-record="%d"></div></div>',
			esc_attr( $slug ),
			(int) ( $_GET['id'] ?? 0 ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which record to show, not changing anything.
		);
	}

	public static function assets( string $hook ): void {
		$slug = self::slugForHook( $hook );
		if ( null === $slug ) {
			return;
		}

		if ( ! function_exists( 'blueworx_admin_design_enqueue' ) ) {
			// A plugin that re-pulled this editor library but not
			// assets/blueworx-admin-design.php beside it. This used to render a
			// blank #bw-page-editor with nothing on the page, no console error
			// and no admin notice — genuinely difficult to debug, since nothing
			// anywhere says the design system is what's missing. Same notice
			// markup render() prints below for a screen that isn't ready, so this
			// says so instead, and the media library enqueue below never runs
			// for a screen that's about to bail anyway.
			add_action( 'admin_notices', [ __CLASS__, 'missingDesignSystemNotice' ] );
			return;
		}

		$base   = self::url();
		$screen = Editor::get( $slug );

		// A 'media' or 'file' field opens the library via wp.media() (see
		// openLibrary() in blueworx-page-editor.js). That global only exists
		// once WordPress's own media modal script is enqueued — without this,
		// "Choose an image" and "Choose a file" render but silently do
		// nothing when clicked. wp_enqueue_media() pulls in roughly ten
		// scripts and the whole media modal, so it is worth skipping on a
		// screen whose schema has no such field.
		if ( null !== $screen && self::hasMediaField( $screen ) ) {
			wp_enqueue_media();
		}

		// The design system decides which copy of itself loads — the newest on
		// the site, once, however many plugins asked. This library used to
		// enqueue it directly under a shared handle, which meant the first
		// plugin to register won and every other plugin's screens wore its
		// stylesheet. See assets/blueworx-admin-design.php.
		blueworx_admin_design_enqueue();
		blueworx_admin_design_enqueue_icons();

		wp_enqueue_script( 'blueworx-page-editor', $base . 'assets/blueworx-page-editor.js', [ 'wp-element', 'wp-api-fetch', 'wp-i18n' ], self::version(), true );

		// The screen is full-bleed inside wp-admin's own chrome, and only here.
		// Keyed off bw-full-bleed (added via admin_body_class(), see
		// bodyClass() below) rather than a class built from the hook name:
		// WordPress's own body class for a hook is not the hook string
		// verbatim — it runs it through its own sanitising, which this
		// library cannot reproduce with certainty. A class this code adds
		// itself needs no guessing.
		wp_add_inline_style( 'blueworx-admin-design', implode( '', [
			'.wrap.bw-wrap{margin:0}',
			'body.bw-full-bleed #wpcontent{padding-left:0}',
			'body.bw-full-bleed #wpbody-content{padding-bottom:0}',
			'body.bw-full-bleed #wpfooter{display:none}',
		] ) );

		wp_add_inline_script(
			'blueworx-page-editor',
			'window.blueworxPageEditor=' . wp_json_encode( [
				'root'      => esc_url_raw( rest_url( Rest::NS ) ),
				'namespace' => Rest::NS,
				'home'      => trailingslashit( home_url() ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
			] ) . ';',
			'before'
		);
	}

	/**
	 * Prints the same notice markup render() uses for "screen not ready",
	 * for the "design system not re-pulled" case caught in assets() above.
	 * It cannot rely on the design system's own stylesheet for that markup —
	 * the whole reason it is showing is that the stylesheet failed to load —
	 * so the notice is plain rather than styled, but plain and visible beats
	 * a blank screen with no explanation anywhere.
	 */
	public static function missingDesignSystemNotice(): void {
		printf(
			'<div class="wrap bw-admin"><div class="bw-notice bw-notice--danger"><p>%s</p></div></div>',
			esc_html__( "This plugin's copy of the BlueWorx design system is missing or incomplete — re-pull assets/blueworx-admin-design.php from the design system, then reload this page.", 'blueworx-page-editor' )
		);
	}

	/** Adds bw-full-bleed to <body> only on a registered editor screen. */
	public static function bodyClass( string $classes ): string {
		global $hook_suffix;
		if ( in_array( $hook_suffix, self::$hooks, true ) ) {
			$classes .= ' bw-full-bleed';
		}
		return $classes;
	}

	private static function slugForHook( string $hook ): ?string {
		$slug = array_search( $hook, self::$hooks, true );
		return false === $slug ? null : $slug;
	}

	/**
	 * Whether this screen's schema — the plugin's own, unmerged — has a
	 * 'media' or 'file' field anywhere, including inside a repeater. A
	 * post-store screen always counts: Settings::tab() adds its own
	 * 'featured_image' media field to every one of those (see Settings.php),
	 * so checking the plugin's bare schema alone would miss it without also
	 * merging that tab in — cheaper to just know a post screen always has one.
	 */
	private static function hasMediaField( array $screen ): bool {
		if ( 'post' === ( $screen['store'] ?? '' ) ) {
			return true;
		}
		foreach ( $screen['tabs'] ?? [] as $tab ) {
			foreach ( $tab['panels'] ?? [] as $panel ) {
				foreach ( $panel['fields'] ?? [] as $field ) {
					if ( self::isMediaKind( $field['kind'] ?? '' ) ) {
						return true;
					}
					if ( 'repeater' === ( $field['kind'] ?? '' ) ) {
						foreach ( $field['fields'] ?? [] as $sub_field ) {
							if ( self::isMediaKind( $sub_field['kind'] ?? '' ) ) {
								return true;
							}
						}
					}
				}
			}
		}
		return false;
	}

	private static function isMediaKind( string $kind ): bool {
		return in_array( $kind, [ 'media', 'file' ], true );
	}

	/**
	 * Only one copy of the library ever runs — the highest version on the
	 * site (see Registry) — regardless of which plugin registered any given
	 * screen. So the asset URL always comes from wherever that winning
	 * copy's own plugin vendored it, not from the plugin that registered
	 * this particular screen, and not from a directory depth guessed from
	 * this file's own location — that guess only holds in this repo's own
	 * layout, and walks past the plugin root once the library is vendored
	 * into a real plugin at <plugin>/blueworx-page-editor/v1/.
	 *
	 * The filter stays as an escape hatch for a plugin that keeps its built
	 * assets somewhere other than <plugin>/assets/ (a custom build output
	 * directory, for instance) — the default only ever guesses right when
	 * assets live at the conventional path.
	 */
	private static function url(): string {
		$loader = \Blueworx\PageEditor\Registry::loaderFile();
		$base   = $loader ? plugin_dir_url( dirname( $loader ) ) : '';
		return apply_filters( 'blueworx_page_editor_asset_url', $base );
	}

	private static function version(): string {
		return \Blueworx\PageEditor\Registry::latest();
	}
}
