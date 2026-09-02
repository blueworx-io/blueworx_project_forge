<?php
/**
 * What a support meeting series has to say before it can exist.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Meetings;

use DateTimeZone;
use Exception;

/**
 * #152, MEET-1 to MEET-3. Pure, so every rule here can be argued with in a test.
 *
 * A series is a standing commitment against a client's hours, and the fields it
 * must carry are the ones somebody would otherwise have to guess at later. Each
 * of them has a wrong answer that looks exactly like an empty box.
 *
 * **The host is the one that looks optional and is not.** MEET-5 makes marking
 * a meeting held the only thing that draws a client's hours, and MEET-1 gives
 * that to the site's Point of Contact. A series with nobody named is a series
 * whose hours nobody can ever draw: it sits on the board looking scheduled, it
 * reserves against a balance, and it never resolves.
 *
 * Every problem is reported at once, for the reason the workflow gates report
 * every unmet requirement (#107): told one thing at a time, somebody fixes it,
 * resubmits, and is refused again for the next.
 */
final class Validate {

	/**
	 * Longest a title may be, matching the column.
	 */
	public const MAX_TITLE = 191;

	/**
	 * Longest the attendee list may be.
	 */
	public const MAX_ATTENDEES = 2000;

	/**
	 * The longest meeting anybody means.
	 *
	 * Eight hours is already a long day. A four-figure duration is somebody
	 * entering the wrong unit, and it would reserve a week of a client's
	 * package against one call.
	 */
	public const MAX_MINUTES = 480;

	/**
	 * Checks a submitted series.
	 *
	 * @param array<string, mixed> $input What was submitted.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function series( array $input ): array {
		$errors = array();
		$values = array();

		$site = trim( (string) ( $input['client_site_id'] ?? '' ) );

		if ( '' === $site ) {
			$errors['client_site_id'] = 'A meeting series belongs to a site.';
		} else {
			$values['client_site_id'] = $site;
		}

		$title = trim( (string) ( $input['title'] ?? '' ) );

		if ( '' === $title ) {
			$errors['title'] = 'Give the series a name.';
		} else {
			// Cut rather than refused: the field is a label, and refusing a long
			// one makes somebody count characters to find out how long is too long.
			$values['title'] = mb_substr( $title, 0, self::MAX_TITLE );
		}

		$frequency = (string) ( $input['frequency'] ?? '' );

		if ( ! Recurrence::exists( $frequency ) ) {
			$errors['frequency'] = 'Choose weekly, fortnightly, four-weekly or monthly.';
		} else {
			$values['frequency'] = $frequency;
		}

		$starts_on = (string) ( $input['starts_on'] ?? '' );

		if ( ! self::is_date( $starts_on ) ) {
			$errors['starts_on'] = 'Give the date the series starts, as YYYY-MM-DD.';
		} else {
			$values['starts_on'] = $starts_on;
		}

		$ends_on = trim( (string) ( $input['ends_on'] ?? '' ) );

		if ( '' === $ends_on ) {
			// Most standing meetings have no agreed last one, so an open series
			// is the ordinary case rather than a field left blank by mistake.
			$values['ends_on'] = '';
		} elseif ( ! self::is_date( $ends_on ) ) {
			$errors['ends_on'] = 'Give the last date as YYYY-MM-DD, or leave it empty.';
		} elseif ( self::is_date( $starts_on ) && $ends_on < $starts_on ) {
			$errors['ends_on'] = 'A series cannot end before it starts.';
		} else {
			$values['ends_on'] = $ends_on;
		}

		$at = (string) ( $input['time_of_day'] ?? '' );

		if ( 1 !== preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $at ) ) {
			$errors['time_of_day'] = 'Give the time as HH:MM.';
		} else {
			$values['time_of_day'] = $at;
		}

		$duration = (int) ( $input['duration_mins'] ?? 0 );

		if ( $duration <= 0 || $duration > self::MAX_MINUTES ) {
			$errors['duration_mins'] = 'Give the length in minutes, up to ' . self::MAX_MINUTES . '.';
		} else {
			$values['duration_mins'] = $duration;
		}

		$timezone = (string) ( $input['timezone'] ?? '' );

		if ( ! self::is_timezone( $timezone ) ) {
			$errors['timezone'] = 'Choose the timezone the meeting is held in.';
		} else {
			$values['timezone'] = $timezone;
		}

		$host = trim( (string) ( $input['host_user_id'] ?? '' ) );

		if ( '' === $host ) {
			$errors['host_user_id'] = 'Name the host: only they can mark a meeting held.';
		} else {
			$values['host_user_id'] = $host;
		}

		$hours = round( (float) ( $input['planned_hours'] ?? 0 ), 2 );

		if ( $hours < 0 ) {
			$errors['planned_hours'] = 'Planned hours cannot be negative.';
		} else {
			// Nought means "work it out from the length" (MEET-3), and is stored
			// as nought rather than as a copy of the derived figure — so a
			// series whose meetings get longer costs more without anybody
			// having to remember a second field.
			$values['planned_hours'] = $hours;
		}

		$values['attendees'] = mb_substr( trim( (string) ( $input['attendees'] ?? '' ) ), 0, self::MAX_ATTENDEES );

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * What one occurrence of a series costs.
	 *
	 * The override where somebody set one, and the length rounded up otherwise
	 * (MEET-3). Answered here rather than at each call site, because "is this
	 * series' figure a real answer or the absence of one" is exactly the
	 * question every caller would get wrong once.
	 *
	 * @param array<string, mixed> $series The series, as stored.
	 * @return float
	 */
	public static function hours_for( array $series ): float {
		$set = round( (float) ( $series['planned_hours'] ?? 0 ), 2 );

		if ( $set > 0 ) {
			return $set;
		}

		return Recurrence::planned_hours( (int) ( $series['duration_mins'] ?? 0 ) );
	}

	/**
	 * Whether a string is a real calendar date.
	 *
	 * Checked by rebuilding it, so the thirty-first of February is refused
	 * rather than quietly rolling into March.
	 *
	 * @param string $date Candidate, YYYY-MM-DD.
	 * @return bool
	 */
	private static function is_date( string $date ): bool {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}

		[ $year, $month, $day ] = array_map( 'intval', explode( '-', $date ) );

		return checkdate( $month, $day, $year );
	}

	/**
	 * Whether a string names a timezone.
	 *
	 * @param string $timezone Candidate.
	 * @return bool
	 */
	private static function is_timezone( string $timezone ): bool {
		if ( '' === $timezone ) {
			return false;
		}

		try {
			new DateTimeZone( $timezone );
		} catch ( Exception $error ) {
			return false;
		}

		return true;
	}
}
