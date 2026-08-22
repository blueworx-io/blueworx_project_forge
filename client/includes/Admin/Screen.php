<?php
/**
 * The client site's landing screen.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

use Blueworx\Forge\Client\Board;
use Blueworx\Forge\Client\Digest;
use Blueworx\Forge\Client\Workspace;

/**
 * What a client sees first: who to talk to, and what is happening (#127).
 *
 * The order is the order somebody asks. Who is looking after us, is anything
 * wrong, what is coming, and only then the details of the connection itself —
 * which matter to whoever set the site up and to nobody afterwards.
 *
 * Every section says something when it is empty, and each empty state says
 * which kind of empty it is. A brand-new client has no work, no contact
 * assigned yet and no support package, and all three of those are ordinary
 * facts about being new rather than signs of a broken screen. That distinction
 * is the whole of this issue's acceptance.
 *
 * The last-sync line is not decoration either. Under ARCH-4 a client site keeps
 * working while the studio is unreachable by showing what it last saw — which
 * is only honest if the screen says so. A cached record shown as though it were
 * live is worse than an error, because the person reading it has no way to tell.
 *
 * Deliberately plain PHP and plain markup. The studio app is React; a client
 * site gets a WordPress admin page that loads with the dashboard and needs no
 * build step of its own.
 */
final class Screen {

	/**
	 * The admin page slug.
	 */
	public const SLUG = 'blueworx-forge-client';

	/**
	 * Handle of the design token stylesheet.
	 */
	public const STYLE = 'blueworx-forge-tokens';

	/**
	 * Every screen this plugin owns.
	 *
	 * @return array<int, string>
	 */
	private static function slugs(): array {
		return array(
			self::SLUG,
			BoardScreen::SLUG,
			TimelineScreen::SLUG,
			CalendarScreen::SLUG,
			ConnectionScreen::SLUG,
		);
	}

	/**
	 * Whether a screen being loaded is one of ours.
	 *
	 * Matched on the hook rather than on the requested page, because the hook
	 * is WordPress telling us which screen it is about to render, and the
	 * request is whatever somebody typed.
	 *
	 * @param string $hook The screen being loaded.
	 * @return bool
	 */
	private static function ours( string $hook ): bool {
		foreach ( self::slugs() as $slug ) {
			if ( 'toplevel_page_' . $slug === $hook || str_ends_with( $hook, '_page_' . $slug ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Loads the design tokens, on this plugin's screens only.
	 *
	 * The tokens are shipped rather than compiled here: this artifact has no
	 * build step of its own, and the file it loads is the same one the studio's
	 * app compiles in (#85). One edit reaches both, and there is no second copy
	 * to forget.
	 *
	 * The work views' own rules ride along inline for the same reason they are
	 * not a file: what the client artifact may contain is a closed list, and the
	 * guarantee that list gives is worth more than a tidier stylesheet (#128).
	 *
	 * @param string $hook The screen being loaded.
	 */
	public static function enqueue( string $hook ): void {
		if ( ! self::ours( $hook ) ) {
			return;
		}

		$tokens = BWX_FORGE_CLIENT_PATH . 'tokens/forge.css';

		if ( ! file_exists( $tokens ) ) {
			return;
		}

		wp_enqueue_style(
			self::STYLE,
			BWX_FORGE_CLIENT_URL . 'tokens/forge.css',
			array(),
			(string) filemtime( $tokens )
		);

		wp_add_inline_style( self::STYLE, Styles::css() );
	}

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

		$refresh = SyncNotice::refresh_requested();
		$view    = Workspace::view( $refresh );
		$board   = Board::view( $refresh );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Forge', 'blueworx-forge' ) . '</h1>';

		Nav::render( self::SLUG, $view );

		// One notice, from the record the frame itself is drawn from. The work
		// sections below say for themselves when the work could not be read,
		// which is more use than a second banner saying the same thing twice.
		SyncNotice::render( $view['sync'], self::SLUG );

		if ( null === $view['record'] ) {
			self::empty_state( (string) $view['sync']['state'] );
			echo '</div>';

			return;
		}

		self::contact( (array) $view['contact'] );
		self::attention( $board );
		self::upcoming( $board );
		self::support();
		self::record( (array) $view['record'] );

		echo '</div>';
	}

	/**
	 * Who to talk to.
	 *
	 * A name and nothing else — the address, the account and the grants
	 * somebody holds are ours (AUTH-5). Nobody assigned yet is said plainly
	 * rather than shown as a blank, because a blank reads as a screen that
	 * failed rather than a client who is new.
	 *
	 * @param array<string, mixed> $contact The contact, as the studio sent it.
	 */
	private static function contact( array $contact ): void {
		$name = (string) ( $contact['display_name'] ?? '' );

		self::open( __( 'Your contact', 'blueworx-forge' ), 'contact' );

		if ( '' === $name ) {
			printf(
				'<p class="bwx-empty">%s</p>',
				esc_html__( 'Nobody is assigned to you yet. The studio is sorting that out; anything urgent can go to whoever set this site up.', 'blueworx-forge' )
			);
		} else {
			printf( '<p class="bwx-lede" data-testid="bwx-contact-name">%s</p>', esc_html( $name ) );
			printf(
				'<p class="bwx-empty">%s</p>',
				esc_html__( 'Your point of contact at the studio.', 'blueworx-forge' )
			);
		}

		self::close();
	}

	/**
	 * What has gone wrong.
	 *
	 * First on the page and first for a reason: it is the only section somebody
	 * would want to be interrupted by. When nothing is wrong it says so, which
	 * is a useful sentence rather than an empty box.
	 *
	 * @param array<string, mixed> $board The board as this site can see it.
	 */
	private static function attention( array $board ): void {
		self::open( __( 'Needs attention', 'blueworx-forge' ), 'attention' );

		if ( ! $board['ok'] ) {
			self::work_unavailable();
			self::close();

			return;
		}

		$wanting = Digest::attention( (array) $board['items'], gmdate( 'Y-m-d' ) );

		if ( array() === $wanting ) {
			printf(
				'<p class="bwx-empty">%s</p>',
				esc_html__( 'Nothing is blocked or overdue.', 'blueworx-forge' )
			);
			self::close();

			return;
		}

		echo '<ul class="bwx-list" data-testid="bwx-attention-list">';

		foreach ( $wanting as $entry ) {
			printf(
				'<li data-bwx-reason="%1$s"><strong>%2$s</strong> <span class="bwx-card-key">%3$s</span></li>',
				esc_attr( (string) $entry['reason'] ),
				esc_html( (string) ( $entry['item']['title'] ?? '' ) ),
				esc_html( self::reason_label( (string) $entry['reason'], (array) $entry['item'] ) )
			);
		}

		echo '</ul>';
		self::close();
	}

	/**
	 * What is coming.
	 *
	 * @param array<string, mixed> $board The board as this site can see it.
	 */
	private static function upcoming( array $board ): void {
		self::open( __( 'Coming up', 'blueworx-forge' ), 'upcoming' );

		if ( ! $board['ok'] ) {
			self::work_unavailable();
			self::close();

			return;
		}

		$coming = Digest::upcoming( (array) $board['items'], gmdate( 'Y-m-d' ) );

		if ( array() === $coming ) {
			printf(
				'<p class="bwx-empty">%s</p>',
				esc_html__( 'Nothing has a date on it yet. Work appears here once it is scheduled.', 'blueworx-forge' )
			);
			self::close();

			return;
		}

		echo '<ul class="bwx-list" data-testid="bwx-upcoming-list">';

		foreach ( $coming as $item ) {
			printf(
				'<li><strong>%1$s</strong> <span class="bwx-card-key">%2$s</span> <span class="bwx-card-value">%3$s</span></li>',
				esc_html( (string) ( $item['title'] ?? '' ) ),
				esc_html( (string) ( $item['stage_label'] ?? '' ) ),
				esc_html( Card::day( (string) ( $item['planned_due'] ?? '' ) ) )
			);
		}

		echo '</ul>';
		self::close();
	}

	/**
	 * The support summary.
	 *
	 * Support packages are M8's, and are not built. This says so rather than
	 * leaving a heading over nothing: a client reading "Support" above a blank
	 * has no way to tell whether they have no package or the screen is broken,
	 * and those are very different things to be told.
	 */
	private static function support(): void {
		self::open( __( 'Support', 'blueworx-forge' ), 'support' );

		printf(
			'<p class="bwx-empty">%s</p>',
			esc_html__( 'Support packages and hours are not set up yet. When they are, your balance appears here.', 'blueworx-forge' )
		);

		self::close();
	}

	/**
	 * Why an item wants attention, in words rather than in dates.
	 *
	 * @param string               $reason Either blocked or overdue.
	 * @param array<string, mixed> $item   The item itself.
	 * @return string
	 */
	private static function reason_label( string $reason, array $item ): string {
		if ( 'blocked' === $reason ) {
			return __( 'Blocked — waiting on something before it can go on', 'blueworx-forge' );
		}

		/* translators: %s: the date the work was due. */
		return sprintf( __( 'Past its date of %s', 'blueworx-forge' ), Card::day( (string) ( $item['planned_due'] ?? '' ) ) );
	}

	/**
	 * What a work section says when the work could not be read.
	 */
	private static function work_unavailable(): void {
		printf(
			'<p class="bwx-empty" data-bwx-work-unavailable="1">%s</p>',
			esc_html__( 'Your work cannot be read from the studio right now. Nothing has been lost.', 'blueworx-forge' )
		);
	}

	/**
	 * Opens a section.
	 *
	 * @param string $heading The section heading.
	 * @param string $name    A name for tests and styling to hold on to.
	 */
	private static function open( string $heading, string $name ): void {
		printf(
			'<section class="bwx-panel" data-testid="bwx-panel" data-bwx-panel="%s">',
			esc_attr( $name )
		);
		printf( '<h2>%s</h2>', esc_html( $heading ) );
	}

	/**
	 * Closes a section.
	 */
	private static function close(): void {
		echo '</section>';
	}

	/**
	 * The workspace record.
	 *
	 * Last on the page on purpose. It matters to whoever connected the site and
	 * to nobody after that.
	 *
	 * @param array<string, mixed> $record The studio's record for this site.
	 */
	private static function record( array $record ): void {
		$connected = (int) ( $record['connected_since'] ?? 0 );

		self::open( __( 'Your site', 'blueworx-forge' ), 'site' );

		echo '<table class="widefat striped" data-bwx-workspace="1"><tbody>';

		self::row( __( 'Site', 'blueworx-forge' ), (string) ( $record['name'] ?? '' ) );
		self::row( __( 'Address', 'blueworx-forge' ), (string) ( $record['url'] ?? '' ) );
		self::row( __( 'Status', 'blueworx-forge' ), (string) ( $record['status'] ?? '' ) );
		self::row(
			__( 'Connected since', 'blueworx-forge' ),
			$connected > 0 ? gmdate( 'j F Y', $connected ) : ''
		);

		echo '</tbody></table>';

		echo '<p class="bwx-empty">' . esc_html__( 'These details are held by the studio. This site shows them; it does not keep them.', 'blueworx-forge' ) . '</p>';

		self::close();
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
