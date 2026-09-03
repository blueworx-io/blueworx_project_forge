<?php
/**
 * The way out of the admin and onto the board.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Frontend;

/**
 * A "Board" entry in the Forge menu, pointing at the front-end app.
 *
 * ARCH-7 puts the work on a front-end page and the configuration in the admin,
 * which leaves the screen people live in all day as the only one with no way
 * to reach it: you had to know the address. This is that way.
 *
 * **It is a link, not a page.** WordPress renders a submenu whose slug is a
 * full URL as a plain anchor, so nothing is registered to render and nothing
 * new can be reached through it — it goes exactly where the address bar would
 * have gone.
 *
 * **It opens in a new tab, and says so before it is clicked.** The board is
 * somewhere you stay; losing the admin page you were configuring in order to
 * glance at it is the whole reason for the tab. The arrow is the convention
 * that warns a link behaves that way, and a link that opens a tab without
 * warning is the version people complain about.
 */
final class BoardLink {

	/**
	 * Marks the anchor for the script that opens it in a new tab.
	 *
	 * The menu title is the only part of a submenu entry a plugin controls, so
	 * the class goes on something inside the anchor and the script steps out to
	 * the anchor itself.
	 */
	private const MARKER = 'bwx-board-link';

	/**
	 * Adds the entry, and the behaviour that goes with it.
	 */
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'register' ) );
		add_action( 'admin_print_footer_scripts', array( self::class, 'open_in_a_new_tab' ) );
	}

	/**
	 * Puts it near the top of the Forge menu, but never at the top.
	 *
	 * **Second, and this is load-bearing.** WordPress gives a top-level menu
	 * the address of its *first* submenu entry, so a board link placed first
	 * silently becomes what clicking "Forge" does — which takes somebody out of
	 * the admin when all they did was open the menu. Second is as high as it
	 * can go without doing that.
	 */
	public static function register(): void {
		add_submenu_page(
			SitesScreen::SLUG,
			__( 'Board', 'blueworx-forge' ),
			self::title(),
			'manage_options',
			Frontend::instance()->app_page_url(),
			'',
			1
		);
	}

	/**
	 * The menu title: the word, and the arrow that warns about the new tab.
	 *
	 * `aria-hidden` because the arrow is decoration for people who can see it;
	 * the same warning is given to everybody else as text the menu does not
	 * show, which is what the screen-reader span is for.
	 *
	 * @return string
	 */
	private static function title(): string {
		return sprintf(
			'<span class="%1$s">%2$s <span class="dashicons dashicons-external" aria-hidden="true" style="font-size:14px;width:14px;height:14px;vertical-align:-2px;"></span><span class="screen-reader-text"> %3$s</span></span>',
			esc_attr( self::MARKER ),
			esc_html__( 'Board', 'blueworx-forge' ),
			esc_html__( '(opens in a new tab)', 'blueworx-forge' )
		);
	}

	/**
	 * Makes the entry open in a new tab.
	 *
	 * A submenu entry has no way to carry a target, so it is set on the anchor
	 * once the menu exists. `noopener` because a tab opened this way can
	 * otherwise reach back into the admin page that opened it.
	 */
	public static function open_in_a_new_tab(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_print_inline_script_tag(
			sprintf(
				'( function () { var mark = document.querySelector( %1$s ); var link = mark ? mark.closest( "a" ) : null; if ( link ) { link.target = "_blank"; link.rel = "noopener"; } } )();',
				wp_json_encode( '.' . self::MARKER )
			)
		);
	}
}
