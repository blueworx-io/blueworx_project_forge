<?php
/**
 * The Forge menu icon, for the client plugin.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

/**
 * The same drawing the studio's menu carries, kept here as its own copy
 * because the client artifact cannot contain a studio file (ARCH-1): the
 * allowlist refuses any path outside client/, and widening the shared list
 * for one icon would be the wrong trade. When the drawing changes, it
 * changes in both places.
 *
 * Handed to WordPress as a data URI so it paints the icon like every other
 * plugin's, in the menu's own colours (#348).
 */
final class MenuIcon {

	/**
	 * The fill WordPress expects for an at-rest menu icon.
	 */
	private const REST = '#a7aaad';

	/**
	 * The icon as a data URI, for add_menu_page.
	 *
	 * @return string
	 */
	public static function data_uri(): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A data URI for an SVG, as WordPress's own menu icons are.
		return 'data:image/svg+xml;base64,' . base64_encode( self::svg( self::REST ) );
	}

	/**
	 * The anvil, as an SVG.
	 *
	 * @param string $fill Fill colour.
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
