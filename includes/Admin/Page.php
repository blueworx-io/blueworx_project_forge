<?php
/**
 * The shell every studio screen renders inside.
 *
 * The foundation's page skeleton, in the order the design system fixes it:
 * wrap → page → header → panels. Nothing here decides what a screen says; it
 * decides only the shape all of them share, so that shape is in one file rather
 * than repeated across eleven.
 *
 * The studio's counterpart to client/includes/Admin/Page.php, and deliberately
 * the same file twice rather than one shared one: the two plugins are two
 * artifacts, and ARCH-1 is that a client's site cannot physically contain
 * studio code. A page shell is not worth reopening that.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

/**
 * The shared page shell.
 */
final class Page {

	/**
	 * Opens the page and its header.
	 *
	 * @param string $title   The page title.
	 * @param string $eyebrow Small label above the title. Optional.
	 * @param string $lede    A sentence under the title. Optional.
	 */
	public static function open( string $title, string $eyebrow = '', string $lede = '' ): void {
		echo '<div class="wrap bw-wrap"><div class="bw-admin bw-page">';
		echo '<header class="bw-pagehead">';
		echo '<div class="bw-pagehead__titles">';

		if ( '' !== $eyebrow ) {
			printf(
				'<p class="bw-pagehead__eyebrow">%s</p>',
				esc_html( $eyebrow )
			);
		}

		printf( '<h1 class="bw-pagehead__h1">%s</h1>', esc_html( $title ) );

		if ( '' !== $lede ) {
			printf( '<p class="bw-pagehead__lede">%s</p>', esc_html( $lede ) );
		}

		echo '</div></header>';

		// The panel column. ScreenLayout draws this as bw-panels when a screen
		// has no sidebar, and no studio screen has one.
		echo '<div class="bw-panels">';
	}

	/**
	 * Closes the page.
	 */
	public static function close(): void {
		echo '</div></div></div>';
	}

	/**
	 * Opens a panel.
	 *
	 * @param string $heading The panel heading.
	 * @param string $name    A name for tests and styling to hold on to.
	 */
	public static function panel_open( string $heading, string $name ): void {
		printf(
			'<section class="bw-card" data-bwx-panel="%s">',
			esc_attr( $name )
		);
		echo '<div class="bw-card__head"><div class="bw-card__titles">';
		printf( '<h2 class="bw-card__title">%s</h2>', esc_html( $heading ) );
		echo '</div></div>';
		echo '<div class="bw-card__body">';
	}

	/**
	 * Closes a panel.
	 */
	public static function panel_close(): void {
		echo '</div></section>';
	}

	/**
	 * A banner.
	 *
	 * The design system's Notice, in the one place every studio screen can
	 * reach it. Replaces the `notice notice-*` markup the screens wrote by
	 * hand, which was WordPress's own admin styling and the one piece of
	 * chrome that would have looked unmistakably unlike the rest of Blueworx
	 * on an otherwise rebuilt screen.
	 *
	 * @param string                $tone       success, warning, danger or info.
	 * @param string                $text       The sentence to show.
	 * @param array<string, string> $attributes Extra attributes on the banner.
	 * @param bool                  $html       Whether $text carries markup.
	 */
	public static function notice( string $tone, string $text, array $attributes = array(), bool $html = false ): void {
		$icons = array(
			'success' => 'circle-check',
			'warning' => 'triangle-alert',
			'danger'  => 'circle-alert',
			'info'    => 'info',
		);

		// Whole class names rather than a stem with the tone appended: the
		// admin UI check reads the classes a screen writes, and one assembled
		// from a variable is one it cannot see.
		$classes = array(
			'success' => 'bw-notice bw-notice--success',
			'warning' => 'bw-notice bw-notice--warning',
			'danger'  => 'bw-notice bw-notice--danger',
			'info'    => 'bw-notice bw-notice--info',
		);

		printf( '<div class="%s"', esc_attr( $classes[ $tone ] ?? $classes['info'] ) );

		foreach ( $attributes as $name => $value ) {
			printf( ' %1$s="%2$s"', esc_attr( $name ), esc_attr( $value ) );
		}

		printf( ' role="%s">', esc_attr( 'danger' === $tone ? 'alert' : 'status' ) );

		printf(
			'<i class="bw-icon bw-notice__icon" data-lucide="%s"></i>',
			esc_attr( $icons[ $tone ] ?? 'info' )
		);

		printf(
			'<div class="bw-notice__body"><p class="bw-notice__text">%s</p></div>',
			$html ? wp_kses_post( $text ) : esc_html( $text )
		);

		echo '</div>';
	}

	/**
	 * Whether a hook belongs to one of the studio's own screens.
	 *
	 * Matched on the slug prefix rather than against a list, because every
	 * studio screen's slug already begins with it and a list is one more thing
	 * to forget to add a screen to.
	 *
	 * @param string $hook The screen being loaded.
	 */
	public static function ours( string $hook ): bool {
		return false !== strpos( $hook, '_page_blueworx-forge' );
	}

	/**
	 * Loads the design system on the studio's own screens, and nowhere else.
	 *
	 * Not on every admin screen: the stylesheet is a whole design system, and a
	 * plugin that repaints the rest of somebody's wp-admin is a plugin they
	 * uninstall. style-isolation.spec.js is the spec that holds this.
	 *
	 * @param string $hook The screen being loaded.
	 */
	public static function enqueue( string $hook ): void {
		if ( ! self::ours( $hook ) ) {
			return;
		}

		$design = BWX_FORGE_PATH . 'assets/blueworx-admin-design.css';

		if ( ! file_exists( $design ) ) {
			return;
		}

		wp_enqueue_style(
			'blueworx-admin-design',
			BWX_FORGE_URL . 'assets/blueworx-admin-design.css',
			array(),
			(string) filemtime( $design )
		);

		$icons = BWX_FORGE_PATH . 'assets/blueworx-admin-icons.js';

		if ( file_exists( $icons ) ) {
			wp_enqueue_script_module(
				'blueworx-admin-icons',
				BWX_FORGE_URL . 'assets/blueworx-admin-icons.js',
				array(),
				(string) filemtime( $icons )
			);
		}
	}
}
