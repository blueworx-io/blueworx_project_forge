<?php
/**
 * Bringing one person's Google Calendar into step.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Google;

use Blueworx\Forge\Meetings\MeetingHours;

/**
 * #606. One way, Google into Forge, and only when somebody opens Forge —
 * there is no cron in this plugin (see `Recurring\Materialise`).
 *
 * The first read is a full one over the window (four weeks back, twelve
 * ahead, matching how far client hours are held), and Google hands back a
 * sync token. After that each read asks only for what changed since. A full
 * read happens again when Google says the token has expired, and once a day,
 * because the window moves forward and an event that has not changed since
 * it was beyond the window would otherwise never arrive. A full read lets go
 * of every copy Google no longer returned.
 *
 * At most once every five minutes per person, unless they ask.
 */
final class Sync {

	/**
	 * Least time between two syncs for one person, in seconds.
	 */
	public const THROTTLE = 300;

	/**
	 * How often a full read happens anyway, in seconds.
	 */
	public const FULL_EVERY = DAY_IN_SECONDS;

	/**
	 * How far back the window goes, in days.
	 */
	public const BACK_DAYS = 28;

	/**
	 * Most pages read in one sync.
	 */
	private const MOST_PAGES = 20;

	/**
	 * Brings a person's calendar into step, if it is time to.
	 *
	 * @param string $user_id Forge person id.
	 * @param bool   $force   Whether to ignore the five-minute wait.
	 * @return array{ran: bool, error: string}
	 */
	public static function run( string $user_id, bool $force = false ): array {
		$connection = Connections::get( $user_id );

		if ( null === $connection || ! Settings::configured() ) {
			return self::outcome( false );
		}

		if ( ! $force && bwx_forge_now() - (int) $connection['synced_at'] < self::THROTTLE ) {
			return self::outcome( false, (string) $connection['last_error'] );
		}

		$access = self::access_token( $user_id );

		if ( is_wp_error( $access ) ) {
			Connections::mark( $user_id, $access->get_error_message() );

			return self::outcome( true, $access->get_error_message() );
		}

		$full = '' === Connections::sync_token( $user_id ) || bwx_forge_now() - (int) $connection['full_synced_at'] >= self::FULL_EVERY;
		$done = self::read( $user_id, $access, $full );

		// The token expired: start again with a full read.
		if ( is_wp_error( $done ) && Client::GONE === $done->get_error_code() && ! $full ) {
			Connections::forget_sync_token( $user_id );
			$done = self::read( $user_id, $access, true );
		}

		if ( is_wp_error( $done ) ) {
			Connections::mark( $user_id, $done->get_error_message() );

			return self::outcome( true, $done->get_error_message() );
		}

		return self::outcome( true );
	}

	/**
	 * The window a full read covers.
	 *
	 * @return array{0: string, 1: string} YYYY-MM-DD, inclusive.
	 */
	public static function window(): array {
		$today = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );

		return array(
			gmdate( 'Y-m-d', (int) strtotime( $today . ' 00:00:00 UTC' ) - ( self::BACK_DAYS * DAY_IN_SECONDS ) ),
			MeetingHours::horizon_end( $today ),
		);
	}

	/**
	 * Reads every page and applies it.
	 *
	 * @param string $user_id Forge person id.
	 * @param string $access  Access token.
	 * @param bool   $full    Whether to read the whole window.
	 * @return true|\WP_Error
	 */
	private static function read( string $user_id, string $access, bool $full ) {
		list( $from, $to ) = self::window();

		$zone  = wp_timezone();
		$query = $full
			? array(
				'singleEvents' => 'true',
				'maxResults'   => 250,
				'timeMin'      => ( new \DateTimeImmutable( $from . ' 00:00:00', $zone ) )->format( \DATE_RFC3339 ),
				'timeMax'      => ( new \DateTimeImmutable( $to . ' 23:59:59', $zone ) )->format( \DATE_RFC3339 ),
			)
			: array(
				'singleEvents' => 'true',
				'maxResults'   => 250,
				'syncToken'    => Connections::sync_token( $user_id ),
			);

		$seen  = array();
		$token = '';

		for ( $page = 0; $page < self::MOST_PAGES; $page++ ) {
			$answer = Client::events( $access, $query );

			if ( is_wp_error( $answer ) ) {
				return $answer;
			}

			foreach ( is_array( $answer['items'] ?? null ) ? $answer['items'] : array() as $item ) {
				$event = is_array( $item ) ? Events::normalise( $item, $zone ) : null;

				if ( null === $event ) {
					continue;
				}

				// A change from outside the window to something Forge has
				// never seen is not worth keeping; a deletion always is.
				$outside = '' !== $event['on'] && ( ( '' === $event['ends_on'] ? $event['on'] : $event['ends_on'] ) < $from || $event['on'] > $to );

				if ( $outside && ! $event['cancelled'] ) {
					Meetings::drop( $user_id, (string) $event['event_id'] );
					continue;
				}

				$seen[] = (string) $event['event_id'];
				Meetings::apply( $user_id, $event );
			}

			$token = (string) ( $answer['nextSyncToken'] ?? '' );
			$next  = (string) ( $answer['nextPageToken'] ?? '' );

			if ( '' === $next ) {
				break;
			}

			$query['pageToken'] = $next;
		}

		if ( $full ) {
			Meetings::drop_unseen( $user_id, $seen, $from, $to );
		}

		Connections::synced( $user_id, $token, $full );

		return true;
	}

	/**
	 * A working access token, refreshed when it is about to run out.
	 *
	 * @param string $user_id Forge person id.
	 * @return string|\WP_Error
	 */
	private static function access_token( string $user_id ) {
		$tokens = Connections::tokens( $user_id );

		if ( null === $tokens ) {
			return new \WP_Error( Client::DENIED, __( 'Forge cannot read this connection any more. Connect Google Calendar again.', 'blueworx-forge' ) );
		}

		if ( '' !== $tokens['access_token'] && $tokens['expires_at'] - 60 > bwx_forge_now() ) {
			return $tokens['access_token'];
		}

		$fresh = Client::refresh( $tokens['refresh_token'] );

		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		Connections::store_tokens( $user_id, $fresh );

		return $fresh['access_token'];
	}

	/**
	 * What a run says back.
	 *
	 * @param bool   $ran   Whether it went to Google.
	 * @param string $error What went wrong, or ''.
	 * @return array{ran: bool, error: string}
	 */
	private static function outcome( bool $ran, string $error = '' ): array {
		return array(
			'ran'   => $ran,
			'error' => $error,
		);
	}
}
