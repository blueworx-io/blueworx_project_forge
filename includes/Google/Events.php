<?php
/**
 * What a Google Calendar event becomes in Forge.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Google;

use Blueworx\Forge\Meetings\Recurrence;

/**
 * #606. Pure: one event from one person's calendar, in Forge's words.
 *
 * Everything that comes across is kept, declined and all-day events included
 * (Luke, 2026-10-23), so this never refuses an event for what it is — it says
 * whether it **counts** against that person's time. An all-day event, one
 * they declined, one marked "free", and out-of-office, working-location and
 * focus-time events show and count nothing: a holiday must not fill
 * somebody's week, and focus time is work, not a meeting.
 *
 * The share key is what makes one invitation on two calendars one meeting:
 * the iCal UID is the same on every attendee's copy, and a recurring event's
 * instances share it too, so the instance's original start goes in with it.
 */
final class Events {

	/**
	 * Longest description kept.
	 */
	public const MAX_DESCRIPTION = 2000;

	/**
	 * Google event types that are not meetings.
	 *
	 * @var array<int, string>
	 */
	private const NOT_MEETINGS = array( 'outOfOffice', 'workingLocation', 'focusTime' );

	/**
	 * One event, normalised.
	 *
	 * @param array<string, mixed> $item An event resource from the Calendar API.
	 * @param \DateTimeZone        $zone The studio's timezone, for its day and time.
	 * @return array<string, mixed>|null Null when it has no id.
	 */
	public static function normalise( array $item, \DateTimeZone $zone ): ?array {
		$id = (string) ( $item['id'] ?? '' );

		if ( '' === $id ) {
			return null;
		}

		$cancelled = 'cancelled' === (string) ( $item['status'] ?? '' );
		$start     = is_array( $item['start'] ?? null ) ? $item['start'] : array();
		$end       = is_array( $item['end'] ?? null ) ? $item['end'] : array();
		$all_day   = isset( $start['date'] ) && ! isset( $start['dateTime'] );
		$response  = self::response( $item );

		$out = array(
			'event_id'      => $id,
			'share_key'     => self::share_key( $item ),
			'cancelled'     => $cancelled,
			'title'         => '' === trim( (string) ( $item['summary'] ?? '' ) ) ? __( '(No title)', 'blueworx-forge' ) : mb_substr( trim( (string) $item['summary'] ), 0, 191 ),
			'description'   => mb_substr( trim( wp_strip_all_tags( (string) ( $item['description'] ?? '' ) ) ), 0, self::MAX_DESCRIPTION ),
			'on'            => '',
			'ends_on'       => '',
			'at'            => '',
			'starts_at'     => 0,
			'ends_at'       => 0,
			'all_day'       => $all_day,
			'private'       => in_array( (string) ( $item['visibility'] ?? '' ), array( 'private', 'confidential' ), true ),
			'response'      => $response,
			'counts'        => false,
			'planned_hours' => 0.0,
			'attendees'     => self::attendees( $item ),
		);

		if ( $all_day ) {
			$first = self::date( (string) $start['date'] );
			// Google's end date is the day after the last one.
			$after = self::date( (string) ( $end['date'] ?? '' ) );
			$last  = null === $after ? null : $after->modify( '-1 day' );

			if ( null !== $first ) {
				$out['on']        = $first->format( 'Y-m-d' );
				$out['ends_on']   = null !== $last && $last > $first ? $last->format( 'Y-m-d' ) : '';
				$out['starts_at'] = ( new \DateTimeImmutable( $out['on'] . ' 00:00:00', $zone ) )->getTimestamp();
				$out['ends_at']   = ( new \DateTimeImmutable( ( '' === $out['ends_on'] ? $out['on'] : $out['ends_on'] ) . ' 23:59:59', $zone ) )->getTimestamp();
			}

			return $out;
		}

		$from = self::moment( (string) ( $start['dateTime'] ?? '' ) );
		$to   = self::moment( (string) ( $end['dateTime'] ?? '' ) );

		if ( null === $from ) {
			return $out;
		}

		$local            = $from->setTimezone( $zone );
		$out['on']        = $local->format( 'Y-m-d' );
		$out['at']        = $local->format( 'H:i' );
		$out['starts_at'] = $from->getTimestamp();
		$out['ends_at']   = null === $to ? $from->getTimestamp() : max( $from->getTimestamp(), $to->getTimestamp() );

		$minutes              = (int) floor( ( $out['ends_at'] - $out['starts_at'] ) / 60 );
		$out['planned_hours'] = Recurrence::planned_hours( $minutes );
		$out['counts']        = ! $cancelled
			&& 'declined' !== $response
			&& 'transparent' !== (string) ( $item['transparency'] ?? '' )
			&& ! in_array( (string) ( $item['eventType'] ?? '' ), self::NOT_MEETINGS, true )
			&& $out['planned_hours'] > 0;

		return $out;
	}

	/**
	 * The key every copy of one meeting shares.
	 *
	 * @param array<string, mixed> $item The event.
	 * @return string 32 hex characters.
	 */
	private static function share_key( array $item ): string {
		$uid = (string) ( $item['iCalUID'] ?? '' );
		$key = '' === $uid ? (string) ( $item['id'] ?? '' ) : $uid;

		if ( '' !== (string) ( $item['recurringEventId'] ?? '' ) ) {
			$original = is_array( $item['originalStartTime'] ?? null ) ? $item['originalStartTime'] : array();
			$when     = (string) ( $original['dateTime'] ?? $original['date'] ?? '' );
			$moment   = self::moment( $when );
			$key     .= '@' . ( null === $moment ? $when : (string) $moment->getTimestamp() );
		}

		return md5( $key );
	}

	/**
	 * How the calendar's owner answered: their own attendee entry, or
	 * accepted when there is none (their own event).
	 *
	 * @param array<string, mixed> $item The event.
	 * @return string
	 */
	private static function response( array $item ): string {
		foreach ( is_array( $item['attendees'] ?? null ) ? $item['attendees'] : array() as $attendee ) {
			if ( is_array( $attendee ) && ! empty( $attendee['self'] ) ) {
				return sanitize_key( (string) ( $attendee['responseStatus'] ?? 'needsAction' ) );
			}
		}

		return 'accepted';
	}

	/**
	 * Everybody else on it.
	 *
	 * @param array<string, mixed> $item The event.
	 * @return array<int, array{email: string, name: string}>
	 */
	private static function attendees( array $item ): array {
		$out = array();

		foreach ( is_array( $item['attendees'] ?? null ) ? $item['attendees'] : array() as $attendee ) {
			if ( ! is_array( $attendee ) || ! empty( $attendee['self'] ) || ! empty( $attendee['resource'] ) ) {
				continue;
			}

			$out[] = array(
				'email' => mb_substr( (string) ( $attendee['email'] ?? '' ), 0, 191 ),
				'name'  => mb_substr( (string) ( $attendee['displayName'] ?? '' ), 0, 191 ),
			);

			if ( count( $out ) >= 50 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * A date-time with an offset, as Google writes them.
	 *
	 * @param string $value RFC 3339.
	 * @return \DateTimeImmutable|null
	 */
	private static function moment( string $value ): ?\DateTimeImmutable {
		if ( '' === $value ) {
			return null;
		}

		try {
			return new \DateTimeImmutable( $value );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * A plain date.
	 *
	 * @param string $value YYYY-MM-DD.
	 * @return \DateTimeImmutable|null
	 */
	private static function date( string $value ): ?\DateTimeImmutable {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );

		return false === $date ? null : $date;
	}
}
