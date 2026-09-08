<?php
namespace Blueworx\PageEditor\v1;

/**
 * The one place a plugin gets a wp-admin menu icon from.
 *
 * Everywhere else in the system an icon is `<i class="bw-icon" data-lucide="…">`
 * and the browser draws it. The top-level admin menu cannot work that way:
 * add_menu_page() takes an image, WordPress renders that menu itself before any
 * of our JavaScript runs, and it paints the icon as a CSS background. So this is
 * the one icon that has to exist as SVG source in PHP.
 *
 * That means the geometry is written twice — here and in lucide-icons.js — which
 * is exactly the kind of duplication that drifts. It is held together by a test
 * (scripts/lib/menu-icons.test.mjs) that reads both files and fails if a name in
 * MENU_ICONS has different geometry from the same name in the icon map, or is
 * missing from it. Add a menu icon here only after adding it there.
 *
 * A background image cannot inherit a colour, so the icon is one fixed grey —
 * WordPress's own default menu icon colour. It will not brighten on hover or on
 * the current item the way a dashicon does. That is the trade for carrying the
 * brand's own icon in the menu, and it is a deliberate choice, not an oversight.
 */
final class Icons {

	/**
	 * WordPress's default admin menu icon colour, in the Fresh scheme.
	 */
	const MENU_COLOUR = '#a7aaad';

	/**
	 * Menu-safe icons, geometry verbatim from the design system's icon map.
	 *
	 * Deliberately short. Every entry is a duplicate of something in
	 * lucide-icons.js, so this list grows only when a plugin genuinely needs a
	 * top-level menu icon.
	 */
	const MENU_ICONS = [
		'playing-cards-fan' => '<path d="M12.65 7.65a2 2 0 012.629-1.046l5.51 2.374a2 2 0 011.046 2.628l-3.957 9.184a2 2 0 01-2.628 1.046l-5.51-2.374a2 2 0 01-1.046-2.628z"/><path d="M18 7.777V4a2 2 0 00-2-2h-6a2 2 0 00-2 2v10a2 2 0 001.137 1.805"/><path d="m8 4.389-4.364.809a2 2 0 00-1.602 2.33l1.822 9.833a2 2 0 002.331 1.602l2.542-.47"/>',
		'layout-dashboard'  => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
		'package'           => '<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><polyline points="3.29 7 12 12 20.71 7"/><path d="m7.5 4.27 9 5.15"/>',
		'library'           => '<path d="m16 6 4 14"/><path d="M12 6v14"/><path d="M8 8v12"/><path d="M4 4v16"/>',
	];

	/**
	 * A menu icon as the data URI add_menu_page() wants.
	 *
	 * Answers WordPress's own 'dashicons-admin-generic' for a name this does
	 * not carry, so a typo degrades to a real icon rather than to a blank
	 * square in the admin menu.
	 *
	 * @param string $name Icon name, as in the design system's icon map.
	 * @return string
	 */
	public static function menu( string $name ): string {
		$geometry = self::MENU_ICONS[ $name ] ?? '';
		if ( '' === $geometry ) {
			return 'dashicons-admin-generic';
		}

		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"'
			. ' fill="none" stroke="' . self::MENU_COLOUR . '" stroke-width="2"'
			. ' stroke-linecap="round" stroke-linejoin="round">' . $geometry . '</svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
