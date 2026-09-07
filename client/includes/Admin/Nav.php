<?php
/**
 * The client workspace frame.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

/**
 * The tab strip every client screen shows, and the text for whose
 * workspace it is (#126).
 *
 * The scope text is not a filter and there is no control to change it. A
 * client site holds one site id and one signing key issued by the studio,
 * and every read is answered for whoever signed it — so this artifact has
 * no credential for another client and nothing typed into it can invent
 * one. That is why the nav links below carry a page and nothing else: a
 * navigation that can name a client is one somebody can edit to name a
 * different client, and the safest parameter is the one that was never
 * there.
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
				'slug'  => ChecklistScreen::SLUG,
				'label' => __( 'Getting you live', 'blueworx-forge' ),
			),
			array(
				'slug'  => SalesScreen::SLUG,
				'label' => __( 'Your hours', 'blueworx-forge' ),
			),
			array(
				'slug'  => AskScreen::SLUG,
				'label' => __( 'Ask for something', 'blueworx-forge' ),
			),
			array(
				'slug'  => AskedScreen::SLUG,
				'label' => __( 'What you asked for', 'blueworx-forge' ),
			),
			array(
				'slug'  => ConnectionScreen::SLUG,
				'label' => __( 'Connection', 'blueworx-forge' ),
			),
		);
	}

	/**
	 * The text for whose workspace this is — the page header's eyebrow.
	 *
	 * Takes the workspace record rather than reading one, for the same reason
	 * render() used to: the caller has already read it, and a second read
	 * would be a second chance for the eyebrow and the rest of the screen to
	 * disagree about how old what they are showing is.
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

	/**
	 * Renders the pages within the frame, current one marked.
	 *
	 * Appearance from the design system's tabs; semantics from what this
	 * actually is. The system's Tabs component renders role="tablist" with
	 * role="tab" buttons, which is right for switching panels inside one
	 * screen and wrong here — these are links to separate admin pages, and
	 * a tablist tells a screen reader to expect panels that never arrive.
	 * So: a <nav> with aria-current, wearing bw-tabs. The stylesheet
	 * supports it — .bw-tab already sets text-decoration:none, so it is
	 * built to dress an <a> as well as a <button>.
	 *
	 * @param string $current The slug of the screen being rendered.
	 */
	public static function render( string $current ): void {
		printf(
			'<nav class="bw-tabs" data-testid="bwx-client-nav" aria-label="%s">',
			esc_attr__( 'Workspace', 'blueworx-forge' )
		);

		// A page and nothing else in each href. Nothing here names a client or a
		// site, so there is nothing here to edit into somebody else's.
		foreach ( self::pages() as $page ) {
			$current_page = $page['slug'] === $current;

			printf(
				'<a class="bw-tab%s" data-testid="bwx-client-nav-item" href="%s"%s>%s</a>',
				$current_page ? ' is-active' : '',
				esc_url( admin_url( 'admin.php?page=' . $page['slug'] ) ),
				$current_page ? ' aria-current="page"' : '',
				esc_html( $page['label'] )
			);
		}

		echo '</nav>';
	}
}
