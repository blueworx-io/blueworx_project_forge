<?php
/**
 * The client workspace frame.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

use Blueworx\Forge\Client\Workspace;

/**
 * One frame every client screen sits inside (#126).
 *
 * Two things, and they are nearly the same thing said twice: whose workspace
 * this is, and where else they can go inside it.
 *
 * The scope is not a filter and there is no control to change it. A client site
 * holds one site id and one signing key issued by the studio, and every read is
 * answered for whoever signed it — so this artifact has no credential for
 * another client and nothing typed into it can invent one. That is why the
 * links below carry a page and nothing else: a navigation that can name a
 * client is one somebody can edit to name a different client, and the safest
 * parameter is the one that was never there.
 *
 * Saying whose workspace it is matters for the ordinary case rather than the
 * hostile one. Somebody administering several client sites has several tabs
 * open that look alike, and needs the screen to say which is which before they
 * act on it.
 */
final class Nav {

	/**
	 * The pages inside the frame, in the order they are shown.
	 *
	 * @return array<int, array{slug: string, label: string}>
	 */
	private static function pages(): array {
		return array(
			array(
				'slug'  => Screen::SLUG,
				'label' => __( 'Overview', 'blueworx-forge' ),
			),
			array(
				'slug'  => BoardScreen::SLUG,
				'label' => __( 'Board', 'blueworx-forge' ),
			),
			array(
				'slug'  => TimelineScreen::SLUG,
				'label' => __( 'Timeline', 'blueworx-forge' ),
			),
			array(
				'slug'  => CalendarScreen::SLUG,
				'label' => __( 'Calendar', 'blueworx-forge' ),
			),
			array(
				'slug'  => AskScreen::SLUG,
				'label' => __( 'Ask for something', 'blueworx-forge' ),
			),
			array(
				'slug'  => ConnectionScreen::SLUG,
				'label' => __( 'Connection', 'blueworx-forge' ),
			),
		);
	}

	/**
	 * Renders the frame's head: whose workspace, and the pages within it.
	 *
	 * The workspace record is passed in rather than read here. The screen
	 * calling this has already read it, and a second read would be a second
	 * chance for the two halves of one screen to disagree about how old what
	 * they are showing is.
	 *
	 * @param string               $current The slug of the screen being rendered.
	 * @param array<string, mixed> $view    The workspace as Workspace::view saw it.
	 */
	public static function render( string $current, array $view = array() ): void {
		if ( array() === $view ) {
			$view = Workspace::view( false );
		}

		$name = (string) ( $view['record']['name'] ?? '' );

		echo '<div class="bwx-client-frame">';
		echo '<p class="bwx-client-scope" data-testid="bwx-client-scope">';

		if ( '' === $name ) {
			// Not a failure to name the client — a workspace nobody has
			// connected yet, which is worth saying plainly.
			echo esc_html__( 'Not connected to a studio yet', 'blueworx-forge' );
		} else {
			echo esc_html__( 'Workspace for', 'blueworx-forge' ) . ' ';
			echo '<strong>' . esc_html( $name ) . '</strong>';
		}

		echo '</p>';

		printf(
			'<nav class="bwx-client-nav" data-testid="bwx-client-nav" aria-label="%s">',
			esc_attr__( 'Workspace', 'blueworx-forge' )
		);

		// A page and nothing else in each href. Nothing here names a client or a
		// site, so there is nothing here to edit into somebody else's.
		foreach ( self::pages() as $page ) {
			printf(
				'<a class="bwx-client-nav-item" data-testid="bwx-client-nav-item" href="%s"%s>%s</a>',
				esc_url( admin_url( 'admin.php?page=' . $page['slug'] ) ),
				$page['slug'] === $current ? ' aria-current="page"' : '',
				esc_html( $page['label'] )
			);
		}

		echo '</nav>';
		echo '</div>';
	}
}
