<?php
/**
 * One piece of work, as a client sees it.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

/**
 * The card all three client views draw (#128).
 *
 * There is one of these rather than one per view so that a client cannot see
 * more of an item on the calendar than on the board. What a client may see is
 * decided once, in the studio's projection, and drawn once, here.
 *
 * Nothing on this card is a control. Since #133 the title is a link, and it is
 * worth being precise about why that is still true: it opens a page that reads
 * the same item and offers somewhere to write a comment. There is no select, no
 * button that changes where the work sits, and no code on this artifact that
 * could render one — which is what #128 asks for and what the artifact check
 * enforces.
 */
final class Card {

	/**
	 * The dates a card shows, and what each is called.
	 *
	 * @return array<string, string>
	 */
	private static function date_labels(): array {
		return array(
			'planned_start'  => __( 'Starts', 'blueworx-forge' ),
			'planned_due'    => __( 'Due', 'blueworx-forge' ),
			'review_target'  => __( 'Review', 'blueworx-forge' ),
			'release_target' => __( 'Release', 'blueworx-forge' ),
		);
	}

	/**
	 * The seats a card names, and what each is called.
	 *
	 * @return array<string, string>
	 */
	private static function seat_labels(): array {
		return array(
			'primary'   => __( 'Working on it', 'blueworx-forge' ),
			'reviewer'  => __( 'Reviewing', 'blueworx-forge' ),
			'deliverer' => __( 'Releasing', 'blueworx-forge' ),
		);
	}

	/**
	 * Renders one card.
	 *
	 * @param array<string, mixed> $item        A board item.
	 * @param bool                 $with_stage  Whether to name the stage. The
	 *                                          board puts items in columns that
	 *                                          already say it.
	 * @param bool                 $linked      Whether the title opens the item.
	 *                                          False on the item's own page,
	 *                                          where a link back to where the
	 *                                          reader already is is noise.
	 */
	public static function render( array $item, bool $with_stage = true, bool $linked = true ): void {
		$id = (string) ( $item['id'] ?? '' );

		// The anchor stays whether or not the title is a link. "What you asked
		// for" points at the work a request became, and it points at the board
		// (#130) — a card that only had a link would leave that pointing at
		// nothing.
		printf(
			'<article class="bwx-card" data-testid="bwx-card" id="bwx-item-%1$s" data-bwx-item="%1$s">',
			esc_attr( $id )
		);

		$title = (string) ( $item['title'] ?? '' );

		if ( $linked && '' !== $id ) {
			printf(
				'<h3 class="bwx-card-title" data-testid="bwx-card-title"><a href="%s">%s</a></h3>',
				esc_url( ItemScreen::url( $id ) ),
				esc_html( $title )
			);
		} else {
			printf(
				'<h3 class="bwx-card-title" data-testid="bwx-card-title">%s</h3>',
				esc_html( $title )
			);
		}

		if ( $with_stage ) {
			printf(
				'<p class="bwx-card-stage" data-testid="bwx-card-stage">%s</p>',
				esc_html( (string) ( $item['stage_label'] ?? '' ) )
			);
		}

		self::dates( $item );
		self::people( $item );

		echo '</article>';
	}

	/**
	 * The dates an item has, and only those it has.
	 *
	 * A date nobody has set is left out rather than shown empty: a blank next to
	 * "Due" reads as a deadline somebody forgot to type, when the truth is that
	 * work this early does not have one yet (WORK-3).
	 *
	 * @param array<string, mixed> $item A board item.
	 */
	private static function dates( array $item ): void {
		$rows = array();

		foreach ( self::date_labels() as $field => $label ) {
			$date = (string) ( $item[ $field ] ?? '' );

			if ( '' !== $date ) {
				$rows[] = array( $label, $date );
			}
		}

		if ( array() === $rows ) {
			return;
		}

		echo '<ul class="bwx-card-dates">';

		foreach ( $rows as $row ) {
			printf(
				'<li><span class="bwx-card-key">%1$s</span> <span class="bwx-card-value">%2$s</span></li>',
				esc_html( $row[0] ),
				esc_html( self::day( $row[1] ) )
			);
		}

		echo '</ul>';
	}

	/**
	 * Who is on it, where anybody is.
	 *
	 * An empty seat is left out rather than shown as nobody. "Reviewing: —" on
	 * work that has not reached review is noise dressed as information.
	 *
	 * @param array<string, mixed> $item A board item.
	 */
	private static function people( array $item ): void {
		$people = (array) ( $item['people'] ?? array() );
		$rows   = array();

		foreach ( self::seat_labels() as $seat => $label ) {
			$name = (string) ( $people[ $seat ]['display_name'] ?? '' );

			if ( '' !== $name ) {
				$rows[] = array( $label, $name );
			}
		}

		if ( array() === $rows ) {
			return;
		}

		echo '<ul class="bwx-card-people" data-testid="bwx-card-people">';

		foreach ( $rows as $row ) {
			printf(
				'<li><span class="bwx-card-key">%1$s</span> <span class="bwx-card-value">%2$s</span></li>',
				esc_html( $row[0] ),
				esc_html( $row[1] )
			);
		}

		echo '</ul>';
	}

	/**
	 * A stored date as this site's people write dates.
	 *
	 * @param string $date YYYY-MM-DD.
	 * @return string
	 */
	public static function day( string $date ): string {
		$stamp = strtotime( $date . ' 00:00:00 UTC' );

		return false === $stamp ? $date : date_i18n( (string) get_option( 'date_format', 'j F Y' ), $stamp );
	}
}
