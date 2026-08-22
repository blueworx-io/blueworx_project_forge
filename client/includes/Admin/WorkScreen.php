<?php
/**
 * The frame the three work views share.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

use Blueworx\Forge\Client\Board;
use Blueworx\Forge\Client\Sync;

/**
 * Everything the board, timeline and calendar do identically (#128).
 *
 * The three differ only in how they arrange the same items. Reading the board,
 * saying how old it is, and saying so honestly when there is nothing to show
 * are the same job three times, and the last of those is the one that must not
 * be got wrong in one view and right in the other two.
 */
final class WorkScreen {

	/**
	 * Renders one work view.
	 *
	 * @param string   $slug    The screen's page slug.
	 * @param string   $heading The page heading.
	 * @param callable $draw    Given the board view, draws the arrangement.
	 */
	public static function render( string $slug, string $heading, callable $draw ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$view = Board::view( SyncNotice::refresh_requested() );

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html( $heading ) );

		Nav::render( $slug );

		SyncNotice::render( $view['sync'], $slug );

		echo '<div class="bwx-work">';

		if ( ! $view['ok'] ) {
			self::nothing_to_show( (string) $view['sync']['state'] );
		} else {
			$draw( $view );
		}

		echo '</div></div>';
	}

	/**
	 * What to show when there is no board.
	 *
	 * Never empty columns. "You have no work" and "we cannot see your work
	 * right now" are different sentences, and drawing the first when the second
	 * is true tells a client their work has been deleted.
	 *
	 * @param string $state One of the Sync STATE_ constants.
	 */
	private static function nothing_to_show( string $state ): void {
		if ( Sync::STATE_NOT_CONFIGURED === $state ) {
			$message = __( 'Once this site is connected to the studio, your work appears here.', 'blueworx-forge' );
		} else {
			$message = __( 'Your work cannot be shown until the studio can be reached again. Nothing has been lost.', 'blueworx-forge' );
		}

		printf( '<p class="bwx-empty" data-bwx-empty="1">%s</p>', esc_html( $message ) );
	}

	/**
	 * A short line for a view that has a board but nothing dated to draw on it.
	 *
	 * @param string $message What to say.
	 */
	public static function note( string $message ): void {
		printf( '<p class="bwx-empty" data-testid="bwx-note">%s</p>', esc_html( $message ) );
	}

	/**
	 * The work carrying no dates at all, listed rather than dropped (#120).
	 *
	 * @param array<int, array<string, mixed>> $items Undated items.
	 */
	public static function undated( array $items ): void {
		if ( array() === $items ) {
			return;
		}

		echo '<section class="bwx-undated" data-testid="bwx-undated">';
		printf( '<h2>%s</h2>', esc_html__( 'No dates yet', 'blueworx-forge' ) );
		printf(
			'<p class="bwx-empty">%s</p>',
			esc_html__( 'This work is real and is being tracked. It has no dates on it yet, so it cannot be placed.', 'blueworx-forge' )
		);

		foreach ( $items as $item ) {
			Card::render( $item );
		}

		echo '</section>';
	}
}
