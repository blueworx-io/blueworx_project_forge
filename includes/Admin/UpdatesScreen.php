<?php
/**
 * The screen that says whether this site can fetch its own updates.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Updates;

/**
 * Whether updates can be fetched, and what the latest release is (#200, #340).
 *
 * Once this screen also took a token, because releases lived in a private
 * repository. The repository is public now (#340), so there is nothing to
 * set here — the screen only reports.
 */
final class UpdatesScreen {

	/**
	 * The submenu page slug.
	 */
	public const SLUG = 'blueworx-forge-updates';

	/**
	 * Adds the menu entry, under the Forge menu.
	 */
	public static function register(): void {
		add_submenu_page(
			SyncScreen::SLUG,
			__( 'Updates', 'blueworx-forge' ),
			__( 'Updates', 'blueworx-forge' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * This screen's URL.
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		Page::open(
			__( 'Updates', 'blueworx-forge' ),
			__( 'Forge', 'blueworx-forge' ),
			__( 'Forge updates itself from its public releases, the same way as any other plugin. Nothing needs setting up on this site.', 'blueworx-forge' )
		);

		self::render_status( Updates::status() );

		Page::panel_open( __( 'This site', 'blueworx-forge' ), 'this-site' );
		printf(
			'<p class="bw-card__note" data-bwx-installed="%1$s">%2$s</p>',
			esc_attr( BWX_FORGE_VERSION ),
			sprintf(
				/* translators: %s: the installed version, such as 2.126.0. */
				esc_html__( 'This site runs Forge %s. WordPress checks for a newer release twice a day and offers it under Plugins, where it installs like any other update.', 'blueworx-forge' ),
				'<strong>' . esc_html( BWX_FORGE_VERSION ) . '</strong>'
			)
		);
		Page::panel_close();

		Page::close();
	}

	/**
	 * Draws one status answer. Shared with the client plugin's own screen only
	 * by shape, not by code — the two plugins ship separately.
	 *
	 * @param array{state: string, message: string, release: string} $status The answer.
	 */
	private static function render_status( array $status ): void {
		$tone = 'ok' === $status['state'] ? 'success' : ( 'limited' === $status['state'] ? 'warning' : 'danger' );

		$text = esc_html( $status['message'] );

		if ( '' !== $status['release'] ) {
			$text .= ' ' . sprintf(
				/* translators: %s: the latest release tag, such as v2.31.0. */
				esc_html__( 'The latest release is %s.', 'blueworx-forge' ),
				'<strong data-bwx-latest-release="1">' . esc_html( $status['release'] ) . '</strong>'
			);
		}

		// Markup, because the release tag inside the sentence carries the hook
		// the spec reads. Everything interpolated is escaped above.
		Page::notice( $tone, $text, array( 'data-bwx-updates' => $status['state'] ), true );
	}
}
