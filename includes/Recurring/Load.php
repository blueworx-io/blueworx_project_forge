<?php
/**
 * What the days ahead of a schedule cost, before its tasks exist.
 *
 * A task is made on the morning it is due, so a capacity read for next week
 * would find nothing from a schedule that costs two people an hour every
 * day — and say there is room where there is not. This projects each running
 * schedule over a window, one commitment per person per due day, in the same
 * shape as the seats and the standing meetings (2026-09-19).
 *
 * Only days after today: today's tasks, and every earlier day's, are real
 * rows by now and are counted as such by {@see \Blueworx\Forge\Capacity\Commitments}.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Recurring;

use Blueworx\Forge\Capacity\Unavailability;

/**
 * The projected load of running schedules.
 */
final class Load {

	/**
	 * The commitments running schedules make in a window, across every site.
	 *
	 * @param string $from  YYYY-MM-DD, inclusive.
	 * @param string $to    YYYY-MM-DD, inclusive.
	 * @param string $today YYYY-MM-DD; days on or before it are left to the tasks themselves.
	 * @return array<int, array<string, mixed>>
	 */
	public static function across( string $from, string $to, string $today = '' ): array {
		$today = '' === $today ? wp_date( 'Y-m-d' ) : $today;
		$start = max( $from, self::day_after( $today ) );

		if ( '' === $from || '' === $to || $to < $start ) {
			return array();
		}

		$out     = array();
		$sources = Sources::running();
		$people  = array();

		foreach ( $sources as $source ) {
			$people = array_merge( $people, array_map( 'strval', (array) ( $source['assignees'] ?? array() ) ) );
		}

		// #491. Nobody gets a task on a day they are away, so it costs nothing.
		$away = array();

		foreach ( Unavailability::for_people( array_values( array_unique( $people ) ), $start, $to ) as $user_id => $records ) {
			$away[ $user_id ] = Unavailability::expand( $records, $start, $to );
		}

		foreach ( $sources as $source ) {
			$out = array_merge( $out, self::allocations( $source, $start, $to, $away ) );
		}

		return $out;
	}

	/**
	 * One schedule's commitments over a window. Pure, so the shape can be
	 * tested.
	 *
	 * @param array<string, mixed>                 $source A hydrated source.
	 * @param string                               $from   YYYY-MM-DD, inclusive.
	 * @param string                               $to     YYYY-MM-DD, inclusive.
	 * @param array<string, array<string, string>> $away   Per person, the dates they are away.
	 * @return array<int, array<string, mixed>>
	 */
	public static function allocations( array $source, string $from, string $to, array $away = array() ): array {
		$each      = round( (float) ( $source['hours_each'] ?? 0 ), 2 );
		$assignees = (array) ( $source['assignees'] ?? array() );
		$since     = max( $from, (string) ( $source['starts_on'] ?? '' ) );

		if ( $each <= 0 || array() === $assignees || $to < $since ) {
			return array();
		}

		$out = array();

		foreach ( Rule::due_between( (array) $source['rule'], $since, $to, (string) ( $source['ends_on'] ?? '' ) ) as $date ) {
			foreach ( $assignees as $who ) {
				if ( isset( $away[ (string) $who ][ $date ] ) ) {
					continue;
				}

				$out[] = array(
					'item_id'        => (string) $source['id'] . ':' . $date,
					'title'          => (string) $source['title'],
					'client_id'      => (string) $source['client_id'],
					'client_site_id' => (string) $source['client_site_id'],
					'role'           => 'assignee',
					'user_id'        => (string) $who,
					'covering'       => '',
					'hours'          => $each,
					'from'           => $date,
					'to'             => $date,
				);
			}
		}

		return $out;
	}

	/**
	 * The day after a date.
	 *
	 * @param string $date YYYY-MM-DD.
	 * @return string
	 */
	private static function day_after( string $date ): string {
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );

		return false === $day ? $date : $day->add( new \DateInterval( 'P1D' ) )->format( 'Y-m-d' );
	}
}
