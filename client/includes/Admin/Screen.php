<?php
/**
 * The client site's landing screen.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

use Blueworx\Forge\Client\Board;
use Blueworx\Forge\Client\Denial;
use Blueworx\Forge\Client\Digest;
use Blueworx\Forge\Client\Sales;
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
			ChecklistScreen::SLUG,
			AskScreen::SLUG,
			AskedScreen::SLUG,
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

		/*
		 * Somebody is on a Forge screen. Announced rather than acted on here,
		 * because what wants to know is not this file's business: #173 uses it
		 * to top up the mail queue, on the grounds that WP-Cron on a quiet site
		 * only fires when somebody visits, and a client site can be quiet for
		 * days. Whoever listens is responsible for not doing it too often.
		 */
		do_action( 'bwx_forge_client_screen_loaded', $hook );

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

		$design = BWX_FORGE_CLIENT_PATH . 'assets/blueworx-admin-design.css';

		if ( file_exists( $design ) ) {
			wp_enqueue_style(
				'blueworx-admin-design',
				BWX_FORGE_CLIENT_URL . 'assets/blueworx-admin-design.css',
				array(),
				(string) filemtime( $design )
			);

			$icons = BWX_FORGE_CLIENT_PATH . 'assets/blueworx-admin-icons.js';

			if ( file_exists( $icons ) ) {
				wp_enqueue_script_module(
					'blueworx-admin-icons',
					BWX_FORGE_CLIENT_URL . 'assets/blueworx-admin-icons.js',
					array(),
					(string) filemtime( $icons )
				);
			}
		}
	}

	/**
	 * Adds the menu entry.
	 *
	 * The submenu entry is added explicitly so it can be called Overview.
	 * WordPress otherwise repeats the top-level name as the first child, which
	 * would list "Forge" twice — once as the section and once as the page.
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

		add_submenu_page(
			self::SLUG,
			__( 'Overview', 'blueworx-forge' ),
			__( 'Overview', 'blueworx-forge' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
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

		// Support Details used to be a screen of its own; since #287 it is
		// these three panels, here. A client's hours are not a separate
		// subject from the rest of their arrangement with us — somebody asking
		// "where are we up to" and somebody asking "what have we got left"
		// are usually the same person in the same minute, and making them
		// visit two screens to find out was our filing showing through.
		//
		// A second read-through, not a second network call in the common
		// case: it is cached exactly as the workspace record is, and a refresh
		// asked for at the top of the page refreshes both.
		$sales = Sales::view( $refresh );

		Page::open( __( 'Overview', 'blueworx-forge' ), Nav::scope_text( $view ) );

		// One notice, from the record the frame itself is drawn from. The work
		// sections below say for themselves when the work could not be read,
		// which is more use than a second banner saying the same thing twice.
		SyncNotice::render( $view['sync'], self::SLUG );

		if ( null === $view['record'] ) {
			self::empty_state( (string) $view['sync']['state'] );
			Page::close();

			return;
		}

		self::contact( (array) $view['contact'] );
		self::attention( $board );
		self::upcoming( $board );

		/*
		 * The support position is read from the sales record rather than the
		 * workspace one. Both carry the same thing — the studio publishes it on
		 * either route — and taking it from the record the figures beside it
		 * came from means the position and the balance under it were read at
		 * the same moment. Two reads a minute apart could disagree, and a
		 * client would have no way to tell which half was stale.
		 */
		$support = array() === (array) $sales['support'] ? (array) $view['support'] : (array) $sales['support'];

		self::position( $sales, $support );
		self::purchases( $sales );
		self::offer( $sales );
		self::record( (array) $view['record'] );

		Page::close();
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

		Page::panel_open( __( 'Your contact', 'blueworx-forge' ), 'contact' );

		if ( '' === $name ) {
			self::nothing(
				'user',
				__( 'No contact assigned yet', 'blueworx-forge' ),
				__( 'Nobody is assigned to you yet. The studio is sorting that out; anything urgent can go to whoever set this site up.', 'blueworx-forge' )
			);
		} else {
			printf( '<p class="bwx-lede" data-testid="bwx-contact-name">%s</p>', esc_html( $name ) );
			printf(
				'<p class="bw-card__note">%s</p>',
				esc_html__( 'Your point of contact at the studio.', 'blueworx-forge' )
			);
		}

		Page::panel_close();
	}

	/**
	 * A panel with nothing to show yet — the design system's EmptyState.
	 *
	 * @param string $icon  A lucide icon name shipped with the design system.
	 * @param string $title Short label for what would appear here.
	 * @param string $text  The fuller sentence explaining the absence.
	 */
	private static function nothing( string $icon, string $title, string $text ): void {
		printf( '<div class="bw-empty"><i class="bw-icon bw-empty__icon" data-lucide="%s"></i>', esc_attr( $icon ) );
		printf( '<h3 class="bw-empty__title">%s</h3>', esc_html( $title ) );
		printf( '<p class="bw-empty__text">%s</p>', esc_html( $text ) );
		echo '</div>';
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
		Page::panel_open( __( 'Needs attention', 'blueworx-forge' ), 'attention' );

		if ( ! $board['ok'] ) {
			self::work_unavailable( (string) $board['sync']['state'] );
			Page::panel_close();

			return;
		}

		$wanting = Digest::attention( (array) $board['items'], gmdate( 'Y-m-d' ) );

		if ( array() === $wanting ) {
			self::nothing(
				'circle-check',
				__( 'Nothing needs attention', 'blueworx-forge' ),
				__( 'Nothing is blocked or overdue.', 'blueworx-forge' )
			);
			Page::panel_close();

			return;
		}

		echo '<ul data-testid="bwx-attention-list">';

		foreach ( $wanting as $entry ) {
			printf(
				'<li data-bwx-reason="%1$s"><strong>%2$s</strong> <span>%3$s</span></li>',
				esc_attr( (string) $entry['reason'] ),
				esc_html( (string) ( $entry['item']['title'] ?? '' ) ),
				esc_html( self::reason_label( (string) $entry['reason'], (array) $entry['item'] ) )
			);
		}

		echo '</ul>';
		Page::panel_close();
	}

	/**
	 * What is coming.
	 *
	 * @param array<string, mixed> $board The board as this site can see it.
	 */
	private static function upcoming( array $board ): void {
		Page::panel_open( __( 'Coming up', 'blueworx-forge' ), 'upcoming' );

		if ( ! $board['ok'] ) {
			self::work_unavailable( (string) $board['sync']['state'] );
			Page::panel_close();

			return;
		}

		$coming = Digest::upcoming( (array) $board['items'], gmdate( 'Y-m-d' ) );

		if ( array() === $coming ) {
			self::nothing(
				'calendar',
				__( 'Nothing scheduled yet', 'blueworx-forge' ),
				__( 'Nothing has a date on it yet. Work appears here once it is scheduled.', 'blueworx-forge' )
			);
			Page::panel_close();

			return;
		}

		echo '<ul data-testid="bwx-upcoming-list">';

		foreach ( $coming as $item ) {
			printf(
				'<li><strong>%1$s</strong> <span>%2$s</span> <span>%3$s</span></li>',
				esc_html( (string) ( $item['title'] ?? '' ) ),
				esc_html( (string) ( $item['stage_label'] ?? '' ) ),
				esc_html( Card::day( (string) ( $item['planned_due'] ?? '' ) ) )
			);
		}

		echo '</ul>';
		Page::panel_close();
	}

	/**
	 * The support position, and what it leaves open (#151).
	 *
	 * **A client with no package is told, and told what they can still do.**
	 * Hiding the section, or leaving a heading over a blank, is the version of
	 * this that reads as a broken screen — and a client who thinks the page is
	 * broken does not ring up to buy a package.
	 *
	 * What is printed is what the studio sent. Whether a position permits
	 * chargeable work is a commercial rule and it is answered on the studio's
	 * server; a copy of it here would be a second answer to the same question,
	 * running where the client can see it and nobody would think to change it.
	 *
	 * An empty answer is not "no package" — it is a site that has never reached
	 * the studio, which the sync notice at the top of the page already explains.
	 *
	 * @param array<string, mixed> $sales   What Sales::view() returned.
	 * @param array<string, mixed> $support The position, as the studio sent it.
	 */
	private static function position( array $sales, array $support ): void {
		$state       = (string) ( $support['state'] ?? '' );
		$entitlement = (array) $sales['entitlement'];

		Page::panel_open( __( 'Where you stand', 'blueworx-forge' ), 'hours' );

		if ( '' === $state && array() === $entitlement ) {
			self::nothing(
				'clock',
				__( 'Not read yet', 'blueworx-forge' ),
				__( 'Your support position has not been read from the studio yet.', 'blueworx-forge' )
			);
			Page::panel_close();

			return;
		}

		$summarised = array() !== $entitlement;

		if ( $summarised ) {
			self::position_summary( $sales, $entitlement );
		}

		/*
		 * The state marker stays whatever the strip above does. It is what the
		 * acceptance for #151 reads, and losing it to a redesign is how a rule
		 * stops being checked without anybody deciding to stop checking it.
		 *
		 * Hidden only when the strip is already saying the same thing in the
		 * same words. Where there is no strip — a position read without an
		 * entitlement beside it — this is the only thing naming the position,
		 * so it is printed for everybody to see.
		 */
		if ( '' !== $state ) {
			printf(
				'<p class="%1$s" data-testid="bwx-support-state" data-bwx-support-state="%2$s">%3$s</p>',
				esc_attr( $summarised ? 'screen-reader-text' : 'bwx-lede' ),
				esc_attr( $state ),
				esc_html( (string) ( $support['label'] ?? '' ) )
			);
		}

		if ( in_array( 'chargeable-work', (array) ( $support['refused'] ?? array() ), true ) ) {
			/*
			 * The one sentence #151 exists for. It says what is not available,
			 * and in the same breath the two things that are — so the
			 * restriction reads as a conversation to have rather than as a door
			 * that has been shut. A Notice, not an EmptyState: there is a
			 * support position shown above, so nothing here is empty.
			 */
			Page::notice(
				'warning',
				__(
					'New chargeable work cannot be scheduled until a support package is in place. You can still report anything that is broken, ask for something, and talk to your contact about a package.',
					'blueworx-forge'
				),
				array( 'data-testid' => 'bwx-support-refused' )
			);
		}

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
	 * @param array<string, mixed> $sales       What Sales::view() returned.
	 * @param array<string, mixed> $entitlement The non-empty entitlement.
	 */
	private static function position_summary( array $sales, array $entitlement ): void {
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
			esc_attr( null === $sales['balance'] ? '' : (string) $sales['balance'] ),
			esc_html( Sales::balance_label( $sales ) )
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
	 * @param array<string, mixed> $sales What Sales::view() returned.
	 */
	private static function purchases( array $sales ): void {
		$purchases = (array) $sales['purchases'];

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
	 * @param array<string, mixed> $sales What Sales::view() returned.
	 */
	private static function offer( array $sales ): void {
		$packages = (array) $sales['packages'];

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
		 * deliberately nothing on this panel that could be mistaken for having
		 * bought something.
		 */
		Page::notice(
			'info',
			__( 'Ask for more hours, or to move to a different package, and we will sort it out with you. Nothing here charges you for anything.', 'blueworx-forge' )
		);

		echo '<div class="bwx-panelaction">';

		printf(
			'<a class="bw-btn bw-btn--primary" data-bwx-ask-hours="1" href="%1$s">%2$s</a>',
			esc_url( AskScreen::url() ),
			esc_html__( 'Ask about hours', 'blueworx-forge' )
		);

		echo '</div>';

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
	 *
	 * Which sentence that is belongs to Denial (#134) rather than to this
	 * screen. Two sections here say it, three other screens say something like
	 * it, and while they each wrote their own one of them was always going to
	 * be the one that never learned about a new way of failing.
	 *
	 * @param string $state One of the Sync STATE_ constants.
	 */
	private static function work_unavailable( string $state ): void {
		Denial::render( $state, Denial::WORK, 'bwx-work-unavailable' );
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

		$url    = (string) ( $record['url'] ?? '' );
		$status = (string) ( $record['status'] ?? '' );

		Page::panel_open( __( 'Your site', 'blueworx-forge' ), 'site' );

		echo '<dl class="bw-dl" data-bwx-workspace="1">';

		printf(
			'<dt>%1$s</dt><dd>%2$s</dd>',
			esc_html__( 'Site', 'blueworx-forge' ),
			esc_html( (string) ( $record['name'] ?? '' ) )
		);

		/*
		 * The address as somebody would say it out loud, linked to the whole
		 * thing. A bare "https://demo.example.co.uk/" is a URL printed at a
		 * person; the host is the part they recognise, and the scheme and the
		 * trailing slash are punctuation only a machine needs.
		 */
		printf( '<dt>%s</dt><dd>', esc_html__( 'Address', 'blueworx-forge' ) );

		if ( '' === $url ) {
			esc_html_e( 'Not recorded', 'blueworx-forge' );
		} else {
			printf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $url ),
				esc_html( self::host( $url ) )
			);
		}

		echo '</dd>';

		/*
		 * A state, drawn as the design system draws states. "active" in the
		 * body text was the database's word for it sitting in a sentence meant
		 * for a person — and it read as something that might be wrong, because
		 * nothing around it said otherwise.
		 */
		printf( '<dt>%s</dt><dd>', esc_html__( 'Status', 'blueworx-forge' ) );

		if ( '' === $status ) {
			esc_html_e( 'Not recorded', 'blueworx-forge' );
		} else {
			printf(
				'<span class="bw-badge bw-badge--%1$s" data-bwx-site-status="%2$s">%3$s</span>',
				esc_attr( 'active' === $status ? 'success' : 'neutral' ),
				esc_attr( $status ),
				esc_html( self::status_label( $status ) )
			);
		}

		echo '</dd>';

		printf(
			'<dt>%1$s</dt><dd>%2$s</dd>',
			esc_html__( 'Connected since', 'blueworx-forge' ),
			esc_html( $connected > 0 ? gmdate( 'j F Y', $connected ) : __( 'Not recorded', 'blueworx-forge' ) )
		);

		echo '</dl>';

		/*
		 * A fieldnote rather than a banner. It is a footnote about where these
		 * four lines live, not something anybody has to act on — and a page
		 * whose every aside is a banner has no way left to say "read this one".
		 */
		printf(
			'<p class="bw-fieldnote"><i class="bw-icon" data-lucide="info"></i>%s</p>',
			esc_html__( 'These details are held by the studio. This site shows them; it does not keep them.', 'blueworx-forge' )
		);

		Page::panel_close();
	}

	/**
	 * A site status, as a person would read it.
	 *
	 * @param string $status active or inactive.
	 * @return string
	 */
	private static function status_label( string $status ): string {
		$labels = array(
			'active'   => __( 'Active', 'blueworx-forge' ),
			'inactive' => __( 'Inactive', 'blueworx-forge' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * The part of an address somebody recognises.
	 *
	 * Falls back to the whole thing rather than to nothing: an address this
	 * cannot parse is still an address, and showing it whole is better than
	 * showing a blank where the client's own domain should be.
	 *
	 * @param string $url A site address.
	 * @return string
	 */
	private static function host( string $url ): string {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) && '' !== $host ? $host : $url;
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
		Denial::render( $state, Denial::WORKSPACE, 'bwx-workspace-unavailable' );
	}
}
