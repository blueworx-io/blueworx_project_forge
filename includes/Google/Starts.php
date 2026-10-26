<?php
/**
 * The day each person first connected Google Calendar.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Google;

/**
 * #657. A person's start date is the day, in studio time, they first
 * connected. Meetings before it were accounted for by hand, so Forge never
 * reads them. It lives in an option rather than on the connection, because
 * disconnecting deletes the connection and a reconnect must not move it.
 */
final class Starts {

	/**
	 * Option: person id => YYYY-MM-DD.
	 */
	public const OPTION = 'bwx_forge_google_starts';

	/**
	 * A person's start date, or '' when they have never connected.
	 *
	 * A connection made before start dates existed takes the day it was made.
	 *
	 * @param string $user_id Forge person id.
	 * @return string YYYY-MM-DD, or ''.
	 */
	public static function of( string $user_id ): string {
		$map = self::all();

		if ( isset( $map[ $user_id ] ) ) {
			return $map[ $user_id ];
		}

		$connection = Connections::get( $user_id );

		if ( null === $connection || (int) $connection['connected_at'] <= 0 ) {
			return '';
		}

		$day = ( new \DateTimeImmutable( '@' . (int) $connection['connected_at'] ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' );

		update_option( self::OPTION, self::with( $map, $user_id, $day ), false );

		return $day;
	}

	/**
	 * Records today as a person's start date, unless they already have one.
	 *
	 * @param string $user_id Forge person id.
	 */
	public static function record( string $user_id ): void {
		$today = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );

		update_option( self::OPTION, self::with( self::all(), $user_id, $today ), false );
	}

	/**
	 * The map with a person's start date added; one already there never moves.
	 *
	 * @param array<string, string> $map     Person id => YYYY-MM-DD.
	 * @param string                $user_id Forge person id.
	 * @param string                $day     YYYY-MM-DD.
	 * @return array<string, string>
	 */
	public static function with( array $map, string $user_id, string $day ): array {
		if ( '' !== $user_id && ! isset( $map[ $user_id ] ) ) {
			$map[ $user_id ] = $day;
		}

		return $map;
	}

	/**
	 * Where a read starts: the later of the usual window and the start date.
	 *
	 * @param string $back_from YYYY-MM-DD.
	 * @param string $start     YYYY-MM-DD, or ''.
	 * @return string YYYY-MM-DD.
	 */
	public static function window_from( string $back_from, string $start ): string {
		return $start > $back_from ? $start : $back_from;
	}

	/**
	 * Everyone's start dates.
	 *
	 * @return array<string, string>
	 */
	private static function all(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? array_map( 'strval', $stored ) : array();
	}
}
