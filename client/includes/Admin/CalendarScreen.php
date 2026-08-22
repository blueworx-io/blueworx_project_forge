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
 */
final class CalendarScreen {

	/**
	 * The submenu page slug.
	 */
	public const SLUG = 'blueworx-forge-client-calendar';

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
			__( 'Forge — calendar', 'blueworx-forge' ),
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

		echo '<table class="bwx-calendar" data-testid="bwx-calendar">';
		self::head();

		echo '<tbody>';

		foreach ( array_chunk( $days, 7 ) as $week ) {
			echo '<tr>';

			foreach ( $week as $day ) {
				self::day( $day, $anchor, $entries[ $day ] ?? array() );
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

		echo '<nav class="bwx-months" data-testid="bwx-months">';

		printf(
			'<a href="%1$s">%2$s</a>',
			esc_url( self::url( gmdate( 'Y-m', (int) strtotime( '-1 month', $stamp ) ) ) ),
			esc_html__( 'Previous', 'blueworx-forge' )
		);

		printf( '<strong data-testid="bwx-month-name">%s</strong>', esc_html( gmdate( 'F Y', $stamp ) ) );

		printf(
			'<a href="%1$s">%2$s</a>',
			esc_url( self::url( gmdate( 'Y-m', (int) strtotime( '+1 month', $stamp ) ) ) ),
			esc_html__( 'Next', 'blueworx-forge' )
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
	 * @param string                           $day     YYYY-MM-DD.
	 * @param string                           $anchor  The first of the month shown.
	 * @param array<int, array<string, mixed>> $entries What falls on it.
	 */
	private static function day( string $day, string $anchor, array $entries ): void {
		$outside = substr( $day, 0, 7 ) !== substr( $anchor, 0, 7 );

		printf(
			'<td class="%1$s" data-testid="bwx-calendar-day" data-bwx-day="%2$s">',
			esc_attr( $outside ? 'bwx-calendar-outside' : '' ),
			esc_attr( $day )
		);

		printf( '<span class="bwx-calendar-daynum">%s</span>', esc_html( (string) (int) substr( $day, 8, 2 ) ) );

		foreach ( $entries as $entry ) {
			printf(
				'<span class="bwx-calendar-entry" data-testid="bwx-calendar-entry" data-bwx-kind="%1$s"><span class="bwx-calendar-kind">%2$s</span> %3$s</span>',
				esc_attr( (string) $entry['kind'] ),
				esc_html( self::kind_label( (string) $entry['kind'] ) ),
				esc_html( (string) ( $entry['item']['title'] ?? '' ) )
			);
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
