<?php
/**
 * What each person's time actually is.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

use Blueworx\Forge\Capacity\Availability;
use Blueworx\Forge\Capacity\Patterns;
use Blueworx\Forge\Capacity\Unavailability;
use Blueworx\Forge\Tenancy\Users;

/**
 * Entering CAP-1's working hours and time off (#136).
 *
 * Its own screen rather than another section on People, because People is about
 * who somebody is and what they can reach, and this is about their week. They
 * are edited by different people at different times — access when somebody
 * joins, hours whenever they change — and a screen that does both makes the
 * rare change hard to find inside the common one.
 *
 * ARCH-7 puts it here rather than in the React application: this configures the
 * system rather than doing the work.
 */
final class AvailabilityScreen {

	/**
	 * The submenu page slug.
	 */
	public const SLUG = 'blueworx-forge-availability';

	/**
	 * Adds the menu entry, under the Forge menu.
	 */
	public static function register(): void {
		add_submenu_page(
			SitesScreen::SLUG,
			__( 'Availability', 'blueworx-forge' ),
			__( 'Availability', 'blueworx-forge' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * This screen's URL, for one person and optionally a result.
	 *
	 * @param string $person_id The person being looked at, or an empty string.
	 * @param string $result    A result code, or an empty string.
	 * @return string
	 */
	public static function url( string $person_id = '', string $result = '' ): string {
		$url = admin_url( 'admin.php?page=' . self::SLUG );

		if ( '' !== $person_id ) {
			$url = add_query_arg( 'person', $person_id, $url );
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

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Forge — availability', 'blueworx-forge' ) . '</h1>';
		echo '<p>' . esc_html__( 'Somebody\'s working week, and the time they are not available for. Everything that works out whether there is room to take work on reads this and nothing else.', 'blueworx-forge' ) . '</p>';

		self::result_notice();

		$people = Users::all( 'active' );

		if ( array() === $people ) {
			echo '<p data-bwx-no-people="1">' . esc_html__( 'Nobody to set hours for yet. Add people on the People screen first.', 'blueworx-forge' ) . '</p>';
			echo '</div>';

			return;
		}

		$person = self::chosen_person( $people );

		self::person_picker( $people, $person );
		self::this_week( $person );
		self::pattern_form( $person );
		self::pattern_history( $person );
		self::unavailability_section( $person );

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
			'hours-set'      => array( 'success', __( 'Hours recorded. Periods before the date you gave are unchanged.', 'blueworx-forge' ) ),
			'leave-added'    => array( 'success', __( 'Time off recorded.', 'blueworx-forge' ) ),
			'leave-removed'  => array( 'success', __( 'Time off removed.', 'blueworx-forge' ) ),
			'needs-date'     => array( 'error', __( 'A date the hours take effect from is needed.', 'blueworx-forge' ) ),
			'needs-dates'    => array( 'error', __( 'A start date and an end date are both needed.', 'blueworx-forge' ) ),
			'unknown-person' => array( 'error', __( 'That person could not be found.', 'blueworx-forge' ) ),
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
	 * The person being looked at — the one asked for, or the first.
	 *
	 * @param array<int, array<string, mixed>> $people Active people.
	 * @return array<string, mixed>
	 */
	private static function chosen_person( array $people ): array {
		$asked = isset( $_GET['person'] ) ? sanitize_text_field( wp_unslash( $_GET['person'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing whose hours to look at changes nothing.

		foreach ( $people as $person ) {
			if ( (string) $person['id'] === $asked ) {
				return $person;
			}
		}

		return $people[0];
	}

	/**
	 * Whose hours these are, and how to look at somebody else's.
	 *
	 * @param array<int, array<string, mixed>> $people Active people.
	 * @param array<string, mixed>             $person The one being shown.
	 */
	private static function person_picker( array $people, array $person ): void {
		echo '<form method="get" data-bwx-person-picker="1">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<label for="bwx-person">' . esc_html__( 'Person', 'blueworx-forge' ) . '</label> ';
		echo '<select id="bwx-person" name="person">';

		foreach ( $people as $option ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $option['id'] ),
				(string) $option['id'] === (string) $person['id'] ? ' selected' : '',
				esc_html( (string) $option['display_name'] )
			);
		}

		echo '</select> ';
		submit_button( __( 'Show', 'blueworx-forge' ), 'secondary', '', false );
		echo '</form>';

		echo '<h2 data-bwx-person-name="' . esc_attr( (string) $person['id'] ) . '">' . esc_html( (string) $person['display_name'] ) . '</h2>';
	}

	/**
	 * What this person's next seven days actually come to.
	 *
	 * The point of #136 made visible: one number, from the one place that works
	 * it out, with the days behind it shown rather than asserted.
	 *
	 * @param array<string, mixed> $person The person.
	 */
	private static function this_week( array $person ): void {
		$id    = (string) $person['id'];
		$from  = gmdate( 'Y-m-d' );
		$to    = gmdate( 'Y-m-d', (int) strtotime( $from . ' 00:00:00 UTC' ) + ( 6 * DAY_IN_SECONDS ) );
		$days  = Availability::by_day( $id, $from, $to );
		$total = Availability::hours( $id, $from, $to );

		echo '<h3>' . esc_html__( 'The next seven days', 'blueworx-forge' ) . '</h3>';

		if ( ! Availability::is_recorded( $id, $from ) ) {
			echo '<div class="notice notice-warning inline" data-bwx-availability="unrecorded"><p>';
			echo esc_html__( 'Nobody has said what this person\'s hours are, so nothing can be planned against them yet. That is different from having no time, and is why this says so rather than showing zero.', 'blueworx-forge' );
			echo '</p></div>';

			return;
		}

		printf(
			'<p data-bwx-availability="recorded">%1$s <strong data-bwx-available-hours="1">%2$s</strong></p>',
			esc_html__( 'Available hours:', 'blueworx-forge' ),
			esc_html( self::hours_label( $total ) )
		);

		echo '<table class="widefat striped" data-bwx-availability-days="1"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Day', 'blueworx-forge' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Hours', 'blueworx-forge' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Note', 'blueworx-forge' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $days as $day ) {
			echo '<tr data-bwx-day="' . esc_attr( $day['date'] ) . '" data-bwx-day-reason="' . esc_attr( $day['reason'] ) . '">';
			echo '<td>' . esc_html( gmdate( 'D j M', (int) strtotime( $day['date'] . ' 00:00:00 UTC' ) ) ) . '</td>';
			echo '<td data-bwx-day-hours="1">' . esc_html( self::hours_label( $day['hours'] ) ) . '</td>';
			echo '<td>' . esc_html( self::reason_label( $day ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The form that records a working week from a date.
	 *
	 * @param array<string, mixed> $person The person.
	 */
	private static function pattern_form( array $person ): void {
		$current = Patterns::in_force( (string) $person['id'], gmdate( 'Y-m-d' ) );

		echo '<h3>' . esc_html__( 'Set working hours', 'blueworx-forge' ) . '</h3>';
		echo '<p>' . esc_html__( 'Hours take effect from the date you give and leave everything before it alone, so a change now does not rewrite what last month was.', 'blueworx-forge' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-set-hours="1">';
		wp_nonce_field( 'bwx_forge_set_hours' );
		echo '<input type="hidden" name="action" value="bwx_forge_set_hours">';
		echo '<input type="hidden" name="person" value="' . esc_attr( (string) $person['id'] ) . '">';

		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row"><label for="bwx-effective-from">' . esc_html__( 'From', 'blueworx-forge' ) . '</label></th><td>';
		echo '<input type="date" id="bwx-effective-from" name="effective_from" value="' . esc_attr( gmdate( 'Y-m-d' ) ) . '" required>';
		echo '</td></tr>';

		foreach ( self::weekdays() as $column => $label ) {
			$value = null === $current ? 0 : (float) $current[ $column ];

			echo '<tr><th scope="row"><label for="bwx-' . esc_attr( $column ) . '">' . esc_html( $label ) . '</label></th><td>';
			printf(
				'<input type="number" id="bwx-%1$s" name="%1$s" value="%2$s" min="0" max="24" step="0.25" class="small-text">',
				esc_attr( $column ),
				esc_attr( (string) $value )
			);
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		submit_button( __( 'Record these hours', 'blueworx-forge' ) );
		echo '</form>';
	}

	/**
	 * Every pattern recorded for this person.
	 *
	 * Shown rather than hidden, because effective dating is only trustworthy if
	 * what it is holding can be seen.
	 *
	 * @param array<string, mixed> $person The person.
	 */
	private static function pattern_history( array $person ): void {
		$history = Patterns::history( (string) $person['id'] );

		if ( array() === $history ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Hours over time', 'blueworx-forge' ) . '</h3>';
		echo '<ul data-bwx-pattern-history="1">';

		foreach ( $history as $pattern ) {
			echo '<li data-bwx-pattern="' . esc_attr( (string) $pattern['effective_from'] ) . '">';
			printf(
				/* translators: 1: date the hours take effect, 2: hours per week. */
				esc_html__( 'From %1$s — %2$s a week', 'blueworx-forge' ),
				'<strong>' . esc_html( (string) $pattern['effective_from'] ) . '</strong>',
				'<span data-bwx-pattern-week="1">' . esc_html( self::hours_label( (float) $pattern['hours_week'] ) ) . '</span>'
			);
			echo '</li>';
		}

		echo '</ul>';
	}

	/**
	 * Time off: what is recorded, and the form that records more.
	 *
	 * @param array<string, mixed> $person The person.
	 */
	private static function unavailability_section( array $person ): void {
		$id = (string) $person['id'];

		echo '<h3>' . esc_html__( 'Time off', 'blueworx-forge' ) . '</h3>';

		// A year either side: far enough back to explain a figure somebody is
		// questioning, and far enough forward to cover anything booked.
		$today  = gmdate( 'Y-m-d' );
		$from   = gmdate( 'Y-m-d', (int) strtotime( $today . ' 00:00:00 UTC' ) - ( 365 * DAY_IN_SECONDS ) );
		$to     = gmdate( 'Y-m-d', (int) strtotime( $today . ' 00:00:00 UTC' ) + ( 365 * DAY_IN_SECONDS ) );
		$booked = Unavailability::overlapping( $id, $from, $to );

		if ( array() === $booked ) {
			echo '<p data-bwx-no-leave="1">' . esc_html__( 'Nothing recorded in the year either side of today.', 'blueworx-forge' ) . '</p>';
		} else {
			echo '<ul data-bwx-leave="1">';

			foreach ( $booked as $record ) {
				echo '<li data-bwx-leave-record="' . esc_attr( (string) $record['id'] ) . '">';
				printf(
					/* translators: 1: start date, 2: end date, 3: what kind of time off. */
					esc_html__( '%1$s to %2$s — %3$s', 'blueworx-forge' ),
					'<strong>' . esc_html( (string) $record['starts_on'] ) . '</strong>',
					'<strong>' . esc_html( (string) $record['ends_on'] ) . '</strong>',
					'<span data-bwx-leave-kind="' . esc_attr( (string) $record['kind'] ) . '">' . esc_html( self::kind_label( (string) $record['kind'] ) ) . '</span>'
				);

				if ( '' !== (string) $record['note'] ) {
					echo ' <em>' . esc_html( (string) $record['note'] ) . '</em>';
				}

				echo ' ';
				self::remove_leave_button( $person, (string) $record['id'] );
				echo '</li>';
			}

			echo '</ul>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-add-leave="1">';
		wp_nonce_field( 'bwx_forge_add_leave' );
		echo '<input type="hidden" name="action" value="bwx_forge_add_leave">';
		echo '<input type="hidden" name="person" value="' . esc_attr( $id ) . '">';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row"><label for="bwx-leave-from">' . esc_html__( 'From', 'blueworx-forge' ) . '</label></th><td>';
		echo '<input type="date" id="bwx-leave-from" name="starts_on" required>';
		echo '<p class="description">' . esc_html__( 'The first day away.', 'blueworx-forge' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="bwx-leave-to">' . esc_html__( 'To', 'blueworx-forge' ) . '</label></th><td>';
		echo '<input type="date" id="bwx-leave-to" name="ends_on" required>';
		echo '<p class="description">' . esc_html__( 'The last day away. This day counts as time off.', 'blueworx-forge' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="bwx-leave-kind">' . esc_html__( 'Kind', 'blueworx-forge' ) . '</label></th><td>';
		echo '<select id="bwx-leave-kind" name="kind">';

		foreach ( Unavailability::KINDS as $kind ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $kind ), esc_html( self::kind_label( $kind ) ) );
		}

		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="bwx-leave-note">' . esc_html__( 'Note', 'blueworx-forge' ) . '</label></th><td>';
		echo '<input type="text" id="bwx-leave-note" name="note" class="regular-text" maxlength="191">';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Record time off', 'blueworx-forge' ) );
		echo '</form>';
	}

	/**
	 * The button that removes one record.
	 *
	 * @param array<string, mixed> $person The person.
	 * @param string               $id     Record id.
	 */
	private static function remove_leave_button( array $person, string $id ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		wp_nonce_field( 'bwx_forge_remove_leave' );
		echo '<input type="hidden" name="action" value="bwx_forge_remove_leave">';
		echo '<input type="hidden" name="person" value="' . esc_attr( (string) $person['id'] ) . '">';
		echo '<input type="hidden" name="record" value="' . esc_attr( $id ) . '">';
		echo '<button type="submit" class="button-link" data-bwx-remove-leave="' . esc_attr( $id ) . '">';
		echo esc_html__( 'Remove', 'blueworx-forge' );
		echo '</button>';
		echo '</form>';
	}

	/**
	 * The seven columns and what to call them.
	 *
	 * Monday first here, whatever order they are stored in: it is the order
	 * people read a working week in.
	 *
	 * @return array<string, string>
	 */
	private static function weekdays(): array {
		return array(
			'hours_mon' => __( 'Monday', 'blueworx-forge' ),
			'hours_tue' => __( 'Tuesday', 'blueworx-forge' ),
			'hours_wed' => __( 'Wednesday', 'blueworx-forge' ),
			'hours_thu' => __( 'Thursday', 'blueworx-forge' ),
			'hours_fri' => __( 'Friday', 'blueworx-forge' ),
			'hours_sat' => __( 'Saturday', 'blueworx-forge' ),
			'hours_sun' => __( 'Sunday', 'blueworx-forge' ),
		);
	}

	/**
	 * Hours, without a trailing .00 on a whole number.
	 *
	 * @param float $hours Hours.
	 * @return string
	 */
	private static function hours_label( float $hours ): string {
		$rounded = round( $hours, 2 );

		return ( (float) (int) $rounded === $rounded ? (string) (int) $rounded : rtrim( number_format( $rounded, 2, '.', '' ), '0' ) ) . 'h';
	}

	/**
	 * Why a day is what it is, in words.
	 *
	 * @param array{date: string, hours: float, base_hours: float, reason: string} $day One day.
	 * @return string
	 */
	private static function reason_label( array $day ): string {
		if ( 'non-working-day' === $day['reason'] ) {
			return __( 'Not a working day', 'blueworx-forge' );
		}

		if ( 'no-pattern' === $day['reason'] ) {
			return __( 'No hours recorded', 'blueworx-forge' );
		}

		if ( '' === $day['reason'] ) {
			return '';
		}

		return sprintf(
			/* translators: 1: kind of time off, 2: the hours it cost. */
			__( '%1$s — %2$s not available', 'blueworx-forge' ),
			self::kind_label( $day['reason'] ),
			self::hours_label( $day['base_hours'] )
		);
	}

	/**
	 * A kind, in words.
	 *
	 * @param string $kind One of Unavailability::KINDS.
	 * @return string
	 */
	private static function kind_label( string $kind ): string {
		$labels = array(
			'leave'          => __( 'Leave', 'blueworx-forge' ),
			'public-holiday' => __( 'Public holiday', 'blueworx-forge' ),
			'training'       => __( 'Training', 'blueworx-forge' ),
			'other'          => __( 'Other', 'blueworx-forge' ),
		);

		return $labels[ $kind ] ?? $labels['other'];
	}
}
