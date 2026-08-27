<?php
/**
 * The client's board.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

/**
 * The same work the studio sees, in the same columns, with nothing to move it
 * with (#128).
 *
 * The columns are the studio's stage list as it arrived with the board. Nothing
 * here decides what a stage is called or which stages exist — a client artifact
 * holding its own copy of the state machine would be a copy that goes wrong the
 * day a stage changes, and goes wrong without anybody noticing.
 *
 * A column with nothing in it is drawn anyway. An empty To Do says something
 * true about the shape of the work; a column that vanishes when it empties
 * makes the board silently change shape week to week.
 */
final class BoardScreen {

	/**
	 * The submenu page slug.
	 */
	public const SLUG = 'blueworx-forge-client-board';

	/**
	 * Adds the menu entry.
	 */
	public static function register(): void {
		add_submenu_page(
			Screen::SLUG,
			__( 'Board', 'blueworx-forge' ),
			__( 'Board', 'blueworx-forge' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Renders the screen, or one card of it.
	 *
	 * The item view is a query argument on this page rather than a page of its
	 * own (#133). A screen about one record cannot have a standing menu entry —
	 * it would point at whichever card somebody last opened — and WordPress's
	 * two ways of registering a page that is not in the menu are a deprecated
	 * null parent and a `remove_submenu_page` that takes the capability check
	 * with it. Dispatching here needs neither, and it is also what actually
	 * happens: clicking a card takes you to the board, zoomed in.
	 */
	public static function render(): void {
		$item = ItemScreen::requested();

		if ( '' !== $item ) {
			ItemScreen::render( $item );

			return;
		}

		WorkScreen::render(
			self::SLUG,
			__( 'Forge — board', 'blueworx-forge' ),
			array( self::class, 'columns' )
		);
	}

	/**
	 * Draws the columns.
	 *
	 * @param array<string, mixed> $view The board as this site can see it.
	 */
	public static function columns( array $view ): void {
		$grouped = self::by_stage( (array) $view['items'] );

		echo '<div class="bwx-columns" data-testid="bwx-columns">';

		foreach ( (array) $view['stages'] as $stage ) {
			$slug = (string) ( $stage['slug'] ?? '' );

			self::column(
				(string) ( $stage['label'] ?? $slug ),
				$slug,
				$grouped[ $slug ] ?? array()
			);

			unset( $grouped[ $slug ] );
		}

		// Anything left is in a stage the studio did not list. It is still this
		// client's work, so it gets a column of its own rather than quietly not
		// being drawn — work disappearing off a board is the one failure a
		// client would never think to report.
		foreach ( $grouped as $slug => $items ) {
			self::column( (string) ( $items[0]['stage_label'] ?? $slug ), (string) $slug, $items );
		}

		echo '</div>';
	}

	/**
	 * One column.
	 *
	 * @param string                           $label The stage's name.
	 * @param string                           $slug  The stage's slug.
	 * @param array<int, array<string, mixed>> $items The work in it.
	 */
	private static function column( string $label, string $slug, array $items ): void {
		printf(
			'<section class="bwx-column" data-testid="bwx-column" data-bwx-stage="%s">',
			esc_attr( $slug )
		);

		printf(
			'<h2 class="bwx-column-head"><span>%1$s</span> <span class="bwx-column-count">%2$s</span></h2>',
			esc_html( $label ),
			esc_html( (string) count( $items ) )
		);

		if ( array() === $items ) {
			printf( '<p class="bwx-empty">%s</p>', esc_html__( 'Nothing here', 'blueworx-forge' ) );
		}

		foreach ( $items as $item ) {
			Card::render( $item, false );
		}

		echo '</section>';
	}

	/**
	 * The items, gathered by the stage they are in.
	 *
	 * @param array<int, array<string, mixed>> $items Board items.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function by_stage( array $items ): array {
		$grouped = array();

		foreach ( $items as $item ) {
			$grouped[ (string) ( $item['stage'] ?? '' ) ][] = $item;
		}

		return $grouped;
	}
}
