<?php
/**
 * Saying how old what is on screen actually is.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

use Blueworx\Forge\Client\Connection;
use Blueworx\Forge\Client\Sync;

/**
 * The last-sync line every client screen carries.
 *
 * This is one class rather than one per screen because the wording is the
 * promise. Under ARCH-4 a client site keeps working while the studio is
 * unreachable by showing what it last saw, and that is only honest if the
 * screen says so — a cached record shown as though it were live is worse than
 * an error, because the person reading it has no way to tell. Four screens
 * phrasing that four ways would eventually have one of them phrase it as
 * nothing at all.
 */
final class SyncNotice {

	/**
	 * The nonce action behind every refresh link.
	 */
	public const NONCE = 'bwx-forge-client-refresh';

	/**
	 * Whether this request is asking for a fresh read.
	 *
	 * A refresh is a read, so this is a convenience rather than a state change
	 * — but it does cause a network call, so it still carries a nonce rather
	 * than being triggerable by any link anyone can put in front of an
	 * administrator.
	 *
	 * @return bool
	 */
	public static function refresh_requested(): bool {
		return isset( $_GET['bwx-refresh'] )
			&& isset( $_GET['_wpnonce'] )
			&& wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE );
	}

	/**
	 * Renders the notice.
	 *
	 * @param array<string, mixed> $sync The sync block from a read-through view.
	 * @param string               $slug The screen asking, so "check again"
	 *                                   comes back to where the reader was.
	 */
	public static function render( array $sync, string $slug ): void {
		$state      = (string) $sync['state'];
		$fetched_at = (int) $sync['fetched_at'];
		$ago        = $fetched_at > 0
			/* translators: %s: human-readable duration, e.g. "2 mins". */
			? sprintf( __( 'Last synced %s ago.', 'blueworx-forge' ), human_time_diff( $fetched_at ) )
			: __( 'Never synced.', 'blueworx-forge' );

		$class   = 'notice notice-info';
		$message = $ago;

		if ( Sync::STATE_STALE === $state ) {
			$class   = 'notice notice-warning';
			$message = __( 'The studio could not be reached, so this is the copy this site last saw. It may be out of date.', 'blueworx-forge' ) . ' ' . $ago;
		}

		if ( Sync::STATE_UNREACHABLE === $state ) {
			$class   = 'notice notice-error';
			$message = __( 'The studio could not be reached, and this site has nothing saved to show in the meantime.', 'blueworx-forge' );
		}

		if ( Sync::STATE_NOT_CONFIGURED === $state ) {
			$class   = 'notice notice-warning';
			$message = __( 'This site has not been connected to the studio yet.', 'blueworx-forge' );
		}

		printf(
			'<div class="%1$s" data-bwx-sync-state="%2$s"><p>%3$s %4$s</p></div>',
			esc_attr( $class ),
			esc_attr( $state ),
			esc_html( $message ),
			wp_kses_post( self::refresh_link( $slug ) )
		);
	}

	/**
	 * A link that asks the studio again, now.
	 *
	 * @param string $slug The screen to come back to.
	 * @return string
	 */
	private static function refresh_link( string $slug ): string {
		if ( ! Connection::is_configured() ) {
			return '';
		}

		$url = wp_nonce_url(
			add_query_arg( 'bwx-refresh', '1', admin_url( 'admin.php?page=' . $slug ) ),
			self::NONCE
		);

		return '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check again', 'blueworx-forge' ) . '</a>';
	}
}
