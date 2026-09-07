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

		wp_enqueue_style( 'blueworx-admin-design', $base . 'assets/blueworx-admin-design.css', [], self::version() );
		wp_enqueue_script( 'blueworx-page-editor', $base . 'assets/blueworx-page-editor.js', [ 'wp-element', 'wp-api-fetch', 'wp-i18n' ], self::version(), true );
		// The design system's icons ship as a self-hosted ES module (see
		// assets/blueworx-admin-icons.js): the browser's <i data-lucide="…">
		// markup turns into inline SVGs only once it has run. It needs
		// type="module", but wp_enqueue_script_module() only exists from
		// WordPress 6.5 — this repo declares no WordPress floor, so "older"
		// is not something the library can rule out. wp_enqueue_script() plus
		// this filter is the same effect back to WP 4.1, where
		// script_loader_tag was introduced.
		wp_enqueue_script( 'blueworx-admin-icons', $base . 'assets/blueworx-admin-icons.js', [], self::version(), true );
		add_filter( 'script_loader_tag', [ __CLASS__, 'moduleType' ], 10, 2 );

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
	 * Forces the icon script's tag to type="module" — see assets() above.
	 * WordPress 4.1–5.6 always prints type='text/javascript' on every
	 * enqueued script, and 5.7–6.3 still do unless the active theme declares
	 * HTML5 script support — so on any of those versions the tag already
	 * carries a type attribute, just the wrong one. Bailing out when a type
	 * attribute was already present (the previous version of this method)
	 * left that wrong type in place on exactly the versions this fallback
	 * exists for: the module keeps its top-level export, the browser throws
	 * a syntax error on it, and every icon is the same empty box the
	 * fallback was written to fix. This replaces whatever value is there
	 * instead, and only adds the attribute outright when none exists at all.
	 */
	public static function moduleType( string $tag, string $handle ): string {
		if ( 'blueworx-admin-icons' !== $handle ) {
			return $tag;
		}
		if ( preg_match( '/\stype=([\'"])[^\'"]*\1/', $tag ) ) {
			return preg_replace( '/\stype=([\'"])[^\'"]*\1/', ' type=$1module$1', $tag, 1 );
		}
		return str_replace( ' src=', ' type="module" src=', $tag );
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
