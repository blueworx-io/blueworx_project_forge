<?php
/**
 * The client site's workspace screen.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

use Blueworx\Forge\Client\Connection;
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

		// A refresh is a read, so this is a convenience rather than a state
		// change — but it does cause a network call, so it still carries a nonce
		// rather than being triggerable by any link anyone can put in front of an
		// administrator.
		$refresh = isset( $_GET['bwx-refresh'] )
			&& isset( $_GET['_wpnonce'] )
			&& wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'bwx-forge-client-refresh' );

		$view = Workspace::view( (bool) $refresh );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Forge', 'blueworx-forge' ) . '</h1>';

		self::sync_notice( $view['sync'] );

		if ( null === $view['record'] ) {
			self::empty_state( (string) $view['sync']['state'] );
		} else {
			self::record( (array) $view['record'] );
		}

		echo '</div>';
	}

	/**
	 * The last-sync line, and a warning when what is shown may be out of date.
	 *
	 * @param array<string, mixed> $sync The sync block from Workspace::view().
	 */
	private static function sync_notice( array $sync ): void {
		$state      = (string) $sync['state'];
		$fetched_at = (int) $sync['fetched_at'];
		$ago        = $fetched_at > 0
			/* translators: %s: human-readable duration, e.g. "2 mins". */
			? sprintf( __( 'Last synced %s ago.', 'blueworx-forge' ), human_time_diff( $fetched_at ) )
			: __( 'Never synced.', 'blueworx-forge' );

		$class   = 'notice notice-info';
		$message = $ago;

		if ( Workspace::STATE_STALE === $state ) {
			$class   = 'notice notice-warning';
			$message = __( 'The studio could not be reached, so this is the copy this site last saw. It may be out of date.', 'blueworx-forge' ) . ' ' . $ago;
		}

		if ( Workspace::STATE_UNREACHABLE === $state ) {
			$class   = 'notice notice-error';
			$message = __( 'The studio could not be reached, and this site has nothing saved to show in the meantime.', 'blueworx-forge' );
		}

		if ( Workspace::STATE_NOT_CONFIGURED === $state ) {
			$class   = 'notice notice-warning';
			$message = __( 'This site has not been connected to the studio yet.', 'blueworx-forge' );
		}

		printf(
			'<div class="%1$s" data-bwx-sync-state="%2$s"><p>%3$s %4$s</p></div>',
			esc_attr( $class ),
			esc_attr( $state ),
			esc_html( $message ),
			wp_kses_post( self::refresh_link() )
		);
	}

	/**
	 * The "check again" link.
	 *
	 * @return string
	 */
	private static function refresh_link(): string {
		if ( ! Connection::is_configured() ) {
			return '';
		}

		$url = wp_nonce_url(
			add_query_arg( 'bwx-refresh', '1', admin_url( 'admin.php?page=' . self::SLUG ) ),
			'bwx-forge-client-refresh'
		);

		return '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check again', 'blueworx-forge' ) . '</a>';
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
