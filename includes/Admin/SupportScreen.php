<?php
/**
 * A site's commercial position: what it is on, and every hour it has.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Commerce\Assignments;
use Blueworx\Forge\Commerce\Ledger;
use Blueworx\Forge\Commerce\Packages;
use Blueworx\Forge\Commerce\ProRata;
use Blueworx\Forge\Commerce\Support;
use Blueworx\Forge\Commerce\Terms;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Clients;

/**
 * Entitlement, for one site at a time (#146).
 *
 * ARCH-7 puts it in WordPress admin rather than the application: putting a
 * client on a package is configuration the studio does occasionally, not work
 * anybody does daily. It is built from the shared admin design system, like
 * every other studio screen.
 *
 * Three things on one screen, and they belong together because they are three
 * views of the same fact. What the site is on today. Every period it has ever
 * been in, which is the record #146's criterion is reconstructed from. And the
 * hour ledger, which is where the numbers actually live — including the CAP-3
 * post-review adjustment with the reason attached to it, so "why has our
 * balance gone down" has an answer on the screen rather than in a conversation.
 *
 * The pro-rata sum is shown before anything is written (COMM-2), and the figure
 * on the preview is the figure the ledger receives: the same calculation
 * produces both, so they cannot differ.
 */
final class SupportScreen {

	/**
	 * The submenu page slug.
	 */
	public const SLUG = 'blueworx-forge-support';

	/**
	 * Adds the menu entry, under the Forge menu.
	 */
	public static function register(): void {
		add_submenu_page(
			SitesScreen::SLUG,
			__( 'Support', 'blueworx-forge' ),
			__( 'Support', 'blueworx-forge' ),
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
			__( 'Support', 'blueworx-forge' ),
			__( 'Forge', 'blueworx-forge' ),
			__( 'One site at a time: what it is on, every period it has been in, and every hour it has.', 'blueworx-forge' )
		);

		self::result_notice();
		self::picker( $chosen );

		if ( null === $site ) {
			Page::panel_open( __( 'Choose a site', 'blueworx-forge' ), 'support' );

			echo '<div class="bw-empty" data-bwx-support="none-chosen">';
			echo '<i class="bw-icon bw-empty__icon" data-lucide="globe"></i>';
			echo '<p class="bw-empty__title">' . esc_html__( 'No site chosen', 'blueworx-forge' ) . '</p>';
			echo '<p class="bw-empty__text">' . esc_html__( 'Choose a site to see what it is on.', 'blueworx-forge' ) . '</p>';
			echo '</div>';

			Page::panel_close();
			Page::close();

			return;
		}

		self::position( $site );
		self::history( $site );
		self::hours( $site );
		self::sales_forms( $site );
		self::assign_form( $site );

		Page::close();
	}

	/**
	 * The outcome of the last action, if there was one.
	 */
	private static function result_notice(): void {
		// Chosen from the fixed list below, never free text: it comes off the
		// URL, so anything it can say is something anyone can make an
		// administrator's screen say.
		$result = isset( $_GET['bwx-result'] ) ? sanitize_key( wp_unslash( $_GET['bwx-result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reporting the outcome of an action that carried its own nonce.

		// The tone is spelled the design system's way here rather than
		// translated at the call site: it has no "error", it has "danger", and
		// Page::notice quietly falls back to "info" for anything it does not
		// recognise. One spelling per screen, and no mapping step to forget.
		$messages = array(
			'assigned'  => array( 'success', __( 'Assigned. The hours are on the ledger below.', 'blueworx-forge' ) ),
			'suspended' => array( 'success', __( 'Suspended. The remaining hours are untouched.', 'blueworx-forge' ) ),
			'resumed'   => array( 'success', __( 'Back on support.', 'blueworx-forge' ) ),
			'topped-up' => array( 'success', __( 'Hours added. They last twelve months.', 'blueworx-forge' ) ),
			'adjusted'  => array( 'success', __( 'Adjusted. The reason is on the entry.', 'blueworx-forge' ) ),
			'no-reason' => array( 'danger', __( 'An adjustment needs a reason. It is what the client is shown.', 'blueworx-forge' ) ),
			'cancelled' => array( 'success', __( 'Cancelled. The remaining hours are untouched — write them off with an adjustment if that is what was agreed.', 'blueworx-forge' ) ),
			'refused'   => array( 'danger', __( 'That could not be done. Check the package and the date.', 'blueworx-forge' ) ),
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

		// screen-reader-text stays: it is WordPress's accessibility utility
		// rather than styling, and a toolbar has no room for a visible label.
		echo '<label for="bwx-site-pick" class="screen-reader-text">' . esc_html__( 'Site', 'blueworx-forge' ) . '</label>';
		echo '<span class="bw-select">';
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
		echo '</span>';

		// submit_button() rather than a <button>, and this is not cosmetic:
		// the specs click `input[type="submit"]`, so the element is as much
		// part of the contract as a data-bwx hook is. What changes is the class
		// it carries.
		submit_button( __( 'Show', 'blueworx-forge' ), 'bw-btn bw-btn--secondary', 'bwx-show', false );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * What the site is on today.
	 *
	 * Two figures side by side, because they are the two questions anybody
	 * opens this screen with: is this client covered, and how many hours have
	 * they got. Both attributes stay on one element — the specs read the
	 * position and the spendability together, and there is only ever one
	 * balance on the page for the ledger helper to find.
	 *
	 * @param array<string, mixed> $site The site.
	 */
	private static function position( array $site ): void {
		$id      = (string) $site['id'];
		$today   = gmdate( 'Y-m-d', bwx_forge_now() );
		$answer  = Assignments::entitlement_on( $id, $today );
		$balance = Ledger::balance( $id );

		Page::panel_open( (string) $site['name'], 'position' );

		echo '<div class="bw-stats">';

		echo '<div class="bw-stat" data-bwx-support-state="' . esc_attr( (string) $answer['state'] ) . '"';
		echo ' data-bwx-may-use-hours="' . esc_attr( $answer['may_use_hours'] ? 'yes' : 'no' ) . '">';
		echo '<p class="bw-stat__label">' . esc_html__( 'Position', 'blueworx-forge' ) . '</p>';
		echo '<p class="bw-stat__value">' . esc_html( Support::label( (string) $answer['state'] ) ) . '</p>';
		echo '<p class="bw-stat__foot">';
		echo esc_html(
			$answer['may_use_hours']
				? __( 'Hours can be spent.', 'blueworx-forge' )
				: __( 'Hours cannot be spent.', 'blueworx-forge' )
		);
		echo '</p>';
		echo '</div>';

		echo '<div class="bw-stat" data-bwx-balance="' . esc_attr( (string) $balance ) . '">';
		echo '<p class="bw-stat__label">' . esc_html__( 'Hours left', 'blueworx-forge' ) . '</p>';
		echo '<p class="bw-stat__value">' . esc_html( number_format( $balance, 2 ) ) . '</p>';
		echo '<p class="bw-stat__foot">';

		if ( '' !== (string) $answer['ends_on'] ) {
			printf(
				/* translators: %s: a date. */
				esc_html__( 'Covered until %s.', 'blueworx-forge' ),
				esc_html( (string) $answer['ends_on'] )
			);
		} else {
			echo esc_html__( 'No end date on the record.', 'blueworx-forge' );
		}

		echo '</p>';
		echo '</div>';

		echo '</div>';

		self::position_actions( $id, (string) $answer['state'], $today );

		Page::panel_close();
	}

	/**
	 * Suspend, resume or cancel, depending on where the site is.
	 *
	 * Only the moves that mean something are drawn. A "resume" on a site that
	 * is not suspended is a control that exists to be refused, and being shown
	 * a way through and then told no is worse than never being shown one.
	 *
	 * Suspend or resume comes first and cancel second, in that order: the specs
	 * reach the first dated field on the page by position.
	 *
	 * @param string $id    The site.
	 * @param string $state Its state today.
	 * @param string $today YYYY-MM-DD.
	 */
	private static function position_actions( string $id, string $state, string $today ): void {
		if ( in_array( $state, array( Support::NONE, Support::LAPSED ), true ) ) {
			return;
		}

		echo '<div class="bw-card__actions">';

		if ( Support::SUSPENDED === $state ) {
			self::action_form( $id, 'bwx_forge_resume_support', __( 'Resume', 'blueworx-forge' ), $today, 'bwx-resume', 'bw-btn bw-btn--secondary' );
		} else {
			self::action_form( $id, 'bwx_forge_suspend_support', __( 'Suspend', 'blueworx-forge' ), $today, 'bwx-suspend', 'bw-btn bw-btn--secondary' );
		}

		self::action_form( $id, 'bwx_forge_cancel_support', __( 'Cancel', 'blueworx-forge' ), $today, 'bwx-cancel', 'bw-btn bw-btn--danger' );

		echo '</div>';
	}

	/**
	 * One dated action.
	 *
	 * @param string $id           The site.
	 * @param string $action       The admin-post action.
	 * @param string $label        The button.
	 * @param string $today        YYYY-MM-DD.
	 * @param string $name         The button's name, for tests and for tab order.
	 * @param string $button_class The button's whole class name.
	 */
	private static function action_form( string $id, string $action, string $label, string $today, string $name, string $button_class ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="site" value="' . esc_attr( $id ) . '">';
		echo '<input type="date" class="bw-input" name="from" value="' . esc_attr( $today ) . '" aria-label="' . esc_attr( $label ) . '">';
		submit_button( $label, $button_class, $name, false );
		echo '</form>';
	}

	/**
	 * Every period the site has been in.
	 *
	 * The record #146's criterion is reconstructed from, shown rather than
	 * tucked away — a history nobody can see is a promise nobody can check.
	 *
	 * A table, and the cells are in the order they have always been in: the
	 * support specs count the rows and read their state, and those specs are
	 * the regression net proving this rebuild changed the look and nothing else.
	 *
	 * @param array<string, mixed> $site The site.
	 */
	private static function history( array $site ): void {
		$periods = Assignments::for_site( (string) $site['id'] );

		Page::panel_open( __( 'Every period', 'blueworx-forge' ), 'periods' );

		if ( array() === $periods ) {
			echo '<div class="bw-empty" data-bwx-periods="0">';
			echo '<i class="bw-icon bw-empty__icon" data-lucide="calendar"></i>';
			echo '<p class="bw-empty__title">' . esc_html__( 'Never on a package', 'blueworx-forge' ) . '</p>';
			echo '<p class="bw-empty__text">' . esc_html__( 'This site has never been on a package.', 'blueworx-forge' ) . '</p>';
			echo '</div>';

			Page::panel_close();

			return;
		}

		echo '<div class="bw-tablescroll">';
		echo '<table class="bw-table" data-bwx-periods="' . esc_attr( (string) count( $periods ) ) . '"><thead><tr>';
		echo '<th>' . esc_html__( 'From', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'To', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Position', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Package', 'blueworx-forge' ) . '</th>';
		echo '<th class="bw-table__num">' . esc_html__( 'Hours granted', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Why it ended', 'blueworx-forge' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $periods as $period ) {
			$version = Packages::version( (string) $period['package_version_id'] );

			echo '<tr data-bwx-period="' . esc_attr( (string) $period['id'] ) . '"';
			echo ' data-bwx-period-state="' . esc_attr( (string) $period['state'] ) . '">';
			echo '<td class="bw-table__primary">' . esc_html( (string) $period['starts_on'] ) . '</td>';
			echo '<td>' . esc_html( '' !== (string) $period['ends_on'] ? (string) $period['ends_on'] : '—' ) . '</td>';
			echo '<td>' . self::state_badge( (string) $period['state'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- state_badge escapes everything it writes.
			echo '<td>' . esc_html( null === $version ? '—' : (string) $version['name'] . ' v' . (string) $version['version'] ) . '</td>';
			echo '<td class="bw-table__num">' . esc_html( number_format( (float) $period['hours_granted'], 2 ) ) . '</td>';
			echo '<td>' . esc_html( '' !== (string) $period['ended_because'] ? (string) $period['ended_because'] : '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

		Page::panel_close();
	}

	/**
	 * A period's state, toned by what it means.
	 *
	 * Whole class names rather than a stem with the tone appended, so the admin
	 * UI check can read them. Nothing here is a fault — a suspension is a
	 * decision somebody made and a period yet to start is one somebody
	 * arranged — so nothing is toned danger, and a period in force needs no
	 * colour at all.
	 *
	 * @param string $state One of Support's period states.
	 * @return string
	 */
	private static function state_badge( string $state ): string {
		$classes = array(
			Support::ACTIVE    => 'bw-badge',
			Support::SCHEDULED => 'bw-badge bw-badge--neutral',
			Support::SUSPENDED => 'bw-badge bw-badge--neutral',
		);

		return sprintf(
			'<span class="%1$s">%2$s</span>',
			esc_attr( $classes[ $state ] ?? 'bw-badge bw-badge--neutral' ),
			esc_html( Support::label( $state ) )
		);
	}

	/**
	 * The hour ledger, entry by entry.
	 *
	 * Including the reason on an adjustment, which is what CAP-3 and COMM-3
	 * ask for: a post-review charge the client can see, with what it was for.
	 *
	 * The total row carries no data-bwx hook of its own, deliberately: the
	 * balance is read from the figure at the top of the screen, and a second
	 * element wearing the same attribute is one the specs cannot tell apart
	 * from the first.
	 *
	 * @param array<string, mixed> $site The site.
	 */
	private static function hours( array $site ): void {
		$entries = Ledger::for_site( (string) $site['id'] );

		Page::panel_open( __( 'Every hour', 'blueworx-forge' ), 'ledger' );

		if ( array() === $entries ) {
			echo '<div class="bw-empty" data-bwx-ledger="0">';
			echo '<i class="bw-icon bw-empty__icon" data-lucide="clock"></i>';
			echo '<p class="bw-empty__title">' . esc_html__( 'Nothing on the ledger', 'blueworx-forge' ) . '</p>';
			echo '<p class="bw-empty__text">' . esc_html__( 'Nothing has happened to this site\'s hours yet.', 'blueworx-forge' ) . '</p>';
			echo '</div>';

			Page::panel_close();

			return;
		}

		echo '<div class="bw-tablescroll">';
		echo '<table class="bw-table" data-bwx-ledger="' . esc_attr( (string) count( $entries ) ) . '"><thead><tr>';
		echo '<th>' . esc_html__( 'When', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'What', 'blueworx-forge' ) . '</th>';
		echo '<th class="bw-table__num">' . esc_html__( 'Hours', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Why', 'blueworx-forge' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			echo '<tr data-bwx-entry="' . esc_attr( (string) $entry['event_type'] ) . '"';
			echo ' data-bwx-entry-hours="' . esc_attr( (string) $entry['hours'] ) . '"';

			/*
			 * What the entry was against (#158). On screen it is what turns
			 * "minus thirteen hours" into "that piece of work", which is the
			 * question anybody reading this table is actually asking — and it
			 * is what lets the drill-down be checked against the ledger rather
			 * than taken on trust.
			 */
			echo ' data-bwx-entry-source="' . esc_attr( (string) $entry['source_type'] . ':' . (string) $entry['source_id'] ) . '">';
			echo '<td>' . esc_html( gmdate( 'Y-m-d', (int) $entry['occurred_at'] ) ) . '</td>';
			echo '<td class="bw-table__primary">' . esc_html( (string) $entry['event_type'] ) . '</td>';
			echo '<td class="bw-table__num">' . esc_html( number_format( (float) $entry['hours'], 2 ) ) . '</td>';
			echo '<td>' . esc_html( '' !== (string) $entry['reason'] ? (string) $entry['reason'] : '—' ) . '</td>';
			echo '</tr>';
		}

		echo '<tr class="bw-table__total">';
		echo '<td class="bw-table__total-label" colspan="2">' . esc_html__( 'Left', 'blueworx-forge' ) . '</td>';
		echo '<td class="bw-table__num">' . esc_html( number_format( Ledger::balance( (string) $site['id'] ), 2 ) ) . '</td>';
		echo '<td></td>';
		echo '</tr>';

		echo '</tbody></table>';
		echo '</div>';

		Page::panel_close();
	}

	/**
	 * Selling more hours, and correcting the record (#157).
	 *
	 * Two panels rather than one, because they are two different things and the
	 * ledger has to be able to tell them apart afterwards. A top-up is hours
	 * somebody bought, with an expiry of their own; an adjustment is a decision
	 * with a reason, going either way. A write-off entered as a negative top-up
	 * would still add up and would no longer say what happened.
	 *
	 * @param array<string, mixed> $site The site.
	 */
	private static function sales_forms( array $site ): void {
		Page::panel_open( __( 'Sell more hours', 'blueworx-forge' ), 'top-up' );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_top_up' );
		echo '<input type="hidden" name="action" value="bwx_forge_top_up">';
		echo '<input type="hidden" name="site" value="' . esc_attr( (string) $site['id'] ) . '">';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-top-up-hours">' . esc_html__( 'Hours', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control">';
		echo '<input type="number" step="0.25" min="0.25" class="bw-input" id="bwx-top-up-hours" name="hours" required>';
		echo '</div></div>';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-top-up-note">' . esc_html__( 'What was bought', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control">';
		echo '<input type="text" class="bw-input" id="bwx-top-up-note" name="reason">';
		echo '</div></div>';

		echo '<p class="bw-fieldnote">' . esc_html__( 'Bought hours last twelve months from today, and are used after the package\'s own (COMM-4).', 'blueworx-forge' ) . '</p>';

		// bw-card__actions rather than the design system's save bar: that bar
		// is fixed to the bottom of the window, and this screen has four forms
		// on it. Four bars would be four things fighting for one strip.
		echo '<div class="bw-card__actions">';
		submit_button( __( 'Add hours', 'blueworx-forge' ), 'bw-btn bw-btn--secondary', 'bwx-top-up', false );
		echo '</div>';

		echo '</form>';

		Page::panel_close();

		Page::panel_open( __( 'Correct the record', 'blueworx-forge' ), 'adjust' );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_adjust' );
		echo '<input type="hidden" name="action" value="bwx_forge_adjust">';
		echo '<input type="hidden" name="site" value="' . esc_attr( (string) $site['id'] ) . '">';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-adjust-hours">' . esc_html__( 'Hours, negative to take away', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control">';
		echo '<input type="number" step="0.25" class="bw-input" id="bwx-adjust-hours" name="hours" required>';
		echo '</div></div>';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-adjust-reason">' . esc_html__( 'Reason', 'blueworx-forge' );
		echo ' <span class="bw-formrow__req" aria-hidden="true">*</span></label>';
		echo '<div class="bw-formrow__control">';
		echo '<input type="text" class="bw-input" id="bwx-adjust-reason" name="reason" required>';
		echo '<p class="bw-formrow__help">' . esc_html__( 'The reason is not optional: it is what the client is shown, and what anybody asking six months later has to go on.', 'blueworx-forge' ) . '</p>';
		echo '</div></div>';

		echo '<div class="bw-card__actions">';
		submit_button( __( 'Adjust', 'blueworx-forge' ), 'bw-btn bw-btn--secondary', 'bwx-adjust', false );
		echo '</div>';

		echo '</form>';

		Page::panel_close();
	}

	/**
	 * Putting the site on a package, with the sum shown first.
	 *
	 * @param array<string, mixed> $site The site.
	 */
	private static function assign_form( array $site ): void {
		$packages = Packages::all( Terms::ACTIVE );

		if ( array() === $packages ) {
			Page::panel_open( __( 'Put this site on a package', 'blueworx-forge' ), 'assign' );

			echo '<div class="bw-empty" data-bwx-assignable="0">';
			echo '<i class="bw-icon bw-empty__icon" data-lucide="package"></i>';
			echo '<p class="bw-empty__title">' . esc_html__( 'Nothing to put it on', 'blueworx-forge' ) . '</p>';
			echo '<p class="bw-empty__text">' . esc_html__( 'There are no packages on offer. Add one on the Support packages screen first.', 'blueworx-forge' ) . '</p>';
			echo '</div>';

			Page::panel_close();

			return;
		}

		$versions = Packages::current_versions( array_column( $packages, 'id' ) );
		$today    = gmdate( 'Y-m-d', bwx_forge_now() );

		Page::panel_open(
			__( 'Put this site on a package', 'blueworx-forge' ),
			'assign',
			array( 'data-bwx-assignable' => (string) count( $packages ) )
		);

		wp_nonce_field( 'bwx_forge_assign_support' );
		echo '<input type="hidden" name="action" value="bwx_forge_assign_support">';
		echo '<input type="hidden" name="site" value="' . esc_attr( (string) $site['id'] ) . '">';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-assign-package">' . esc_html__( 'Package', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control"><span class="bw-select">';
		echo '<select id="bwx-assign-package" name="package_version" class="bw-select__el">';

		foreach ( $packages as $package ) {
			$version = $versions[ (string) $package['id'] ] ?? array();

			if ( array() === $version ) {
				continue;
			}

			printf(
				'<option value="%1$s">%2$s</option>',
				esc_attr( (string) $version['id'] ),
				esc_html(
					sprintf(
						/* translators: 1: package name, 2: hours, 3: currency, 4: price. */
						__( '%1$s — %2$s hours, %3$s %4$s a year', 'blueworx-forge' ),
						(string) $version['name'],
						number_format( (float) $version['hours'], 2 ),
						(string) $version['currency'],
						number_format( (float) $version['price'] )
					)
				)
			);
		}

		echo '</select>';
		echo '<i class="bw-icon bw-select__arrow" data-lucide="chevron-down"></i>';
		echo '</span></div></div>';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-assign-from">' . esc_html__( 'From', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control">';
		echo '<input type="date" class="bw-input" id="bwx-assign-from" name="starts_on" value="' . esc_attr( $today ) . '">';
		echo '</div></div>';

		/*
		 * COMM-1: an ordinary assignment starts its own twelve-month term and
		 * gets the whole package. Pro-rata is for the client who asked to renew
		 * alongside everything else, which is why it is a deliberate choice
		 * here rather than something that happens quietly whenever the dates
		 * are not a round year.
		 */
		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-assign-until">' . esc_html__( 'Aligned to a renewal date', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control">';
		echo '<input type="date" class="bw-input" id="bwx-assign-until" name="ends_on" value="">';
		echo '<p class="bw-formrow__help">';
		echo esc_html__( 'Leave empty for a full twelve-month term. Set a date to align this client with a shared renewal, and the hours and price are pro-rated to it.', 'blueworx-forge' );
		echo '</p>';
		echo '</div></div>';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-assign-note">' . esc_html__( 'Note', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control">';
		echo '<input type="text" class="bw-input" id="bwx-assign-note" name="note" value="">';
		echo '</div></div>';

		self::preview_of();

		Page::actions_open();
		submit_button( __( 'Assign', 'blueworx-forge' ), 'bw-btn bw-btn--primary', 'bwx-assign', false );
		Page::panel_close();
	}

	/**
	 * The sum, before anything is written (COMM-2).
	 *
	 * Shown for whatever was last previewed, which is how a person checks a
	 * part-year figure without a round trip through the ledger. The number here
	 * is produced by the same call the assignment makes, so agreeing to it and
	 * receiving it cannot come apart.
	 *
	 * A banner rather than a field: it is a statement about what saving would
	 * do, and nothing about it is typed into.
	 */
	private static function preview_of(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a preview writes nothing.
		$version_id = isset( $_GET['preview'] ) ? sanitize_text_field( wp_unslash( $_GET['preview'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a preview writes nothing.
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a preview writes nothing.
		$to = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';

		$version = '' === $version_id ? null : Packages::version( $version_id );

		if ( null === $version || '' === $from || '' === $to ) {
			return;
		}

		$sum = ProRata::preview( $version, $from, $to );

		Page::notice(
			'info',
			sprintf(
				/* translators: 1: days covered, 2: days in a full term, 3: hours, 4: currency, 5: price. */
				__( '%1$d days of %2$d: %3$s hours, %4$s %5$s.', 'blueworx-forge' ),
				(int) $sum['days'],
				(int) $sum['term_days'],
				number_format( (float) $sum['hours'], 2 ),
				(string) $sum['currency'],
				number_format( (float) $sum['price'] )
			),
			array(
				'data-bwx-preview'       => '1',
				'data-bwx-preview-hours' => (string) $sum['hours'],
				'data-bwx-preview-days'  => (string) $sum['days'],
			)
		);
	}
}
