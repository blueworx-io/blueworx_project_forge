<?php
/**
 * The client site's workspace screen.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

use Blueworx\Forge\Client\Workspace;

/**
 * One admin screen, showing the studio's record and how old it is.
 *
 * The last-sync line is not decoration. Under ARCH-4 a client site keeps
 * working while the studio is unreachable by showing what it last saw — which
 * is only honest if the screen says so. A cached record shown as though it were
 * live is worse than an error, because the person reading it has no way to tell.
 *
 * Deliberately plain PHP and plain markup. The studio app is React; a client
 * site gets a WordPress admin page that loads with the dashboard and needs no
 * build step of its own. The design token layer (#85) styles this later.
 */
final class Screen {

	/**
	 * The admin page slug.
	 */
	public const SLUG = 'blueworx-forge-client';

	/**
	 * Handle of the design token stylesheet.
	 */
	public const STYLE = 'blueworx-forge-tokens';

	/**
	 * Every screen this plugin owns.
	 *
	 * @return array<int, string>
	 */
	private static function slugs(): array {
		return array(
			self::SLUG,
			BoardScreen::SLUG,
			TimelineScreen::SLUG,
			CalendarScreen::SLUG,
			ConnectionScreen::SLUG,
		);
	}

	/**
	 * Whether a screen being loaded is one of ours.
	 *
	 * Matched on the hook rather than on the requested page, because the hook
	 * is WordPress telling us which screen it is about to render, and the
	 * request is whatever somebody typed.
	 *
	 * @param string $hook The screen being loaded.
	 * @return bool
	 */
	private static function ours( string $hook ): bool {
		foreach ( self::slugs() as $slug ) {
			if ( 'toplevel_page_' . $slug === $hook || str_ends_with( $hook, '_page_' . $slug ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Loads the design tokens, on this plugin's screens only.
	 *
	 * The tokens are shipped rather than compiled here: this artifact has no
	 * build step of its own, and the file it loads is the same one the studio's
	 * app compiles in (#85). One edit reaches both, and there is no second copy
	 * to forget.
	 *
	 * The work views' own rules ride along inline for the same reason they are
	 * not a file: what the client artifact may contain is a closed list, and the
	 * guarantee that list gives is worth more than a tidier stylesheet (#128).
	 *
	 * @param string $hook The screen being loaded.
	 */
	public static function enqueue( string $hook ): void {
		if ( ! self::ours( $hook ) ) {
			return;
		}

		$tokens = BWX_FORGE_CLIENT_PATH . 'tokens/forge.css';

		if ( ! file_exists( $tokens ) ) {
			return;
		}

		wp_enqueue_style(
			self::STYLE,
			BWX_FORGE_CLIENT_URL . 'tokens/forge.css',
			array(),
			(string) filemtime( $tokens )
		);

		wp_add_inline_style( self::STYLE, Styles::css() );
	}

	/**
	 * Adds the menu entry.
	 */
	public static function register(): void {
		add_menu_page(
			__( 'Forge', 'blueworx-forge' ),
			__( 'Forge', 'blueworx-forge' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' ),
			'dashicons-hammer',
			58
		);
	}

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$view = Workspace::view( SyncNotice::refresh_requested() );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Forge', 'blueworx-forge' ) . '</h1>';

		Nav::render( self::SLUG, $view );

		SyncNotice::render( $view['sync'], self::SLUG );

		if ( null === $view['record'] ) {
			self::empty_state( (string) $view['sync']['state'] );
		} else {
			self::record( (array) $view['record'] );
		}

		echo '</div>';
	}

	/**
	 * The workspace record.
	 *
	 * @param array<string, mixed> $record The studio's record for this site.
	 */
	private static function record( array $record ): void {
		$connected = (int) ( $record['connected_since'] ?? 0 );

		echo '<table class="widefat striped" data-bwx-workspace="1"><tbody>';

		self::row( __( 'Site', 'blueworx-forge' ), (string) ( $record['name'] ?? '' ) );
		self::row( __( 'Address', 'blueworx-forge' ), (string) ( $record['url'] ?? '' ) );
		self::row( __( 'Status', 'blueworx-forge' ), (string) ( $record['status'] ?? '' ) );
		self::row(
			__( 'Connected since', 'blueworx-forge' ),
			$connected > 0 ? gmdate( 'j F Y', $connected ) : ''
		);

		echo '</tbody></table>';

		echo '<p class="description">' . esc_html__( 'These details are held by the studio. This site shows them; it does not keep them.', 'blueworx-forge' ) . '</p>';
	}

	/**
	 * One label-and-value row.
	 *
	 * @param string $label Row label.
	 * @param string $value Row value.
	 */
	private static function row( string $label, string $value ): void {
		printf(
			'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
			esc_html( $label ),
			esc_html( $value )
		);
	}

	/**
	 * What to show when there is no record.
	 *
	 * Never an empty workspace: "you have nothing" and "we cannot see your
	 * things right now" are different sentences, and only one of them is true.
	 *
	 * @param string $state One of Workspace's STATE_ constants.
	 */
	private static function empty_state( string $state ): void {
		$message = Workspace::STATE_NOT_CONFIGURED === $state
			? __( 'Once this site is connected, its workspace appears here.', 'blueworx-forge' )
			: __( 'Nothing can be shown until the studio can be reached again.', 'blueworx-forge' );

		echo '<p data-bwx-empty="1">' . esc_html( $message ) . '</p>';
	}
}
