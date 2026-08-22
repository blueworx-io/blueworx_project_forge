<?php
/**
 * The client's timeline.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

use Blueworx\Forge\Client\Layout;

/**
 * When the work is happening, as a chart nobody can drag (#128).
 *
 * A bar and a date, and no handle to move either. The studio's chart lets a
 * person change a plan by dragging it; there is no such control here, and no
 * code in this artifact that could draw one.
 *
 * Work with no dates is listed underneath rather than left off. A client
 * looking at a timeline naturally reads it as everything they have, so work
 * that cannot be placed has to be visible somewhere on the same screen or the
 * chart is quietly lying about how much is going on.
 */
final class TimelineScreen {

	/**
	 * The submenu page slug.
	 */
	public const SLUG = 'blueworx-forge-client-timeline';

	/**
	 * Adds the menu entry.
	 */
	public static function register(): void {
		add_submenu_page(
			Screen::SLUG,
			__( 'Timeline', 'blueworx-forge' ),
			__( 'Timeline', 'blueworx-forge' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		WorkScreen::render(
			self::SLUG,
			__( 'Forge — timeline', 'blueworx-forge' ),
			array( self::class, 'chart' )
		);
	}

	/**
	 * Draws the chart.
	 *
	 * @param array<string, mixed> $view The board as this site can see it.
	 */
	public static function chart( array $view ): void {
		$items = (array) $view['items'];
		$axis  = Layout::axis( $items );

		if ( array() === $axis ) {
			WorkScreen::note( __( 'Nothing has dates on it yet, so there is nothing to place on a timeline.', 'blueworx-forge' ) );
			WorkScreen::undated( Layout::undated( $items ) );

			return;
		}

		echo '<div class="bwx-timeline" data-testid="bwx-timeline">';

		printf(
			'<p class="bwx-timeline-scale"><span>%1$s</span><span>%2$s</span></p>',
			esc_html( Card::day( (string) $axis['from'] ) ),
			esc_html( Card::day( (string) $axis['to'] ) )
		);

		foreach ( $items as $item ) {
			self::row( $item, $axis );
		}

		echo '</div>';

		WorkScreen::undated( Layout::undated( $items ) );
	}

	/**
	 * One row: what it is, and when.
	 *
	 * @param array<string, mixed> $item A board item.
	 * @param array<string, mixed> $axis The axis as Layout::axis built it.
	 */
	private static function row( array $item, array $axis ): void {
		$place = Layout::place( $item, $axis );

		if ( array() === $place ) {
			return;
		}

		printf(
			'<div class="bwx-timeline-row" data-testid="bwx-timeline-row" data-bwx-item="%s">',
			esc_attr( (string) ( $item['id'] ?? '' ) )
		);

		printf(
			'<div class="bwx-timeline-label">%1$s <span class="bwx-card-key">%2$s</span></div>',
			esc_html( (string) ( $item['title'] ?? '' ) ),
			esc_html( (string) ( $item['stage_label'] ?? '' ) )
		);

		printf(
			'<div class="bwx-timeline-track"><span class="bwx-timeline-bar" style="left:%1$s%%;width:%2$s%%" title="%3$s"></span>%4$s</div>',
			esc_attr( (string) $place['left'] ),
			esc_attr( (string) $place['width'] ),
			esc_attr( self::span_label( $item ) ),
			wp_kses_post( self::today_marker( $axis ) )
		);

		echo '</div>';
	}

	/**
	 * The dates a bar covers, for the title attribute.
	 *
	 * @param array<string, mixed> $item A board item.
	 * @return string
	 */
	private static function span_label( array $item ): string {
		$start = (string) ( $item['planned_start'] ?? '' );
		$due   = (string) ( $item['planned_due'] ?? '' );

		if ( '' !== $start && '' !== $due ) {
			/* translators: 1: start date, 2: due date. */
			return sprintf( __( '%1$s to %2$s', 'blueworx-forge' ), Card::day( $start ), Card::day( $due ) );
		}

		return Card::day( '' === $start ? $due : $start );
	}

	/**
	 * Today, where today is on the chart at all.
	 *
	 * @param array<string, mixed> $axis The axis as Layout::axis built it.
	 * @return string
	 */
	private static function today_marker( array $axis ): string {
		$today = gmdate( 'Y-m-d' );

		if ( $today < (string) $axis['from'] || $today > (string) $axis['to'] ) {
			return '';
		}

		$place = Layout::place(
			array(
				'planned_start' => $today,
				'planned_due'   => $today,
			),
			$axis
		);

		return sprintf(
			'<span class="bwx-timeline-today" style="left:%s%%" aria-hidden="true"></span>',
			esc_attr( (string) $place['left'] )
		);
	}
}
