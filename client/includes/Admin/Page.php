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
	 * Closes a panel.
	 */
	public static function panel_close(): void {
		echo '</div></section>';
	}
}
