<?php
/**
 * The client workspace frame.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

/**
 * The text for whose workspace a client screen is showing (#126).
 *
 * There used to be a tab strip here as well, repeating the pages that the
 * WordPress admin menu already lists down the side. One navigation is enough,
 * and the side menu is the one people expect on a WordPress screen, so the
 * strip is gone and every page is still one click away.
 *
 * The scope text is not a filter and there is no control to change it. A
 * client site holds one site id and one signing key issued by the studio,
 * and every read is answered for whoever signed it — so this artifact has
 * no credential for another client and nothing typed into it can invent one.
 *
 * Saying whose workspace it is matters for the ordinary case rather than the
 * hostile one. Somebody administering several client sites has several tabs
 * open that look alike, and needs the screen to say which is which before they
 * act on it.
 */
final class Nav {

	/**
	 * The text for whose workspace this is — the page header's eyebrow.
	 *
	 * Takes the workspace record rather than reading one: the caller has
	 * already read it, and a second read would be a second chance for the
	 * eyebrow and the rest of the screen to disagree about how old what they
	 * are showing is.
	 *
	 * @param array<string, mixed> $view The workspace as Workspace::view saw it.
	 */
	public static function scope_text( array $view ): string {
		$name = (string) ( $view['record']['name'] ?? '' );

		if ( '' === $name ) {
			// Not a failure to name the client — a workspace nobody has
			// connected yet, which is worth saying plainly.
			return __( 'Not connected to a studio yet', 'blueworx-forge' );
		}

		return __( 'Workspace for', 'blueworx-forge' ) . ' ' . $name;
	}
}
