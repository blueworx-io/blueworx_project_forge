<?php
/**
 * A site's standing meetings, and what has become of them.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Meetings\Diary;
use Blueworx\Forge\Meetings\Hours;
use Blueworx\Forge\Meetings\MeetingHours;
use Blueworx\Forge\Meetings\Occurrence;
use Blueworx\Forge\Meetings\Recurrence;
use Blueworx\Forge\Meetings\Series;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Clients;
use Blueworx\Forge\Tenancy\Users;

/**
 * #152 to #155, MEET-1. Where support meetings are set up and settled.
 *
 * A plain WordPress admin page rather than part of the React application, per
 * ARCH-7: a standing meeting is something you configure about a client, like
 * their package and their people, not work anybody moves across a board.
 *
 * **The screen is where the hours get settled.** There is no cron in this
 * plugin, so {@see Hours::reconcile_site()} runs on render — which is the
 * moment somebody is actually looking at a client's meetings, and therefore the
 * moment their balance most needs to be right. It is idempotent, so looking
 * twice costs a read and writes nothing.
 *
 * The list shows the next twelve weeks, which is deliberately the same horizon
 * hours are reserved over (MEET-4): what a person can see and what the balance
 * has committed are then the same set of meetings, and a meeting further out
 * cannot be quietly holding hours nobody is shown.
 */
final class MeetingsScreen {

	/**
	 * The submenu page slug.
	 */
	public const SLUG = 'blueworx-forge-meetings';

	/**
	 * Adds the menu entry, under the Forge menu.
	 */
	public static function register(): void {
		add_submenu_page(
			SitesScreen::SLUG,
			__( 'Meetings', 'blueworx-forge' ),
			__( 'Meetings', 'blueworx-forge' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * This screen's URL, for one site and optionally a result.
	 *
	 * @param string $site_id The site being looked at, or ''.
	 * @param string $result  A result code, or ''.
	 * @return string
	 */
	public static function url( string $site_id = '', string $result = '' ): string {
		$url = admin_url( 'admin.php?page=' . self::SLUG );

		if ( '' !== $site_id ) {
			$url = add_query_arg( 'site', $site_id, $url );
		}

		return '' === $result ? $url : add_query_arg( 'bwx-result', $result, $url );
	}

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing which site to look at changes nothing.
		$chosen = isset( $_GET['site'] ) ? sanitize_text_field( wp_unslash( $_GET['site'] ) ) : '';
		$site   = '' === $chosen ? null : ClientSites::get( $chosen );

		Page::open(
			__( 'Meetings', 'blueworx-forge' ),
			__( 'Forge', 'blueworx-forge' ),
			__( 'The standing meetings a site has, the twelve weeks they imply, and the hours those are holding.', 'blueworx-forge' )
		);

		self::result_notice();
		self::picker( $chosen );

		if ( null === $site ) {
			echo '<div class="bw-empty" data-bwx-meetings="none-chosen">';
			echo '<i class="bw-icon bw-empty__icon" data-lucide="calendar"></i>';
			echo '<p class="bw-empty__title">' . esc_html__( 'Choose a site to see its meetings', 'blueworx-forge' ) . '</p>';
			echo '<p class="bw-empty__text">' . esc_html__( 'Its standing meetings, and the next twelve weeks of them, appear here.', 'blueworx-forge' ) . '</p>';
			echo '</div>';

			Page::close();

			return;
		}

		/*
		 * Settled before anything is drawn, so the hours shown are the hours
		 * held. A screen that reported a position it had not yet brought up to
		 * date would be the one place the balance and the meetings disagree.
		 */
		Hours::reconcile_site( (string) $site['id'], get_current_user_id() );

		self::series_list( $site );
		self::coming_up( $site );
		self::add_form( $site );

		Page::close();
	}

	/**
	 * The outcome of the last action, if there was one.
	 */
	private static function result_notice(): void {
		// A result code chosen from the fixed list below, never free text: it
		// comes off the URL, so anything it can say is something anyone can
		// make an administrator's screen say.
		$result = isset( $_GET['bwx-result'] ) ? sanitize_key( wp_unslash( $_GET['bwx-result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reporting the outcome of an action that carried its own nonce.

		// Toned danger rather than error: the design system has no error tone,
		// and the spelling is fixed here so there is no mapping step between
		// this list and Page::notice to forget.
		$messages = array(
			'added'     => array( 'success', __( 'Series added. Its meetings are below.', 'blueworx-forge' ) ),
			'ended'     => array( 'success', __( 'Series ended. Past meetings are untouched and any held hours have been given back.', 'blueworx-forge' ) ),
			'moved'     => array( 'success', __( 'Moved. Only that meeting changed.', 'blueworx-forge' ) ),
			'held'      => array( 'success', __( 'Marked held. The hours have been drawn.', 'blueworx-forge' ) ),
			'cancelled' => array( 'success', __( 'Cancelled. No hours were charged.', 'blueworx-forge' ) ),
			'no-show'   => array( 'success', __( 'Recorded. No hours were charged.', 'blueworx-forge' ) ),
			'invalid'   => array( 'danger', __( 'That series could not be saved — check the highlighted fields.', 'blueworx-forge' ) ),
			'refused'   => array( 'danger', __( 'That could not be done.', 'blueworx-forge' ) ),
			'unknown'   => array( 'danger', __( 'There is no such site.', 'blueworx-forge' ) ),
		);

		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}

		Page::notice(
			$messages[ $result ][0],
			$messages[ $result ][1],
			array( 'data-bwx-result' => $result )
		);
	}

	/**
	 * Which site is being looked at.
	 *
	 * @param string $chosen The site id, or ''.
	 */
	private static function picker( string $chosen ): void {
		$sites   = ClientSites::all( null );
		$clients = array_column( Clients::all( null ), null, 'id' );

		echo '<div class="bw-toolbar bw-toolbar--card">';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="bw-toolbar__group">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		// screen-reader-text is WordPress's accessibility utility rather than
		// its styling, and the design system has no counterpart, so it stays.
		echo '<label for="bwx-site-pick" class="screen-reader-text">' . esc_html__( 'Site', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-toolbar__search"><span class="bw-select">';
		echo '<select id="bwx-site-pick" name="site" class="bw-select__el" data-bwx-site-picker="1">';
		echo '<option value="">' . esc_html__( '— choose a site —', 'blueworx-forge' ) . '</option>';

		foreach ( $sites as $site ) {
			$client = $clients[ (string) $site['client_id'] ] ?? array();
			$label  = (string) $site['name'];

			if ( isset( $client['display_name'] ) && '' !== (string) $client['display_name'] ) {
				$label = (string) $client['display_name'] . ' — ' . $label;
			}

			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $site['id'] ),
				selected( $chosen, (string) $site['id'], false ),
				esc_html( $label )
			);
		}

		echo '</select>';
		echo '<i class="bw-icon bw-select__arrow" data-lucide="chevron-down"></i>';
		echo '</span></div>';

		// submit_button() rather than a <button>, and this is not cosmetic: the
		// specs click `input[type="submit"]`, so the element is as much part of
		// the contract as a data-bwx hook is. What changes is the class it
		// carries.
		submit_button( __( 'Show', 'blueworx-forge' ), 'bw-btn bw-btn--secondary', 'bwx-show', false );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * The arrangements this site has.
	 *
	 * @param array<string, mixed> $site The site.
	 */
	private static function series_list( array $site ): void {
		$all = Series::for_site( (string) $site['id'] );

		Page::panel_open( __( 'Standing meetings', 'blueworx-forge' ), 'series' );

		if ( array() === $all ) {
			echo '<div class="bw-empty" data-bwx-series="0">';
			echo '<i class="bw-icon bw-empty__icon" data-lucide="calendar"></i>';
			echo '<p class="bw-empty__title">' . esc_html__( 'No standing meetings', 'blueworx-forge' ) . '</p>';
			echo '<p class="bw-empty__text">' . esc_html__( 'This site has no standing meetings. Add one below.', 'blueworx-forge' ) . '</p>';
			echo '</div>';

			Page::panel_close();

			return;
		}

		echo '<div class="bw-tablescroll">';
		echo '<table class="bw-table" data-bwx-series="' . esc_attr( (string) count( $all ) ) . '"><thead><tr>';
		echo '<th>' . esc_html__( 'What', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'How often', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'When', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Host', 'blueworx-forge' ) . '</th>';
		echo '<th class="bw-table__num">' . esc_html__( 'Hours each', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'State', 'blueworx-forge' ) . '</th>';
		echo '<th class="bw-table__actions"></th>';
		echo '</tr></thead><tbody>';

		foreach ( $all as $series ) {
			$host    = Users::get( (string) $series['host_user_id'] );
			$running = Series::ACTIVE === (string) $series['state'];

			echo '<tr data-bwx-series-row="' . esc_attr( (string) $series['id'] ) . '" data-bwx-series-state="' . esc_attr( (string) $series['state'] ) . '">';
			echo '<td class="bw-table__primary">' . esc_html( (string) $series['title'] ) . '</td>';
			echo '<td>' . esc_html( (string) $series['frequency_label'] ) . '</td>';
			echo '<td>' . esc_html( (string) $series['time_of_day'] . ' ' . $series['timezone'] . ', ' . $series['duration_mins'] . ' min' ) . '</td>';
			echo '<td>' . esc_html( null === $host ? '—' : (string) $host['display_name'] ) . '</td>';
			echo '<td class="bw-table__num" data-bwx-series-hours="' . esc_attr( (string) $series['hours_each'] ) . '">' . esc_html( number_format( (float) $series['hours_each'], 2 ) ) . '</td>';

			// Whole class names rather than a stem with the tone appended: the
			// admin UI check reads the classes a screen writes, and one
			// assembled from a variable is one it cannot see. An ended series
			// is neutral rather than danger — ending one is a decision
			// somebody made, not a fault.
			echo '<td>';

			if ( $running ) {
				echo '<span class="bw-badge">' . esc_html__( 'Running', 'blueworx-forge' ) . '</span>';
			} else {
				echo '<span class="bw-badge bw-badge--neutral">' . esc_html__( 'Ended', 'blueworx-forge' ) . '</span>';
			}

			echo '</td>';
			echo '<td class="bw-table__actions"><div class="bw-rowactions">';

			if ( $running ) {
				self::end_form( (string) $site['id'], $series );
			}

			echo '</div></td></tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

		Page::panel_close();
	}

	/**
	 * The next twelve weeks of meetings, and what can be done to each.
	 *
	 * @param array<string, mixed> $site The site.
	 */
	private static function coming_up( array $site ): void {
		$today    = gmdate( 'Y-m-d' );
		$meetings = Diary::for_site( (string) $site['id'], $today, MeetingHours::horizon_end( $today ) );

		Page::panel_open( __( 'The next twelve weeks', 'blueworx-forge' ), 'meetings' );

		if ( array() === $meetings ) {
			echo '<div class="bw-empty" data-bwx-meetings="0">';
			echo '<i class="bw-icon bw-empty__icon" data-lucide="calendar"></i>';
			echo '<p class="bw-empty__title">' . esc_html__( 'Nothing is coming up', 'blueworx-forge' ) . '</p>';
			echo '<p class="bw-empty__text">' . esc_html__( 'No meeting falls inside the next twelve weeks.', 'blueworx-forge' ) . '</p>';
			echo '</div>';

			Page::panel_close();

			return;
		}

		echo '<div class="bw-tablescroll">';
		echo '<table class="bw-table" data-bwx-meetings="' . esc_attr( (string) count( $meetings ) ) . '"><thead><tr>';
		echo '<th>' . esc_html__( 'When', 'blueworx-forge' ) . '</th>';
		echo '<th class="bw-table__num">' . esc_html__( 'Hours', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'What happened', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Hours held', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Move it', 'blueworx-forge' ) . '</th>';
		// Not bw-table__actions, on either header or cell: that class hides its
		// column until the row is hovered, which is right for a secondary row
		// action and wrong for the control this whole screen exists for.
		echo '<th>' . esc_html__( 'Settle it', 'blueworx-forge' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $meetings as $meeting ) {
			$state = self::ledger_state_of( $meeting );

			echo '<tr data-bwx-meeting="' . esc_attr( (string) $meeting['on'] ) . '" data-bwx-meeting-status="' . esc_attr( (string) $meeting['status'] ) . '">';
			echo '<td class="bw-table__primary">' . esc_html( (string) $meeting['on'] . ' ' . $meeting['at'] );

			// The line under the date rather than beside it: a meeting on an
			// odd day explains itself, and the explanation is secondary to the
			// date it is explaining.
			if ( ! empty( $meeting['moved'] ) ) {
				echo '<span class="bw-table__sub" data-bwx-moved-from="' . esc_attr( (string) $meeting['excepted_from'] ) . '">';
				printf(
					/* translators: %s: the date the meeting was originally on. */
					esc_html__( '(moved from %s)', 'blueworx-forge' ),
					esc_html( (string) $meeting['excepted_from'] )
				);
				echo '</span>';
			}

			echo '</td>';
			echo '<td class="bw-table__num">' . esc_html( number_format( (float) $meeting['planned_hours'], 2 ) ) . '</td>';
			echo '<td>' . esc_html( Occurrence::label( (string) $meeting['status'] ) ) . '</td>';
			echo '<td data-bwx-ledger-state="' . esc_attr( $state ) . '">';
			echo '<span class="' . esc_attr( self::ledger_badge_class( $state ) ) . '">' . esc_html( self::ledger_label( $state ) ) . '</span>';
			echo '</td>';
			echo '<td>';
			self::move_form( (string) $site['id'], $meeting );
			echo '</td><td><div class="bw-rowactions">';
			self::settle_forms( (string) $site['id'], $meeting );
			echo '</div></td></tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

		Page::panel_close();
	}

	/**
	 * What the ledger holds against one meeting on the list.
	 *
	 * A meeting with no row of its own has nothing held against it — it is a
	 * forecast, and saying so is more use than leaving the column empty.
	 *
	 * @param array<string, mixed> $meeting One merged occurrence.
	 * @return string
	 */
	private static function ledger_state_of( array $meeting ): string {
		if ( '' === (string) ( $meeting['id'] ?? '' ) ) {
			return MeetingHours::FORECAST;
		}

		$stored = Diary::get( (string) $meeting['id'] );

		return null === $stored ? MeetingHours::FORECAST : (string) $stored['ledger_state'];
	}

	/**
	 * How a ledger state reads to a person.
	 *
	 * @param string $state One of MeetingHours' four.
	 * @return string
	 */
	private static function ledger_label( string $state ): string {
		switch ( $state ) {
			case MeetingHours::RESERVED:
				return __( 'Set aside', 'blueworx-forge' );
			case MeetingHours::USED:
				return __( 'Spent', 'blueworx-forge' );
			case MeetingHours::RELEASED:
				return __( 'Given back', 'blueworx-forge' );
			default:
				return __( 'Forecast only', 'blueworx-forge' );
		}
	}

	/**
	 * How a ledger state is toned.
	 *
	 * Whole class names rather than a stem with the tone appended: the admin UI
	 * check reads the classes a screen writes, and one assembled from a
	 * variable is one it cannot see. Hours that are actually held or spent are
	 * the plain badge; a forecast and a release are neutral, because neither is
	 * holding anything.
	 *
	 * @param string $state One of MeetingHours' four.
	 * @return string
	 */
	private static function ledger_badge_class( string $state ): string {
		switch ( $state ) {
			case MeetingHours::RESERVED:
			case MeetingHours::USED:
				return 'bw-badge';
			default:
				return 'bw-badge bw-badge--neutral';
		}
	}

	/**
	 * Moving one meeting.
	 *
	 * @param string               $site_id The site.
	 * @param array<string, mixed> $meeting One merged occurrence.
	 */
	private static function move_form( string $site_id, array $meeting ): void {
		if ( Occurrence::settled( (string) $meeting['status'] ) ) {
			echo '—';

			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_move_meeting' );
		echo '<input type="hidden" name="action" value="bwx_forge_move_meeting">';
		echo '<input type="hidden" name="site" value="' . esc_attr( $site_id ) . '">';
		echo '<input type="hidden" name="series" value="' . esc_attr( (string) $meeting['series_id'] ) . '">';
		echo '<input type="hidden" name="slot" value="' . esc_attr( self::slot_of( $meeting ) ) . '">';
		echo '<div class="bw-rowactions">';
		echo '<input type="date" class="bw-input" name="on" value="' . esc_attr( (string) $meeting['on'] ) . '" aria-label="' . esc_attr__( 'New date', 'blueworx-forge' ) . '">';

		// submit_button() rather than a <button>, and the name stays bwx-move:
		// meetings.spec.js clicks it by name inside the row it belongs to.
		submit_button( __( 'Move', 'blueworx-forge' ), 'bw-btn bw-btn--secondary', 'bwx-move', false );
		echo '</div>';
		echo '</form>';
	}

	/**
	 * Saying what became of one meeting.
	 *
	 * @param string               $site_id The site.
	 * @param array<string, mixed> $meeting One merged occurrence.
	 */
	private static function settle_forms( string $site_id, array $meeting ): void {
		$buttons = array(
			Occurrence::HELD      => __( 'Held', 'blueworx-forge' ),
			Occurrence::CANCELLED => __( 'Cancelled', 'blueworx-forge' ),
			Occurrence::NO_SHOW   => __( 'Nobody came', 'blueworx-forge' ),
		);

		foreach ( $buttons as $status => $label ) {
			if ( $status === (string) $meeting['status'] ) {
				continue;
			}

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'bwx_forge_settle_meeting' );
			echo '<input type="hidden" name="action" value="bwx_forge_settle_meeting">';
			echo '<input type="hidden" name="site" value="' . esc_attr( $site_id ) . '">';
			echo '<input type="hidden" name="series" value="' . esc_attr( (string) $meeting['series_id'] ) . '">';
			echo '<input type="hidden" name="slot" value="' . esc_attr( self::slot_of( $meeting ) ) . '">';
			echo '<input type="hidden" name="status" value="' . esc_attr( $status ) . '">';
			// A <button> rather than submit_button(), as it always has been:
			// the specs reach these by their data-bwx-settle attribute, which
			// an <input> could carry but which nothing here needs changing to
			// prove. Only the class it wears is new.
			printf(
				'<button type="submit" class="bw-btn bw-btn--secondary" data-bwx-settle="%1$s">%2$s</button>',
				esc_attr( $status ),
				esc_html( $label )
			);
			echo '</form>';
		}
	}

	/**
	 * Which slot in the rule a meeting came from.
	 *
	 * A meeting nobody has touched is its own slot; one that has already moved
	 * keeps the slot it was moved from, so moving it twice does not create a
	 * second exception against the second date.
	 *
	 * @param array<string, mixed> $meeting One merged occurrence.
	 * @return string
	 */
	private static function slot_of( array $meeting ): string {
		$from = (string) ( $meeting['excepted_from'] ?? '' );

		return '' !== $from ? $from : (string) $meeting['on'];
	}

	/**
	 * Ending a series.
	 *
	 * @param string               $site_id The site.
	 * @param array<string, mixed> $series  The series.
	 */
	private static function end_form( string $site_id, array $series ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_end_series' );
		echo '<input type="hidden" name="action" value="bwx_forge_end_series">';
		echo '<input type="hidden" name="site" value="' . esc_attr( $site_id ) . '">';
		echo '<input type="hidden" name="series" value="' . esc_attr( (string) $series['id'] ) . '">';
		echo '<input type="hidden" name="record_version" value="' . esc_attr( (string) $series['record_version'] ) . '">';
		// Dressed as a row action, and it is still a posted form: ending a
		// series gives hours back, and an action that changes a client's
		// balance is not something a link should be able to do.
		printf(
			'<button type="submit" class="bw-rowactions__link bw-rowactions__link--danger" id="bwx-end-%1$s">%2$s</button>',
			esc_attr( (string) $series['id'] ),
			esc_html__( 'End', 'blueworx-forge' )
		);
		echo '</form>';
	}

	/**
	 * Starting a series.
	 *
	 * @param array<string, mixed> $site The site.
	 */
	private static function add_form( array $site ): void {
		$client  = Clients::get( (string) $site['client_id'] );
		$people  = Users::all( null );
		$default = null === $client ? 'UTC' : (string) $client['timezone'];

		Page::panel_open( __( 'Add a standing meeting', 'blueworx-forge' ), 'add-series' );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_add_series' );
		echo '<input type="hidden" name="action" value="bwx_forge_add_series">';
		echo '<input type="hidden" name="site" value="' . esc_attr( (string) $site['id'] ) . '">';

		self::text_row( 'title', __( 'What it is called', 'blueworx-forge' ), '' );

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-frequency">' . esc_html__( 'How often', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control"><span class="bw-select">';
		echo '<select id="bwx-frequency" name="frequency" class="bw-select__el">';

		foreach ( Recurrence::FREQUENCIES as $frequency ) {
			printf(
				'<option value="%1$s">%2$s</option>',
				esc_attr( $frequency ),
				esc_html( Recurrence::label( $frequency ) )
			);
		}

		echo '</select>';
		echo '<i class="bw-icon bw-select__arrow" data-lucide="chevron-down"></i>';
		echo '</span></div></div>';

		self::date_row( 'starts_on', __( 'First one', 'blueworx-forge' ), gmdate( 'Y-m-d' ) );
		self::date_row( 'ends_on', __( 'Last one (optional)', 'blueworx-forge' ), '' );
		self::time_row( 'time_of_day', __( 'At', 'blueworx-forge' ), '10:00' );
		self::number_row( 'duration_mins', __( 'For how long, in minutes', 'blueworx-forge' ), '60' );
		self::text_row( 'timezone', __( 'Timezone', 'blueworx-forge' ), $default );

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-host">' . esc_html__( 'Host', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control"><span class="bw-select">';
		echo '<select id="bwx-host" name="host_user_id" class="bw-select__el" required>';
		echo '<option value="">' . esc_html__( '— choose —', 'blueworx-forge' ) . '</option>';

		foreach ( $people as $person ) {
			printf(
				'<option value="%1$s">%2$s</option>',
				esc_attr( (string) $person['id'] ),
				esc_html( (string) $person['display_name'] )
			);
		}

		echo '</select>';
		echo '<i class="bw-icon bw-select__arrow" data-lucide="chevron-down"></i>';
		echo '</span>';
		echo '<p class="bw-formrow__help">' . esc_html__( 'Only the host can mark a meeting held, and that is what draws the hours.', 'blueworx-forge' ) . '</p>';
		echo '</div></div>';

		self::text_row( 'attendees', __( 'Who else comes (a note, not accounts)', 'blueworx-forge' ), '' );
		self::number_row( 'planned_hours', __( 'Hours each, or 0 to work it out from the length', 'blueworx-forge' ), '0' );

		/*
		 * The button sits where the form ends rather than in the design
		 * system's save bar. That bar is fixed to the bottom of the viewport,
		 * and this screen's own controls — settling a meeting, moving one —
		 * sit in a long table above the form; a bar floating over the row
		 * somebody is trying to settle is worse than no bar at all.
		 *
		 * submit_button() rather than a <button>, and the name stays
		 * bwx-add-series: it is also the id, and meetings.spec.js clicks it.
		 */
		echo '<div class="bw-card__actions">';
		submit_button( __( 'Add', 'blueworx-forge' ), 'bw-btn bw-btn--primary', 'bwx-add-series', false );
		echo '</div>';

		echo '</form>';

		Page::panel_close();
	}

	/**
	 * One text field on the add form.
	 *
	 * @param string $name  Field name.
	 * @param string $label How it reads.
	 * @param string $value What it starts as.
	 */
	private static function text_row( string $name, string $label, string $value ): void {
		self::field_row( 'text', $name, $label, $value );
	}

	/**
	 * One date field on the add form.
	 *
	 * @param string $name  Field name.
	 * @param string $label How it reads.
	 * @param string $value What it starts as.
	 */
	private static function date_row( string $name, string $label, string $value ): void {
		self::field_row( 'date', $name, $label, $value );
	}

	/**
	 * One time field on the add form.
	 *
	 * @param string $name  Field name.
	 * @param string $label How it reads.
	 * @param string $value What it starts as.
	 */
	private static function time_row( string $name, string $label, string $value ): void {
		self::field_row( 'time', $name, $label, $value );
	}

	/**
	 * One number field on the add form.
	 *
	 * @param string $name  Field name.
	 * @param string $label How it reads.
	 * @param string $value What it starts as.
	 */
	private static function number_row( string $name, string $label, string $value ): void {
		self::field_row( 'number', $name, $label, $value, ' step="0.25" min="0"' );
	}

	/**
	 * One labelled field on the add form.
	 *
	 * The four helpers above differ only in the input type, and every one of
	 * them writes the id the specs fill by name — bwx-title, bwx-starts_on and
	 * the rest — so the id is built here in one place rather than four.
	 *
	 * @param string $type  Input type.
	 * @param string $name  Field name.
	 * @param string $label How it reads.
	 * @param string $value What it starts as.
	 * @param string $extra Attributes only some types carry, already escaped.
	 */
	private static function field_row( string $type, string $name, string $label, string $value, string $extra = '' ): void {
		printf(
			'<div class="bw-formrow"><label class="bw-formrow__label" for="bwx-%1$s">%2$s</label>'
				. '<div class="bw-formrow__control">'
				. '<input type="%3$s" class="bw-input" id="bwx-%1$s" name="%1$s" value="%4$s"%5$s>'
				. '</div></div>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $value ),
			$extra // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a literal written above, never caller input.
		);
	}
}
