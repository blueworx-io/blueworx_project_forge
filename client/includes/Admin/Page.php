<?php
/**
 * The shell every client screen renders inside.
 *
 * The foundation's page skeleton, in the order the design system fixes it:
 * wrap → page → header → tabs → panels. Nothing here decides what a screen
 * says; it decides only the shape all of them share, so that shape is in one
 * file rather than repeated across ten.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

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
		echo '<header class="bw-pagehead" data-testid="bwx-pagehead">';
		echo '<div class="bw-pagehead__titles">';

		if ( '' !== $eyebrow ) {
			// data-testid carried over from Nav::render()'s old bwx-client-scope
			// paragraph (#126): every client screen's eyebrow says whose
			// workspace this is, so the pair suite's one test id still applies.
			printf(
				'<p class="bw-pagehead__eyebrow" data-testid="bwx-client-scope">%s</p>',
				esc_html( $eyebrow )
			);
		}

		printf( '<h1 class="bw-pagehead__h1">%s</h1>', esc_html( $title ) );

		if ( '' !== $lede ) {
			printf( '<p class="bw-pagehead__lede">%s</p>', esc_html( $lede ) );
		}

		echo '</div></header>';

		// The panel column. ScreenLayout draws this as bw-panels when a screen
		// has no sidebar, and no client screen has one.
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
	 * The test id and the panel name are carried over from Screen::open()
	 * unchanged: the pair suite holds on to both, and a rebuild that renames
	 * them is a rebuild that cannot be checked.
	 *
	 * @param string $heading The panel heading.
	 * @param string $name    A name for tests and styling to hold on to.
	 */
	public static function panel_open( string $heading, string $name ): void {
		printf(
			'<section class="bw-card" data-testid="bwx-panel" data-bwx-panel="%s">',
			esc_attr( $name )
		);
		echo '<div class="bw-card__head"><div class="bw-card__titles">';
		printf( '<h2 class="bw-card__title">%s</h2>', esc_html( $heading ) );
		echo '</div></div>';
		echo '<div class="bw-card__body">';
	}

	/**
	 * A banner.
	 *
	 * The design system's Notice, in the one place every client screen can
	 * reach it. Standing guidance — what happens to something once it is sent,
	 * who to talk to, what this site keeps and what it only shows — is a banner
	 * rather than a small grey line under a panel, because a sentence set in
	 * muted type at the foot of a form reads as small print and is skipped by
	 * exactly the people it was written for.
	 *
	 * Lifted here from ConnectionScreen, which had the only complete version of
	 * it. That screen now calls this one, so there is one banner in this plugin
	 * rather than one per screen that happened to need it.
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
	 * Closes a panel.
	 */
	public static function panel_close(): void {
		echo '</div></section>';
	}
}
