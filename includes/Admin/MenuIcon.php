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
 * Handed to add_menu_page as a data URI and then left to WordPress, which
 * paints a menu's SVG icon the way it paints its own: as the entry's
 * background, sized and placed by the menu's stylesheet — so whatever an
 * admin theme does to every other plugin's icon it does to this one — and
 * recoloured on hover and on the current screen by rewriting the SVG's fill.
 *
 * Lucide draws with strokes; WordPress recolours fills. So the anvil is given
 * as a filled silhouette, which is also how every other icon in that menu is
 * drawn. An earlier version painted the outline itself on a box beside the
 * entry, and sat wrong the moment a site restyled its menu.
 */
final class MenuIcon {

	/**
	 * The menu's resting icon colour in the default admin scheme. WordPress
	 * repaints it for the site's own scheme once the page has loaded.
	 */
	private const REST = '#a7aaad';

	/**
	 * The icon, ready for add_menu_page.
	 *
	 * @return string
	 */
	public static function data_uri(): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A data URI for an SVG, as WordPress's own menu icons are.
		return 'data:image/svg+xml;base64,' . base64_encode( self::svg( self::REST ) );
	}

	/**
	 * The lucide anvil as a silhouette, filled in one colour.
	 *
	 * @param string $fill A CSS colour.
	 * @return string
	 */
	public static function svg( string $fill ): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="' . $fill . '">'
			. '<path d="M7 10H6a4 4 0 0 1-4-4 1 1 0 0 1 1-1h4z"/>'
			. '<path d="M7 5a1 1 0 0 1 1-1h13a1 1 0 0 1 1 1 7 7 0 0 1-7 7H8a1 1 0 0 1-1-1z"/>'
			. '<path d="M8 12h2v5H8z"/><path d="M14 12h2v5h-2z"/>'
			. '<path d="M5 20a3 3 0 0 1 3-3h8a3 3 0 0 1 3 3 1 1 0 0 1-1 1H6a1 1 0 0 1-1-1z"/>'
			. '</svg>';
	}
}
