<?php
/**
 * The Forge menu's icon.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

/**
 * The lucide anvil, in the admin menu.
 *
 * WordPress recolours a menu's SVG icon by rewriting its fills, and lucide
 * icons are strokes with no fill — so handed to add_menu_page on its own the
 * anvil would never take the menu's hover or current colour. The icon is
 * therefore drawn twice: once as the data URI add_menu_page wants, in the
 * menu's resting grey, and once as a mask the stylesheet below paints with
 * `currentColor`, which is whatever colour the menu entry is at the time.
 *
 * The CSS is printed on every admin screen because the menu is on every admin
 * screen. It names one menu entry and nothing else.
 */
final class MenuIcon {

	/**
	 * The menu's resting icon colour in the default admin scheme.
	 */
	private const REST = '#a7aaad';

	/**
	 * The icon, ready for add_menu_page.
	 *
	 * @return string
	 */
	public static function data_uri(): string {
		return 'data:image/svg+xml;base64,' . base64_encode( self::svg( self::REST ) );
	}

	/**
	 * Prints the stylesheet that paints the icon in the menu's own colour.
	 */
	public static function print_styles(): void {
		$mask = 'url("data:image/svg+xml;base64,' . base64_encode( self::svg( '#000' ) ) . '")';
		$item = '#adminmenu .toplevel_page_' . SitesScreen::SLUG . ' .wp-menu-image';

		echo '<style id="bwx-forge-menu-icon">';
		echo esc_html( $item ) . '{background-image:none!important}';
		echo esc_html( $item ) . '::before{content:"";display:block;width:20px;height:20px;margin:7px auto 0;background-color:currentColor;';
		echo '-webkit-mask:' . $mask . ' center/20px 20px no-repeat;mask:' . $mask . ' center/20px 20px no-repeat}'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built here from a base64 string and fixed CSS; nothing in it came from a request.
		echo esc_html( $item ) . ' img{display:none}';
		echo '</style>';
	}

	/**
	 * The lucide anvil, stroked in one colour.
	 *
	 * @param string $stroke A CSS colour.
	 * @return string
	 */
	public static function svg( string $stroke ): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="' . $stroke . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
			. '<path d="M7 10H6a4 4 0 0 1-4-4 1 1 0 0 1 1-1h4"/>'
			. '<path d="M7 5a1 1 0 0 1 1-1h13a1 1 0 0 1 1 1 7 7 0 0 1-7 7H8a1 1 0 0 1-1-1z"/>'
			. '<path d="M9 12v5"/><path d="M15 12v5"/>'
			. '<path d="M5 20a3 3 0 0 1 3-3h8a3 3 0 0 1 3 3 1 1 0 0 1-1 1H6a1 1 0 0 1-1-1"/>'
			. '</svg>';
	}
}
