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
 *
 * Drawn from the shared design system (docs #ARCH-...): the card shell is
 * `bw-card`, the stage is a `bw-badge` toned by what the stage means rather
 * than at random, and each seat is a `bw-chip` — read-only reference data, not
 * a value anybody here could change. The date line has no dedicated component
 * of its own in the system; `bw-fieldnote` (a small muted note, optionally with
 * an icon) is the closest honest fit and is used for it.
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
	 * The badge tone a stage reads as, chosen for what the stage means rather
	 * than assigned in list order.
	 *
	 * Blocked (Work\Stages::EXCEPTION) is the one thing on a board that needs
	 * attention, so it is the one danger tone. Bug tracking is conditional and
	 * itself a signal something is wrong, so it warns. Completed and released
	 * are both finished, but only released is live on the client's own site
	 * (NOTIF-2) — completed is "ready", released is "done" — so completed reads
	 * as info and released as success. In development and in review are the
	 * stages work actually moves through, so they carry the brand accent.
	 * Everything earlier than that — still being planned rather than built — is
	 * left neutral.
	 *
	 * @param string $stage A stage slug from Work\Stages::ALL.
	 * @return string One of the bw-badge tones.
	 */
	private static function stage_tone( string $stage ): string {
		switch ( $stage ) {
			case 'blocked':
				return 'danger';
			case 'bug-tracking':
				return 'warning';
			case 'in-development':
			case 'in-review':
				return 'accent';
			case 'completed':
				return 'info';
			case 'released':
				return 'success';
			default:
				return 'neutral';
		}
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
			'<article class="bw-card" data-testid="bwx-card" id="bwx-item-%1$s" data-bwx-item="%1$s">',
			esc_attr( $id )
		);

		echo '<div class="bw-card__head"><div class="bw-card__titles">';

		$title = (string) ( $item['title'] ?? '' );

		if ( $linked && '' !== $id ) {
			printf(
				'<h3 class="bw-card__title" data-testid="bwx-card-title"><a href="%s">%s</a></h3>',
				esc_url( ItemScreen::url( $id ) ),
				esc_html( $title )
			);
		} else {
			printf(
				'<h3 class="bw-card__title" data-testid="bwx-card-title">%s</h3>',
				esc_html( $title )
			);
		}

		echo '</div>';

		if ( $with_stage ) {
			printf(
				'<div class="bw-card__actions"><span class="bw-badge bw-badge--%1$s" data-testid="bwx-card-stage">%2$s</span></div>',
				esc_attr( self::stage_tone( (string) ( $item['stage'] ?? '' ) ) ),
				esc_html( (string) ( $item['stage_label'] ?? '' ) )
			);
		}

		echo '</div>';

		/*
		 * Built first, printed only if there is anything in it. A board column
		 * of work nobody has dated or staffed yet was drawing an empty ruled
		 * box under every title — which reads as a card that failed to load
		 * rather than as work that has not been scheduled.
		 */
		ob_start();

		self::classification( $item );
		self::dates( $item );
		self::people( $item );

		$body = (string) ob_get_clean();

		if ( '' !== trim( $body ) ) {
			echo '<div class="bw-card__body">';
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Assembled above from the same escaped printers this file has always used.
			echo '</div>';
		}

		echo '</article>';
	}

	/**
	 * What kind of work this is, and how big a piece of it.
	 *
	 * The two facts a card was missing (#287). A title on its own says what
	 * somebody called the work; it does not say whether this is a fix or a new
	 * feature, and that is usually the first thing a client wants to know —
	 * "is this the bug I reported, or the thing I asked for?"
	 *
	 * Both arrive from the studio already in words. This does not translate
	 * them, and must not: the vocabulary belongs to one end (ARCH-6), and a
	 * client site with its own copy of it starts lying the day we rename a
	 * stage.
	 *
	 * @param array<string, mixed> $item A board item.
	 */
	private static function classification( array $item ): void {
		$type  = (string) ( $item['work_type_label'] ?? '' );
		$level = (string) ( $item['level_label'] ?? '' );

		if ( '' === $type && '' === $level ) {
			return;
		}

		echo '<p class="bwx-card-class" data-testid="bwx-card-class">';

		if ( '' !== $type ) {
			printf(
				'<span class="bw-badge bw-badge--%1$s" data-bwx-card-type="%2$s">%3$s</span>',
				esc_attr( self::type_tone( (string) ( $item['work_type'] ?? '' ) ) ),
				esc_attr( (string) ( $item['work_type'] ?? '' ) ),
				esc_html( $type )
			);
		}

		if ( '' !== $level ) {
			printf( '<span data-bwx-card-level="%1$s">%2$s</span>', esc_attr( (string) ( $item['level'] ?? '' ) ), esc_html( $level ) );
		}

		echo '</p>';
	}

	/**
	 * The badge tone a work type reads as.
	 *
	 * A bug is the one kind of work that is somebody's problem right now, so it
	 * is the one that is coloured. Everything else is a category, not a signal,
	 * and a card where four things compete for attention draws attention to
	 * none of them.
	 *
	 * @param string $type A work type slug.
	 * @return string One of the bw-badge tones.
	 */
	private static function type_tone( string $type ): string {
		return 'bug' === $type ? 'warning' : 'neutral';
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
		foreach ( self::date_labels() as $field => $label ) {
			$date = (string) ( $item[ $field ] ?? '' );

			if ( '' === $date ) {
				continue;
			}

			printf(
				'<p class="bw-fieldnote"><i class="bw-icon" data-lucide="calendar"></i>%1$s %2$s</p>',
				esc_html( $label ),
				esc_html( self::day( $date ) )
			);
		}
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

		echo '<div class="bw-chips" data-testid="bwx-card-people">';

		foreach ( $rows as $row ) {
			printf(
				'<span class="bw-chip bw-chip--plain">%1$s: %2$s</span>',
				esc_html( $row[0] ),
				esc_html( $row[1] )
			);
		}

		echo '</div>';
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
