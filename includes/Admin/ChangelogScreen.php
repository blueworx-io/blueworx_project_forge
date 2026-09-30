<?php
/**
 * Every release, and what changed in it.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

/**
 * The changelog, beside Updates (#466). It shows what this site has installed
 * and everything before it. Notes for a newer release stay where WordPress
 * shows them, in the update's View details box.
 */
final class ChangelogScreen {

	/**
	 * The submenu page slug.
	 */
	public const SLUG = 'blueworx-forge-changelog';

	/**
	 * Adds the menu entry, under the Forge menu.
	 */
	public static function register(): void {
		add_submenu_page(
			SyncScreen::SLUG,
			__( 'Changelog', 'blueworx-forge' ),
			__( 'Changelog', 'blueworx-forge' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		Page::open(
			__( 'Changelog', 'blueworx-forge' ),
			__( 'Forge', 'blueworx-forge' ),
			__( 'Every release of Forge, newest first, and what changed in it.', 'blueworx-forge' )
		);

		$releases = Changelog::read( BWX_FORGE_PATH . 'CHANGELOG.md' );

		if ( null === $releases || array() === $releases ) {
			Page::notice( 'info', __( 'The changelog is not included in this copy of Forge.', 'blueworx-forge' ), array( 'data-bwx-changelog' => 'missing' ) );
			Page::close();
			return;
		}

		foreach ( $releases as $release ) {
			self::render_release( $release );
		}

		Page::close();
	}

	/**
	 * One release as a panel, with the installed one marked.
	 *
	 * @param array{version: string, date: string, sections: list<array{heading: string, entries: list<string>}>} $release The release.
	 */
	private static function render_release( array $release ): void {
		$installed = BWX_FORGE_VERSION === $release['version'];
		$heading   = '' === $release['date'] ? $release['version'] : sprintf( '%1$s — %2$s', $release['version'], $release['date'] );
		$badge     = static function (): void {
			printf( '<span class="bw-badge bw-badge--success">%s</span>', esc_html__( 'Installed', 'blueworx-forge' ) );
		};

		printf(
			'<div data-bwx-release="%1$s"%2$s>',
			esc_attr( $release['version'] ),
			$installed ? ' data-bwx-installed-release="1"' : ''
		);
		Page::panel_open( $heading, 'release-' . $release['version'], null, $installed ? $badge : null );

		foreach ( $release['sections'] as $section ) {
			printf( '<h3 class="bw-card__eyebrow">%s</h3>', esc_html( $section['heading'] ) );
			echo '<ul class="bwx-changelog">';

			foreach ( $section['entries'] as $entry ) {
				echo '<li>' . Changelog::inline( $entry ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Changelog::inline(); only strong and code survive.
			}

			echo '</ul>';
		}

		Page::panel_close();
		echo '</div>';
	}
}
