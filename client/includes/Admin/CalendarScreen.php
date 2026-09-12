<?php
/**
 * The client's calendar.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

use Blueworx\Forge\Client\Layout;

/**
 * Every date the work carries, on the month it falls in (#128).
 *
 * A month at a time, and only a month: the studio's calendar offers week and
 * day views because somebody working the schedule needs them. A client reading
 * what is coming does not, and the modes would be a second copy of the studio's
 * date arithmetic maintained for nobody.
 *
 * Each date is its own entry rather than one mark per item, so a due date and a
 * release target a fortnight apart read as two things happening, which is what
 * they are.
 *
 * Drawn from the design system's month grid (#286): the grid, the month bar,
 * the days and the entries are all its classes, and nothing here is styled by
 * hand. What this screen adds is the data — which month, which day, what kind
 * of date — and the system decides how each of those looks.
 */
final class CalendarScreen {

	/**
	 * The submenu page slug.
	 */
	public const SLUG = 'blueworx-forge-client-calendar';

	/**
	 * The design system tone each kind of date takes: a start is information,
	 * a due date is a warning, a review is the studio's own accent, a release
	 * is success.
	 *
	 * @var array<string, string>
	 */
	private const TONES = array(
		'starts'  => 'info',
		'due'     => 'warning',
		'review'  => 'accent',
		'release' => 'success',
	);

	/**
	 * Adds the menu entry.
	 */
	public static function register(): void {
		add_submenu_page(
			Screen::SLUG,
			__( 'Calendar', 'blueworx-forge' ),
			__( 'Calendar', 'blueworx-forge' ),
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
			__( 'Calendar', 'blueworx-forge' ),
			array( self::class, 'month' )
		);
	}

	/**
	 * Draws the month.
	 *
	 * @param array<string, mixed> $view The board as this site can see it.
	 */
	public static function month( array $view ): void {
		$items   = (array) $view['items'];
		$anchor  = self::anchor();
		$days    = Layout::month( $anchor );
		$entries = Layout::entries( $items );

		self::months( $anchor );

		echo '<table class="bw-calendar" data-testid="bwx-calendar">';
		self::head();

		echo '<tbody>';

		$today = current_time( 'Y-m-d' );

		foreach ( array_chunk( $days, 7 ) as $week ) {
			echo '<tr>';

			foreach ( $week as $day ) {
				self::day( $day, $anchor, $today, $entries[ $day ] ?? array() );
			}

			echo '</tr>';
		}

		echo '</tbody></table>';

		WorkScreen::undated( Layout::undated( $items ) );
	}

	/**
	 * The month being shown.
	 *
	 * Read from the request so a person can look ahead, and validated to a
	 * month rather than trusted: this is the only parameter any client work
	 * screen takes, and it names a month or it names nothing.
	 *
	 * @return string The first of the month, as YYYY-MM-DD.
	 */
	private static function anchor(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Looking at a different month is a read: it changes nothing, and a nonce on it would only expire while somebody was reading.
		$asked = isset( $_GET['bwx-month'] ) ? sanitize_text_field( wp_unslash( $_GET['bwx-month'] ) ) : '';

		if ( 1 === preg_match( '/^\d{4}-\d{2}$/', $asked ) ) {
			$stamp = strtotime( $asked . '-01 00:00:00 UTC' );

			if ( false !== $stamp ) {
				return gmdate( 'Y-m-01', $stamp );
			}
		}

		return gmdate( 'Y-m-01' );
	}

	/**
	 * Last month, this month's name, next month.
	 *
	 * @param string $anchor The first of the month being shown.
	 */
	private static function months( string $anchor ): void {
		$stamp = (int) strtotime( $anchor . ' 00:00:00 UTC' );

		printf(
			'<nav class="bw-calendar__nav" data-testid="bwx-months" aria-label="%s">',
			esc_attr__( 'Month', 'blueworx-forge' )
		);

		printf(
			'<a class="bw-btn bw-btn--ghost bw-btn--sm" href="%1$s" aria-label="%2$s"><i class="bw-icon" data-lucide="chevron-left"></i></a>',
			esc_url( self::url( gmdate( 'Y-m', (int) strtotime( '-1 month', $stamp ) ) ) ),
			esc_attr__( 'Previous month', 'blueworx-forge' )
		);

		printf( '<h2 class="bw-calendar__month" data-testid="bwx-month-name">%s</h2>', esc_html( gmdate( 'F Y', $stamp ) ) );

		printf(
			'<a class="bw-btn bw-btn--ghost bw-btn--sm" href="%1$s" aria-label="%2$s"><i class="bw-icon" data-lucide="chevron-right"></i></a>',
			esc_url( self::url( gmdate( 'Y-m', (int) strtotime( '+1 month', $stamp ) ) ) ),
			esc_attr__( 'Next month', 'blueworx-forge' )
		);

		echo '</nav>';
	}

	/**
	 * This screen at a given month.
	 *
	 * @param string $month YYYY-MM.
	 * @return string
	 */
	private static function url( string $month ): string {
		return add_query_arg( 'bwx-month', $month, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	/**
	 * The weekday header, Monday first.
	 */
	private static function head(): void {
		$days = array(
			__( 'Monday', 'blueworx-forge' ),
			__( 'Tuesday', 'blueworx-forge' ),
			__( 'Wednesday', 'blueworx-forge' ),
			__( 'Thursday', 'blueworx-forge' ),
			__( 'Friday', 'blueworx-forge' ),
			__( 'Saturday', 'blueworx-forge' ),
			__( 'Sunday', 'blueworx-forge' ),
		);

		echo '<thead><tr>';

		foreach ( $days as $day ) {
			printf( '<th scope="col">%s</th>', esc_html( $day ) );
		}

		echo '</tr></thead>';
	}

	/**
	 * One day.
	 *
	 * The cell carries its short weekday name and whether it is empty, because
	 * under phone width the system draws the month as a list of the days that
	 * have something on them, and reads both from here.
	 *
	 * @param string                           $day     YYYY-MM-DD.
	 * @param string                           $anchor  The first of the month shown.
	 * @param string                           $today   Today, YYYY-MM-DD, in the site's own time.
	 * @param array<int, array<string, mixed>> $entries What falls on it.
	 */
	private static function day( string $day, string $anchor, string $today, array $entries ): void {
		$stamp   = (int) strtotime( $day . ' 00:00:00 UTC' );
		$classes = array( 'bw-calendar__day' );

		if ( substr( $day, 0, 7 ) !== substr( $anchor, 0, 7 ) ) {
			$classes[] = 'bw-calendar__day--outside';
		}

		if ( $day === $today ) {
			$classes[] = 'bw-calendar__day--today';
		}

		if ( array() === $entries ) {
			$classes[] = 'bw-calendar__day--empty';
		}

		printf(
			'<td class="%1$s" data-weekday="%2$s" data-testid="bwx-calendar-day" data-bwx-day="%3$s">',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( gmdate( 'D', $stamp ) ),
			esc_attr( $day )
		);

		printf( '<span class="bw-calendar__daynum">%s</span>', esc_html( (string) (int) substr( $day, 8, 2 ) ) );

		if ( array() !== $entries ) {
			echo '<ul class="bw-calendar__entries">';

			foreach ( $entries as $entry ) {
				$kind = (string) $entry['kind'];

				printf(
					'<li class="bw-calendar__entry bw-calendar__entry--%1$s" data-testid="bwx-calendar-entry" data-bwx-kind="%2$s"><span class="bw-calendar__kind">%3$s</span> %4$s</li>',
					esc_attr( self::TONES[ $kind ] ?? 'info' ),
					esc_attr( $kind ),
					esc_html( self::kind_label( $kind ) ),
					esc_html( (string) ( $entry['item']['title'] ?? '' ) )
				);
			}

			echo '</ul>';
		}

		echo '</td>';
	}

	/**
	 * What a kind of date is called.
	 *
	 * @param string $kind One of Layout::KINDS' values.
	 * @return string
	 */
	private static function kind_label( string $kind ): string {
		$labels = array(
			'starts'  => __( 'Starts', 'blueworx-forge' ),
			'due'     => __( 'Due', 'blueworx-forge' ),
			'review'  => __( 'Review', 'blueworx-forge' ),
			'release' => __( 'Release', 'blueworx-forge' ),
		);

		return $labels[ $kind ] ?? $kind;
	}
}
