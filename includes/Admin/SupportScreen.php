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
 * anybody does daily.
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

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Forge — support', 'blueworx-forge' ) . '</h1>';

		self::result_notice();
		self::picker( $chosen );

		if ( null === $site ) {
			echo '<p data-bwx-support="none-chosen">' . esc_html__( 'Choose a site to see what it is on.', 'blueworx-forge' ) . '</p>';
			echo '</div>';

			return;
		}

		self::position( $site );
		self::history( $site );
		self::hours( $site );
		self::assign_form( $site );

		echo '</div>';
	}

	/**
	 * The outcome of the last action, if there was one.
	 */
	private static function result_notice(): void {
		// Chosen from the fixed list below, never free text: it comes off the
		// URL, so anything it can say is something anyone can make an
		// administrator's screen say.
		$result = isset( $_GET['bwx-result'] ) ? sanitize_key( wp_unslash( $_GET['bwx-result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reporting the outcome of an action that carried its own nonce.

		$messages = array(
			'assigned'  => array( 'success', __( 'Assigned. The hours are on the ledger below.', 'blueworx-forge' ) ),
			'suspended' => array( 'success', __( 'Suspended. The remaining hours are untouched.', 'blueworx-forge' ) ),
			'resumed'   => array( 'success', __( 'Back on support.', 'blueworx-forge' ) ),
			'cancelled' => array( 'success', __( 'Cancelled. The remaining hours are untouched — write them off with an adjustment if that is what was agreed.', 'blueworx-forge' ) ),
			'refused'   => array( 'error', __( 'That could not be done. Check the package and the date.', 'blueworx-forge' ) ),
			'unknown'   => array( 'error', __( 'There is no such site.', 'blueworx-forge' ) ),
		);

		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s" data-bwx-result="%2$s"><p>%3$s</p></div>',
			esc_attr( $messages[ $result ][0] ),
			esc_attr( $result ),
			esc_html( $messages[ $result ][1] )
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

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<label for="bwx-site-pick" class="screen-reader-text">' . esc_html__( 'Site', 'blueworx-forge' ) . '</label>';
		echo '<select id="bwx-site-pick" name="site" data-bwx-site-picker="1">';
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

		echo '</select> ';
		submit_button( __( 'Show', 'blueworx-forge' ), 'secondary', 'bwx-show', false );
		echo '</form>';
	}

	/**
	 * What the site is on today.
	 *
	 * @param array<string, mixed> $site The site.
	 */
	private static function position( array $site ): void {
		$id     = (string) $site['id'];
		$today  = gmdate( 'Y-m-d', bwx_forge_now() );
		$answer = Assignments::entitlement_on( $id, $today );

		echo '<h2>' . esc_html( (string) $site['name'] ) . '</h2>';
		echo '<p data-bwx-support-state="' . esc_attr( (string) $answer['state'] ) . '"';
		echo ' data-bwx-may-use-hours="' . esc_attr( $answer['may_use_hours'] ? 'yes' : 'no' ) . '">';
		echo '<strong>' . esc_html( Support::label( (string) $answer['state'] ) ) . '</strong>';

		if ( '' !== (string) $answer['ends_on'] ) {
			echo ' — ';
			printf(
				/* translators: %s: a date. */
				esc_html__( 'until %s', 'blueworx-forge' ),
				esc_html( (string) $answer['ends_on'] )
			);
		}

		echo '</p>';

		printf(
			'<p data-bwx-balance="%1$s">%2$s</p>',
			esc_attr( (string) Ledger::balance( $id ) ),
			esc_html(
				sprintf(
					/* translators: %s: a number of hours. */
					__( '%s hours left.', 'blueworx-forge' ),
					number_format( Ledger::balance( $id ), 2 )
				)
			)
		);

		self::position_actions( $id, (string) $answer['state'], $today );
	}

	/**
	 * Suspend, resume or cancel, depending on where the site is.
	 *
	 * Only the moves that mean something are drawn. A "resume" on a site that
	 * is not suspended is a control that exists to be refused, and being shown
	 * a way through and then told no is worse than never being shown one.
	 *
	 * @param string $id    The site.
	 * @param string $state Its state today.
	 * @param string $today YYYY-MM-DD.
	 */
	private static function position_actions( string $id, string $state, string $today ): void {
		if ( in_array( $state, array( Support::NONE, Support::LAPSED ), true ) ) {
			return;
		}

		echo '<div style="display:flex;gap:8px;align-items:flex-start">';

		if ( Support::SUSPENDED === $state ) {
			self::action_form( $id, 'bwx_forge_resume_support', __( 'Resume', 'blueworx-forge' ), $today, 'bwx-resume' );
		} else {
			self::action_form( $id, 'bwx_forge_suspend_support', __( 'Suspend', 'blueworx-forge' ), $today, 'bwx-suspend' );
		}

		self::action_form( $id, 'bwx_forge_cancel_support', __( 'Cancel', 'blueworx-forge' ), $today, 'bwx-cancel' );

		echo '</div>';
	}

	/**
	 * One dated action.
	 *
	 * @param string $id     The site.
	 * @param string $action The admin-post action.
	 * @param string $label  The button.
	 * @param string $today  YYYY-MM-DD.
	 * @param string $name   The button's name, for tests and for tab order.
	 */
	private static function action_form( string $id, string $action, string $label, string $today, string $name ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="site" value="' . esc_attr( $id ) . '">';
		echo '<input type="date" name="from" value="' . esc_attr( $today ) . '" aria-label="' . esc_attr( $label ) . '">';
		submit_button( $label, 'secondary', $name, false );
		echo '</form>';
	}

	/**
	 * Every period the site has been in.
	 *
	 * The record #146's criterion is reconstructed from, shown rather than
	 * tucked away — a history nobody can see is a promise nobody can check.
	 *
	 * @param array<string, mixed> $site The site.
	 */
	private static function history( array $site ): void {
		$periods = Assignments::for_site( (string) $site['id'] );

		echo '<h3>' . esc_html__( 'Every period', 'blueworx-forge' ) . '</h3>';

		if ( array() === $periods ) {
			echo '<p data-bwx-periods="0">' . esc_html__( 'This site has never been on a package.', 'blueworx-forge' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped" data-bwx-periods="' . esc_attr( (string) count( $periods ) ) . '"><thead><tr>';
		echo '<th>' . esc_html__( 'From', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'To', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Position', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Package', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Hours granted', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Why it ended', 'blueworx-forge' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $periods as $period ) {
			$version = Packages::version( (string) $period['package_version_id'] );

			echo '<tr data-bwx-period="' . esc_attr( (string) $period['id'] ) . '"';
			echo ' data-bwx-period-state="' . esc_attr( (string) $period['state'] ) . '">';
			echo '<td>' . esc_html( (string) $period['starts_on'] ) . '</td>';
			echo '<td>' . esc_html( '' !== (string) $period['ends_on'] ? (string) $period['ends_on'] : '—' ) . '</td>';
			echo '<td>' . esc_html( Support::label( (string) $period['state'] ) ) . '</td>';
			echo '<td>' . esc_html( null === $version ? '—' : (string) $version['name'] . ' v' . (string) $version['version'] ) . '</td>';
			echo '<td>' . esc_html( number_format( (float) $period['hours_granted'], 2 ) ) . '</td>';
			echo '<td>' . esc_html( '' !== (string) $period['ended_because'] ? (string) $period['ended_because'] : '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The hour ledger, entry by entry.
	 *
	 * Including the reason on an adjustment, which is what CAP-3 and COMM-3
	 * ask for: a post-review charge the client can see, with what it was for.
	 *
	 * @param array<string, mixed> $site The site.
	 */
	private static function hours( array $site ): void {
		$entries = Ledger::for_site( (string) $site['id'] );

		echo '<h3>' . esc_html__( 'Every hour', 'blueworx-forge' ) . '</h3>';

		if ( array() === $entries ) {
			echo '<p data-bwx-ledger="0">' . esc_html__( 'Nothing has happened to this site\'s hours yet.', 'blueworx-forge' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped" data-bwx-ledger="' . esc_attr( (string) count( $entries ) ) . '"><thead><tr>';
		echo '<th>' . esc_html__( 'When', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'What', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Hours', 'blueworx-forge' ) . '</th>';
		echo '<th>' . esc_html__( 'Why', 'blueworx-forge' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			echo '<tr data-bwx-entry="' . esc_attr( (string) $entry['event_type'] ) . '"';
			echo ' data-bwx-entry-hours="' . esc_attr( (string) $entry['hours'] ) . '">';
			echo '<td>' . esc_html( gmdate( 'Y-m-d', (int) $entry['occurred_at'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) $entry['event_type'] ) . '</td>';
			echo '<td>' . esc_html( number_format( (float) $entry['hours'], 2 ) ) . '</td>';
			echo '<td>' . esc_html( '' !== (string) $entry['reason'] ? (string) $entry['reason'] : '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Putting the site on a package, with the sum shown first.
	 *
	 * @param array<string, mixed> $site The site.
	 */
	private static function assign_form( array $site ): void {
		$packages = Packages::all( Terms::ACTIVE );

		echo '<h3>' . esc_html__( 'Put this site on a package', 'blueworx-forge' ) . '</h3>';

		if ( array() === $packages ) {
			echo '<p data-bwx-assignable="0">';
			echo esc_html__( 'There are no packages on offer. Add one on the Support packages screen first.', 'blueworx-forge' );
			echo '</p>';

			return;
		}

		$versions = Packages::current_versions( array_column( $packages, 'id' ) );
		$today    = gmdate( 'Y-m-d', bwx_forge_now() );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-assignable="' . esc_attr( (string) count( $packages ) ) . '">';
		wp_nonce_field( 'bwx_forge_assign_support' );
		echo '<input type="hidden" name="action" value="bwx_forge_assign_support">';
		echo '<input type="hidden" name="site" value="' . esc_attr( (string) $site['id'] ) . '">';

		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row"><label for="bwx-assign-package">' . esc_html__( 'Package', 'blueworx-forge' ) . '</label></th><td>';
		echo '<select id="bwx-assign-package" name="package_version">';

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

		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="bwx-assign-from">' . esc_html__( 'From', 'blueworx-forge' ) . '</label></th>';
		echo '<td><input type="date" id="bwx-assign-from" name="starts_on" value="' . esc_attr( $today ) . '"></td></tr>';

		/*
		 * COMM-1: an ordinary assignment starts its own twelve-month term and
		 * gets the whole package. Pro-rata is for the client who asked to renew
		 * alongside everything else, which is why it is a deliberate choice
		 * here rather than something that happens quietly whenever the dates
		 * are not a round year.
		 */
		echo '<tr><th scope="row"><label for="bwx-assign-until">' . esc_html__( 'Aligned to a renewal date', 'blueworx-forge' ) . '</label></th>';
		echo '<td><input type="date" id="bwx-assign-until" name="ends_on" value="">';
		echo '<p class="description">';
		echo esc_html__( 'Leave empty for a full twelve-month term. Set a date to align this client with a shared renewal, and the hours and price are pro-rated to it.', 'blueworx-forge' );
		echo '</p></td></tr>';

		echo '<tr><th scope="row"><label for="bwx-assign-note">' . esc_html__( 'Note', 'blueworx-forge' ) . '</label></th>';
		echo '<td><input type="text" class="regular-text" id="bwx-assign-note" name="note" value=""></td></tr>';

		echo '</tbody></table>';

		self::preview_of();

		submit_button( __( 'Assign', 'blueworx-forge' ), 'primary', 'bwx-assign', false );
		echo '</form>';
	}

	/**
	 * The sum, before anything is written (COMM-2).
	 *
	 * Shown for whatever was last previewed, which is how a person checks a
	 * part-year figure without a round trip through the ledger. The number here
	 * is produced by the same call the assignment makes, so agreeing to it and
	 * receiving it cannot come apart.
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

		echo '<div class="notice notice-info inline" data-bwx-preview="1"';
		echo ' data-bwx-preview-hours="' . esc_attr( (string) $sum['hours'] ) . '"';
		echo ' data-bwx-preview-days="' . esc_attr( (string) $sum['days'] ) . '"><p>';
		echo esc_html(
			sprintf(
				/* translators: 1: days covered, 2: days in a full term, 3: hours, 4: currency, 5: price. */
				__( '%1$d days of %2$d: %3$s hours, %4$s %5$s.', 'blueworx-forge' ),
				(int) $sum['days'],
				(int) $sum['term_days'],
				number_format( (float) $sum['hours'], 2 ),
				(string) $sum['currency'],
				number_format( (float) $sum['price'] )
			)
		);
		echo '</p></div>';
	}
}
