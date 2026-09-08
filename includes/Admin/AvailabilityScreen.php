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
 * system rather than doing the work. It is built from the shared admin design
 * system, like every other studio screen.
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

		Page::open(
			__( 'Availability', 'blueworx-forge' ),
			__( 'Forge', 'blueworx-forge' ),
			__( 'Somebody\'s working week, and the time they are not available for. Everything that works out whether there is room to take work on reads this and nothing else.', 'blueworx-forge' )
		);

		self::result_notice();

		$people = Users::all( 'active' );

		if ( array() === $people ) {
			echo '<div class="bw-empty" data-bwx-no-people="1">';
			echo '<i class="bw-icon bw-empty__icon" data-lucide="users"></i>';
			echo '<p class="bw-empty__title">' . esc_html__( 'Nobody to set hours for yet', 'blueworx-forge' ) . '</p>';
			echo '<p class="bw-empty__text">' . esc_html__( 'Add people on the People screen first.', 'blueworx-forge' ) . '</p>';
			echo '</div>';

			Page::close();

			return;
		}

		$person = self::chosen_person( $people );

		self::person_picker( $people, $person );

		Page::panel_open( __( 'The working week', 'blueworx-forge' ), 'week' );
		self::this_week( $person );
		self::pattern_form( $person );
		self::pattern_history( $person );
		Page::panel_close();

		Page::panel_open( __( 'Time off', 'blueworx-forge' ), 'leave' );
		self::unavailability_section( $person );
		Page::panel_close();

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

		// Toned danger rather than error, spelled that way once here: the design
		// system has no error tone, and Page::notice shows one it does not
		// recognise as information rather than as a failure.
		$messages = array(
			'hours-set'      => array( 'success', __( 'Hours recorded. Periods before the date you gave are unchanged.', 'blueworx-forge' ) ),
			'leave-added'    => array( 'success', __( 'Time off recorded.', 'blueworx-forge' ) ),
			'leave-removed'  => array( 'success', __( 'Time off removed.', 'blueworx-forge' ) ),
			'needs-date'     => array( 'danger', __( 'A date the hours take effect from is needed.', 'blueworx-forge' ) ),
			'needs-dates'    => array( 'danger', __( 'A start date and an end date are both needed.', 'blueworx-forge' ) ),
			'unknown-person' => array( 'danger', __( 'That person could not be found.', 'blueworx-forge' ) ),
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
		echo '<div class="bw-toolbar bw-toolbar--card">';
		echo '<form method="get" class="bw-toolbar__group" data-bwx-person-picker="1">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<label class="bw-formrow__label" for="bwx-person">' . esc_html__( 'Person', 'blueworx-forge' ) . '</label>';
		echo '<span class="bw-select">';
		echo '<select class="bw-select__el" id="bwx-person" name="person">';

		foreach ( $people as $option ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $option['id'] ),
				(string) $option['id'] === (string) $person['id'] ? ' selected' : '',
				esc_html( (string) $option['display_name'] )
			);
		}

		echo '</select>';
		echo '<i class="bw-icon bw-select__arrow" data-lucide="chevron-down"></i>';
		echo '</span>';

		// submit_button() rather than a <button>, and this is not cosmetic: the
		// specs click `input[type="submit"]`, so the element is as much part of
		// the contract as a data-bwx hook is. What changes is the class it
		// carries.
		submit_button( __( 'Show', 'blueworx-forge' ), 'bw-btn bw-btn--secondary', '', false );

		echo '</form>';
		echo '</div>';

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

		echo '<h3 class="bw-card__eyebrow">' . esc_html__( 'The next seven days', 'blueworx-forge' ) . '</h3>';

		if ( ! Availability::is_recorded( $id, $from ) ) {
			Page::notice(
				'warning',
				__( 'Nobody has said what this person\'s hours are, so nothing can be planned against them yet. That is different from having no time, and is why this says so rather than showing zero.', 'blueworx-forge' ),
				array( 'data-bwx-availability' => 'unrecorded' )
			);

			return;
		}

		echo '<div class="bw-stats">';
		echo '<div class="bw-stat" data-bwx-availability="recorded">';
		echo '<span class="bw-stat__label">' . esc_html__( 'Available hours', 'blueworx-forge' ) . '</span>';
		echo '<span class="bw-stat__value" data-bwx-available-hours="1">' . esc_html( self::hours_label( $total ) ) . '</span>';
		echo '<p class="bw-stat__foot">' . esc_html__( 'Across the next seven days.', 'blueworx-forge' ) . '</p>';
		echo '</div>';
		echo '</div>';

		// A row per day, and the day on the row rather than in a cell of its
		// own: availability-screen.spec.js reads tr[data-bwx-day] and its
		// reason attribute, so only the styling of this table moved.
		echo '<div class="bw-tablescroll">';
		echo '<table class="bw-table" data-bwx-availability-days="1"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Day', 'blueworx-forge' ) . '</th>';
		echo '<th scope="col" class="bw-table__num">' . esc_html__( 'Hours', 'blueworx-forge' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Note', 'blueworx-forge' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $days as $day ) {
			echo '<tr data-bwx-day="' . esc_attr( $day['date'] ) . '" data-bwx-day-reason="' . esc_attr( $day['reason'] ) . '">';
			echo '<td class="bw-table__primary">' . esc_html( gmdate( 'D j M', (int) strtotime( $day['date'] . ' 00:00:00 UTC' ) ) ) . '</td>';
			echo '<td class="bw-table__num" data-bwx-day-hours="1">' . esc_html( self::hours_label( $day['hours'] ) ) . '</td>';
			echo '<td class="bw-table__note">' . esc_html( self::reason_label( $day ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * The form that records a working week from a date.
	 *
	 * @param array<string, mixed> $person The person.
	 */
	private static function pattern_form( array $person ): void {
		$current = Patterns::in_force( (string) $person['id'], gmdate( 'Y-m-d' ) );

		echo '<h3 class="bw-card__eyebrow">' . esc_html__( 'Set working hours', 'blueworx-forge' ) . '</h3>';
		echo '<p class="bw-card__note">' . esc_html__( 'Hours take effect from the date you give and leave everything before it alone, so a change now does not rewrite what last month was.', 'blueworx-forge' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-set-hours="1">';
		wp_nonce_field( 'bwx_forge_set_hours' );
		echo '<input type="hidden" name="action" value="bwx_forge_set_hours">';
		echo '<input type="hidden" name="person" value="' . esc_attr( (string) $person['id'] ) . '">';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-effective-from">' . esc_html__( 'From', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control">';
		echo '<input type="date" class="bw-input" id="bwx-effective-from" name="effective_from" value="' . esc_attr( gmdate( 'Y-m-d' ) ) . '" required>';
		echo '</div></div>';

		foreach ( self::weekdays() as $column => $label ) {
			$value = null === $current ? 0 : (float) $current[ $column ];

			echo '<div class="bw-formrow">';
			echo '<label class="bw-formrow__label" for="bwx-' . esc_attr( $column ) . '">' . esc_html( $label ) . '</label>';
			echo '<div class="bw-formrow__control">';
			printf(
				'<input type="number" class="bw-input" id="bwx-%1$s" name="%1$s" value="%2$s" min="0" max="24" step="0.25">',
				esc_attr( $column ),
				esc_attr( (string) $value )
			);
			echo '</div></div>';
		}

		echo '<div class="bw-card__actions">';
		submit_button( __( 'Record these hours', 'blueworx-forge' ), 'bw-btn bw-btn--primary', 'submit', false );
		echo '</div>';

		echo '</form>';
	}

	/**
	 * Every pattern recorded for this person.
	 *
	 * Shown rather than hidden, because effective dating is only trustworthy if
	 * what it is holding can be seen. A list that reads as history, which is
	 * what it is — and still a <ul> of <li> items, because that is what
	 * availability-screen.spec.js counts to prove both statements were kept.
	 *
	 * @param array<string, mixed> $person The person.
	 */
	private static function pattern_history( array $person ): void {
		$history = Patterns::history( (string) $person['id'] );

		if ( array() === $history ) {
			return;
		}

		echo '<h3 class="bw-card__eyebrow">' . esc_html__( 'Hours over time', 'blueworx-forge' ) . '</h3>';
		echo '<ul class="bw-activity" data-bwx-pattern-history="1">';

		foreach ( $history as $pattern ) {
			echo '<li class="bw-activity__item" data-bwx-pattern="' . esc_attr( (string) $pattern['effective_from'] ) . '">';
			echo '<span class="bw-activity__dot"><i class="bw-icon" data-lucide="clock"></i></span>';
			echo '<div class="bw-activity__body"><p class="bw-activity__text">';
			printf(
				/* translators: 1: date the hours take effect, 2: hours per week. */
				esc_html__( 'From %1$s — %2$s a week', 'blueworx-forge' ),
				'<strong>' . esc_html( (string) $pattern['effective_from'] ) . '</strong>',
				'<span data-bwx-pattern-week="1">' . esc_html( self::hours_label( (float) $pattern['hours_week'] ) ) . '</span>'
			);
			echo '</p></div>';
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

		// A year either side: far enough back to explain a figure somebody is
		// questioning, and far enough forward to cover anything booked.
		$today  = gmdate( 'Y-m-d' );
		$from   = gmdate( 'Y-m-d', (int) strtotime( $today . ' 00:00:00 UTC' ) - ( 365 * DAY_IN_SECONDS ) );
		$to     = gmdate( 'Y-m-d', (int) strtotime( $today . ' 00:00:00 UTC' ) + ( 365 * DAY_IN_SECONDS ) );
		$booked = Unavailability::overlapping( $id, $from, $to );

		echo '<h3 class="bw-card__eyebrow">' . esc_html__( 'Recorded time off', 'blueworx-forge' ) . '</h3>';

		if ( array() === $booked ) {
			echo '<div class="bw-empty" data-bwx-no-leave="1">';
			echo '<i class="bw-icon bw-empty__icon" data-lucide="calendar-check"></i>';
			echo '<p class="bw-empty__title">' . esc_html__( 'No time off recorded', 'blueworx-forge' ) . '</p>';
			echo '<p class="bw-empty__text">' . esc_html__( 'Nothing recorded in the year either side of today.', 'blueworx-forge' ) . '</p>';
			echo '</div>';
		} else {
			self::leave_table( $person, $booked );
		}

		self::add_leave_form( $id );
	}

	/**
	 * What is already booked, and how to take one back out.
	 *
	 * @param array<string, mixed>             $person The person.
	 * @param array<int, array<string, mixed>> $booked The records.
	 */
	private static function leave_table( array $person, array $booked ): void {
		echo '<div class="bw-tablescroll">';
		echo '<table class="bw-table" data-bwx-leave="1"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'From', 'blueworx-forge' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'To', 'blueworx-forge' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Kind', 'blueworx-forge' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Note', 'blueworx-forge' ) . '</th>';
		echo '<th scope="col" class="bw-table__actions">' . esc_html__( 'Actions', 'blueworx-forge' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $booked as $record ) {
			$kind = (string) $record['kind'];

			echo '<tr data-bwx-leave-record="' . esc_attr( (string) $record['id'] ) . '">';
			echo '<td class="bw-table__primary">' . esc_html( (string) $record['starts_on'] ) . '</td>';
			echo '<td>' . esc_html( (string) $record['ends_on'] ) . '</td>';
			echo '<td><span class="bw-chip bw-chip--plain" data-bwx-leave-kind="' . esc_attr( $kind ) . '">' . esc_html( self::kind_label( $kind ) ) . '</span></td>';
			echo '<td class="bw-table__note">' . esc_html( (string) $record['note'] ) . '</td>';
			echo '<td class="bw-table__actions"><div class="bw-rowactions">';
			self::remove_leave_button( $person, (string) $record['id'] );
			echo '</div></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * The form that records time off.
	 *
	 * @param string $id The person.
	 */
	private static function add_leave_form( string $id ): void {
		echo '<h3 class="bw-card__eyebrow">' . esc_html__( 'Record time off', 'blueworx-forge' ) . '</h3>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bwx-add-leave="1">';
		wp_nonce_field( 'bwx_forge_add_leave' );
		echo '<input type="hidden" name="action" value="bwx_forge_add_leave">';
		echo '<input type="hidden" name="person" value="' . esc_attr( $id ) . '">';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-leave-from">' . esc_html__( 'From', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control">';
		echo '<input type="date" class="bw-input" id="bwx-leave-from" name="starts_on" required>';
		echo '<p class="bw-formrow__help">' . esc_html__( 'The first day away.', 'blueworx-forge' ) . '</p>';
		echo '</div></div>';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-leave-to">' . esc_html__( 'To', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control">';
		echo '<input type="date" class="bw-input" id="bwx-leave-to" name="ends_on" required>';
		echo '<p class="bw-formrow__help">' . esc_html__( 'The last day away. This day counts as time off.', 'blueworx-forge' ) . '</p>';
		echo '</div></div>';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-leave-kind">' . esc_html__( 'Kind', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control"><span class="bw-select">';
		echo '<select class="bw-select__el" id="bwx-leave-kind" name="kind">';

		foreach ( Unavailability::KINDS as $kind ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $kind ), esc_html( self::kind_label( $kind ) ) );
		}

		echo '</select>';
		echo '<i class="bw-icon bw-select__arrow" data-lucide="chevron-down"></i>';
		echo '</span></div></div>';

		echo '<div class="bw-formrow">';
		echo '<label class="bw-formrow__label" for="bwx-leave-note">' . esc_html__( 'Note', 'blueworx-forge' ) . '</label>';
		echo '<div class="bw-formrow__control">';
		echo '<input type="text" class="bw-input" id="bwx-leave-note" name="note" maxlength="191">';
		echo '</div></div>';

		echo '<div class="bw-card__actions">';
		submit_button( __( 'Record time off', 'blueworx-forge' ), 'bw-btn bw-btn--primary', 'submit', false );
		echo '</div>';

		echo '</form>';
	}

	/**
	 * The button that removes one record.
	 *
	 * A posted form dressed as a row action rather than a link: taking time
	 * back off somebody's record is not something a URL put in front of an
	 * administrator should be able to do.
	 *
	 * @param array<string, mixed> $person The person.
	 * @param string               $id     Record id.
	 */
	private static function remove_leave_button( array $person, string $id ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bwx_forge_remove_leave' );
		echo '<input type="hidden" name="action" value="bwx_forge_remove_leave">';
		echo '<input type="hidden" name="person" value="' . esc_attr( (string) $person['id'] ) . '">';
		echo '<input type="hidden" name="record" value="' . esc_attr( $id ) . '">';
		echo '<button type="submit" class="bw-rowactions__link bw-rowactions__link--danger" data-bwx-remove-leave="' . esc_attr( $id ) . '">';
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
