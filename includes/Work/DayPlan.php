<?php
/**
 * The order a person sets on their day in My tasks.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

/**
 * #620. The day planner is a view of a person's tasks; the only thing it keeps
 * is the order they arranged it in, for one day.
 *
 * Held against the WordPress account, like a saved view: it travels with the
 * person and nobody else's request can reach it. It holds task ids and nothing
 * else, so it can never change a task, and an id for work that has since gone
 * is simply ignored when the plan is drawn.
 *
 * #635. It also keeps when the day was planned, so the plan holds each task
 * in its slot as the day goes on rather than chasing the clock.
 *
 * #649. And the times each task was placed at, so the day is drawn from them
 * and nothing moves until the person edits it or plans the day again. A plan
 * kept before then has no times (null), and is laid out once and kept.
 */
final class DayPlan {

	/**
	 * Where a person's plans live.
	 */
	public const META_KEY = 'bwx_forge_day_plans';

	/**
	 * How many days are kept. The next day starts from scratch, so a few is
	 * plenty and the row stays small.
	 */
	public const DAYS = 3;

	/**
	 * The most ids kept in either list.
	 */
	public const MOST = 300;

	/**
	 * The most placed pieces kept: a task split around meetings has several.
	 */
	public const MOST_SLOTS = 200;

	/**
	 * One person's order for one day.
	 *
	 * @param int    $wp_user_id WordPress user id.
	 * @param string $day        YYYY-MM-DD.
	 * @return array{order: array<int, string>, down: array<int, string>, start: int|null, slots: array<int, array{id: string, start: int, end: int}>|null}
	 */
	public static function for_day( int $wp_user_id, string $day ): array {
		$stored = $wp_user_id > 0 ? get_user_meta( $wp_user_id, self::META_KEY, true ) : array();
		$plan   = is_array( $stored ) && is_array( $stored[ $day ] ?? null ) ? $stored[ $day ] : array();

		return array(
			'order' => self::ids( $plan['order'] ?? array() ),
			'down'  => self::ids( $plan['down'] ?? array() ),
			'start' => self::minute( $plan['start'] ?? null ),
			'slots' => self::slots( $plan['slots'] ?? null ),
		);
	}

	/**
	 * Saves the order for one day.
	 *
	 * @param int               $wp_user_id WordPress user id.
	 * @param string            $day        YYYY-MM-DD.
	 * @param array<int, mixed> $order      Task ids, in the order set.
	 * @param array<int, mixed> $down       Task ids sent down to "doesn't fit".
	 * @param mixed             $start      When the day was planned, in minutes from midnight.
	 * @param mixed             $slots      Where each task was placed (#649): {id, start, end} pieces.
	 * @return bool Whether it was saved.
	 */
	public static function save( int $wp_user_id, string $day, array $order, array $down, $start = null, $slots = null ): bool {
		if ( $wp_user_id <= 0 || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
			return false;
		}

		$stored = get_user_meta( $wp_user_id, self::META_KEY, true );
		$stored = is_array( $stored ) ? $stored : array();

		$stored[ $day ] = array(
			'order' => self::ids( $order ),
			'down'  => self::ids( $down ),
			'start' => self::minute( $start ),
			'slots' => self::slots( $slots ),
		);

		// Newest days last; keep only the latest few.
		ksort( $stored );
		$stored = array_slice( $stored, -self::DAYS, null, true );

		update_user_meta( $wp_user_id, self::META_KEY, $stored );

		return true;
	}

	/**
	 * A minute of the day, or null for anything that is not one.
	 *
	 * @param mixed $raw Whatever arrived or was stored.
	 * @return int|null
	 */
	private static function minute( $raw ): ?int {
		return is_int( $raw ) && $raw >= 0 && $raw < 1440 ? $raw : null;
	}

	/**
	 * Placed pieces brought back to {id, start, end} with whole minutes of the
	 * day, end after start; null when there were none to keep (#649).
	 *
	 * @param mixed $raw Whatever arrived or was stored.
	 * @return array<int, array{id: string, start: int, end: int}>|null
	 */
	private static function slots( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$out = array();

		foreach ( $raw as $piece ) {
			if ( ! is_array( $piece ) ) {
				continue;
			}
			$id    = $piece['id'] ?? null;
			$start = $piece['start'] ?? null;
			$end   = $piece['end'] ?? null;
			if ( is_string( $id ) && 1 === preg_match( '/^[A-Za-z0-9_:\-]{1,64}$/', $id ) && is_int( $start ) && is_int( $end ) && $start >= 0 && $end > $start && $end <= 1440 ) {
				$out[] = array(
					'id'    => $id,
					'start' => $start,
					'end'   => $end,
				);
			}
		}

		return array_slice( $out, 0, self::MOST_SLOTS );
	}

	/**
	 * A list brought back to plain ids: strings of id characters only, once each, capped.
	 *
	 * @param mixed $raw Whatever arrived or was stored.
	 * @return array<int, string>
	 */
	private static function ids( $raw ): array {
		$out = array();

		foreach ( is_array( $raw ) ? $raw : array() as $id ) {
			if ( is_string( $id ) && 1 === preg_match( '/^[A-Za-z0-9_:\-]{1,64}$/', $id ) && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}

		return array_slice( $out, 0, self::MOST );
	}
}
