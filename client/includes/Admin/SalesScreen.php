<?php
/**
 * What you have, and how to ask for more.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

use Blueworx\Forge\Client\Connection;
use Blueworx\Forge\Client\Denial;
use Blueworx\Forge\Client\Sales;
use Blueworx\Forge\Client\Sync;
use Blueworx\Forge\Client\Workspace;

/**
 * #156, COMM-2. The client's own view of their support hours.
 *
 * **The number on this screen is money**, which changes what "unknown" has to
 * look like. Everywhere else a stale copy shown as a stale copy is good enough;
 * here a balance read as live when it is a fortnight old is something somebody
 * makes a decision on. So a balance that has never been read says so in words
 * rather than showing nought, and the sync notice sits above everything.
 *
 * **Nothing here is worked out.** The balance, the entitlement and what each
 * package costs all arrive as the studio calculated them. Summing a ledger on
 * this side would be a second answer to a question that must have exactly one.
 *
 * **Asking is a conversation, not a checkout.** COMM-2 keeps assignment manual,
 * so what this screen offers is a message that reaches a person — deliberately
 * nothing that could be mistaken for buying.
 */
final class SalesScreen {

	/**
	 * The submenu page slug.
	 */
	public const SLUG = 'blueworx-forge-client-sales';

	/**
	 * Adds the menu entry.
	 */
	public static function register(): void {
		add_submenu_page(
			Screen::SLUG,
			__( 'Support Details', 'blueworx-forge' ),
			__( 'Support Details', 'blueworx-forge' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * This screen's URL.
	 *
	 * @param string $result A result code, or ''.
	 * @return string
	 */
	public static function url( string $result = '' ): string {
		$url = admin_url( 'admin.php?page=' . self::SLUG );

		return '' === $result ? $url : add_query_arg( 'bwx-result', $result, $url );
	}

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Not a new read: it is cached the same as any other read-through view
		// (the same reasoning AskScreen's and AskedScreen's own eyebrow rests
		// on) — this screen needs it only for whose workspace the eyebrow
		// names.
		$workspace = Workspace::view( false );

		Page::open( __( 'Support Details', 'blueworx-forge' ), Nav::scope_text( $workspace ) );

		if ( ! Connection::is_configured() ) {
			Denial::render( Sync::STATE_NOT_CONFIGURED, Denial::WORKSPACE, 'bwx-sales-unavailable' );

			Page::close();

			return;
		}

		$view = Sales::view();

		SyncNotice::render( $view['sync'], self::SLUG );

		self::position( $view );
		self::purchases( $view );
		self::offer( $view );

		Page::close();
	}

	/**
	 * What the client has today.
	 *
	 * @param array<string, mixed> $view What Sales::view() returned.
	 */
	private static function position( array $view ): void {
		$entitlement = (array) $view['entitlement'];

		Page::panel_open( __( 'Where you stand', 'blueworx-forge' ), 'hours' );

		if ( array() === $entitlement ) {
			echo '<div class="bw-empty">';
			echo '<i class="bw-icon bw-empty__icon" data-lucide="clock"></i>';
			printf( '<h3 class="bw-empty__title">%s</h3>', esc_html__( 'Not read yet', 'blueworx-forge' ) );
			printf(
				'<p class="bw-empty__text">%s</p>',
				esc_html__( 'Your hours have not been read from the studio yet.', 'blueworx-forge' )
			);
			echo '</div>';

			Page::panel_close();

			return;
		}

		self::position_summary( $view, $entitlement );

		Page::panel_close();
	}

	/**
	 * The three figures that make up "where you stand", as one strip.
	 *
	 * Status, balance and term all belong to the same question and change
	 * together, so they read as one `bw-summary` — the design system's own
	 * shape for a persistent band of derived figures — rather than as three
	 * loose paragraphs that happen to sit near each other.
	 *
	 * @param array<string, mixed> $view        What Sales::view() returned.
	 * @param array<string, mixed> $entitlement The non-empty entitlement.
	 */
	private static function position_summary( array $view, array $entitlement ): void {
		echo '<div class="bw-summary">';

		printf(
			'<div class="bw-summary__cell"><span class="bw-summary__label">%1$s</span><span class="bw-summary__value" data-bwx-state="%2$s">%3$s</span></div>',
			esc_html__( 'Status', 'blueworx-forge' ),
			esc_attr( (string) ( $entitlement['state'] ?? '' ) ),
			esc_html( (string) ( $entitlement['label'] ?? '' ) )
		);

		printf(
			'<div class="bw-summary__cell"><span class="bw-summary__label">%1$s</span><span class="bw-summary__value" data-bwx-balance="%2$s">%3$s</span></div>',
			esc_html__( 'Balance', 'blueworx-forge' ),
			esc_attr( null === $view['balance'] ? '' : (string) $view['balance'] ),
			esc_html( Sales::balance_label( $view ) )
		);

		if ( '' !== (string) ( $entitlement['term_ends_on'] ?? '' ) ) {
			printf(
				'<div class="bw-summary__cell"><span class="bw-summary__label">%1$s</span><span class="bw-summary__value" data-bwx-term-ends="%2$s">%3$s</span><span class="bw-summary__foot">%4$s</span></div>',
				esc_html__( 'Term ends', 'blueworx-forge' ),
				esc_attr( (string) $entitlement['term_ends_on'] ),
				esc_html( (string) $entitlement['term_ends_on'] ),
				esc_html__( 'Your current term', 'blueworx-forge' )
			);
		}

		echo '</div>';
	}

	/**
	 * What the client has been given or has bought.
	 *
	 * @param array<string, mixed> $view What Sales::view() returned.
	 */
	private static function purchases( array $view ): void {
		$purchases = (array) $view['purchases'];

		Page::panel_open( __( 'What you have bought', 'blueworx-forge' ), 'purchases' );

		if ( array() === $purchases ) {
			echo '<div class="bw-empty" data-bwx-purchases="0">';
			echo '<i class="bw-icon bw-empty__icon" data-lucide="package"></i>';
			printf( '<h3 class="bw-empty__title">%s</h3>', esc_html__( 'Nothing yet', 'blueworx-forge' ) );
			printf(
				'<p class="bw-empty__text">%s</p>',
				esc_html__( 'Hours appear here as soon as a package is set up for you.', 'blueworx-forge' )
			);
			echo '</div>';

			Page::panel_close();

			return;
		}

		echo '<table class="bw-table" data-bwx-purchases="' . esc_attr( (string) count( $purchases ) ) . '"><thead><tr>';
		echo '<th>' . esc_html__( 'When', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'What', 'blueworx-forge' ) . '</th>';
		echo '<th class="bw-table__num">' . esc_html__( 'Hours', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Runs out', 'blueworx-forge' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $purchases as $bought ) {
			$expires = (int) ( $bought['expires_at'] ?? 0 );

			echo '<tr data-bwx-purchase="' . esc_attr( (string) ( $bought['kind'] ?? '' ) ) . '">';
			echo '<td>' . esc_html( (string) ( $bought['on'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( self::kind_label( (string) ( $bought['kind'] ?? '' ), (string) ( $bought['reason'] ?? '' ) ) ) . '</td>';
			echo '<td class="bw-table__num">' . esc_html( number_format( (float) ( $bought['hours'] ?? 0 ), 2 ) ) . '</td>';
			echo '<td>' . esc_html( 0 === $expires ? __( 'With your package', 'blueworx-forge' ) : gmdate( 'Y-m-d', $expires ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		Page::panel_close();
	}

	/**
	 * How a purchase reads to the person who made it.
	 *
	 * @param string $kind   allocation or top-up.
	 * @param string $reason What was said about it, where anything was.
	 * @return string
	 */
	private static function kind_label( string $kind, string $reason ): string {
		$label = 'top-up' === $kind
			? __( 'Extra hours', 'blueworx-forge' )
			: __( 'Your package', 'blueworx-forge' );

		return '' === $reason ? $label : $label . ' — ' . $reason;
	}

	/**
	 * What else is available, and how to ask for it.
	 *
	 * @param array<string, mixed> $view What Sales::view() returned.
	 */
	private static function offer( array $view ): void {
		$packages = (array) $view['packages'];

		Page::panel_open( __( 'More hours', 'blueworx-forge' ), 'offer' );

		if ( array() !== $packages ) {
			echo '<table class="bw-table" data-bwx-packages="' . esc_attr( (string) count( $packages ) ) . '"><thead><tr>';
			echo '<th>' . esc_html__( 'Package', 'blueworx-forge' ) . '</th>';
			echo '<th class="bw-table__num">' . esc_html__( 'Hours', 'blueworx-forge' ) . '</th>';
			echo '<th class="bw-table__num">' . esc_html__( 'Price', 'blueworx-forge' ) . '</th>';
			echo '<th>' . esc_html__( 'Runs for', 'blueworx-forge' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $packages as $package ) {
				echo '<tr data-bwx-package="' . esc_attr( (string) ( $package['name'] ?? '' ) ) . '">';
				echo '<td>' . esc_html( (string) ( $package['name'] ?? '' ) ) . '</td>';
				echo '<td class="bw-table__num">' . esc_html( number_format( (float) ( $package['hours'] ?? 0 ), 2 ) ) . '</td>';
				echo '<td class="bw-table__num">' . esc_html( self::money( (int) ( $package['price'] ?? 0 ), (string) ( $package['currency'] ?? 'GBP' ) ) ) . '</td>';
				echo '<td>' . esc_html(
					sprintf(
						/* translators: %d: a number of months. */
						_n( '%d month', '%d months', (int) ( $package['validity_months'] ?? 12 ), 'blueworx-forge' ),
						(int) ( $package['validity_months'] ?? 12 )
					)
				) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		/*
		 * A message, not a basket. COMM-2 keeps assignment manual, so what is
		 * offered here reaches a person who will talk to you — and there is
		 * deliberately nothing on this screen that could be mistaken for having
		 * bought something.
		 */
		printf(
			'<p class="bw-card__note">%s</p>',
			esc_html__( 'Ask for more hours, or to move to a different package, and we will sort it out with you. Nothing here charges you for anything.', 'blueworx-forge' )
		);

		printf(
			'<a class="bw-btn bw-btn--primary" data-bwx-ask-hours="1" href="%1$s">%2$s</a>',
			esc_url( AskScreen::url() ),
			esc_html__( 'Ask about hours', 'blueworx-forge' )
		);

		Page::panel_close();
	}

	/**
	 * A price, as the client would read it.
	 *
	 * @param int    $pence    The price in the smallest unit.
	 * @param string $currency Three-letter code.
	 * @return string
	 */
	private static function money( int $pence, string $currency ): string {
		$symbols = array(
			'GBP' => '£',
			'EUR' => '€',
			'USD' => '$',
		);

		return ( $symbols[ $currency ] ?? ( $currency . ' ' ) ) . number_format( $pence / 100, 2 );
	}
}
