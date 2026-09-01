<?php
/**
 * The delivery numbers, worked out from the changelog.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Reports;

use Blueworx\Forge\Work\Events;
use Blueworx\Forge\Work\Stages;

/**
 * #176. Seven reports, computed every time somebody asks.
 *
 * **Nothing here is stored, and that is the whole design.** The obvious
 * implementation keeps a cycle time on the item and updates it when the item
 * moves, and then the figure and the moves behind it drift apart — a report
 * says eleven days, the changelog says nine, and the only way anybody finds out
 * is by acting on the wrong one. So a report is not a number kept alongside the
 * records. It is those records, counted, now. That is the same argument
 * {@see \Blueworx\Forge\Standup\Rules} makes about the day's list, and it is
 * what makes "each report reconciles to the records behind it" true by
 * construction rather than something a test has to keep proving.
 *
 * It also means these cover history nobody planned to report on: the log has
 * been written since #106, so the first time this screen is opened it already
 * has months behind it.
 *
 * **This file is pure.** Everything it needs is handed to it, because the
 * alternative — seven reports each reaching for their own data — is seven
 * reports nobody can argue with in a test, and a screen that costs fifty
 * queries to draw. {@see Source} does the reading, and knows nothing about what
 * any of it means.
 *
 * Medians rather than means, with the count beside them. One piece of work that
 * sat for a year moves a mean and tells nobody anything; the count is what says
 * whether the median is worth reading at all.
 */
final class Delivery {

	/**
	 * Seconds in an hour, since every duration here is reported in hours.
	 */
	private const HOUR = 3600;

	/**
	 * Seconds in a week, for the throughput buckets.
	 */
	private const WEEK = 604800;

	/**
	 * Every report, from the work and the log behind it.
	 *
	 * @param array<int, array<string, mixed>> $items  Open and closed work in reach.
	 * @param array<int, array<string, mixed>> $events The changelog for that work, oldest first.
	 * @param int                              $from   Window start, a timestamp.
	 * @param int                              $to     Window end, a timestamp.
	 * @return array<string, mixed>
	 */
	public static function compute( array $items, array $events, int $from, int $to ): array {
		$stays   = self::stays( $events, $from, $to );
		$blocked = self::blocked( $events, $from, $to );

		return array(

			/*
			 * Said once, at the top, rather than left to a screen to infer from
			 * seven empty sections. A window with nothing in it and a window
			 * where everything is zero are different answers, and only one of
			 * them means anything.
			 */
			'empty'              => array() === $items && array() === $events,
			'from'               => $from,
			'to'                 => $to,
			'stage_distribution' => self::stage_distribution( $items ),
			'time_in_stage'      => self::time_in_stage( $stays ),
			'cycle_time'         => self::cycle_time( $events, $from, $to ),
			'blocked_time'       => self::summarise( $blocked ),
			'review_turnaround'  => self::review_turnaround( $events, $from, $to ),
			'planned_vs_actual'  => self::planned_vs_actual( $items, $events, $from, $to ),
			'throughput'         => self::throughput( $events, $from, $to ),
		);
	}

	// ---- Where work is sitting -------------------------------------------

	/**
	 * How much open work is in each stage, right now.
	 *
	 * Every stage is present whether or not anything is in it. A stage that
	 * disappears when it empties is a stage somebody reads as "not applicable"
	 * rather than as "none", and the two look identical on a chart.
	 *
	 * @param array<int, array<string, mixed>> $items Work in reach.
	 * @return array<string, int>
	 */
	private static function stage_distribution( array $items ): array {
		$counts = array_fill_keys( Stages::ALL, 0 );

		foreach ( $items as $item ) {
			$stage = (string) ( $item['stage'] ?? '' );

			if ( array_key_exists( $stage, $counts ) ) {
				++$counts[ $stage ];
			}
		}

		return $counts;
	}

	// ---- Time in stage ---------------------------------------------------

	/**
	 * Every completed stay in a stage, in hours, keyed by stage.
	 *
	 * A stay is an arrival and the departure that followed it. Work still
	 * sitting somewhere has not got a stay yet and is not counted: reporting
	 * time-so-far as though the stage were finished makes the number fall every
	 * time somebody finally moves the oldest item, which is the wrong direction.
	 *
	 * A stage entered twice produces two stays. Averaging a return away hides
	 * exactly the cost the report exists to show.
	 *
	 * @param array<int, array<string, mixed>> $events The log, oldest first.
	 * @param int                              $from   Window start.
	 * @param int                              $to     Window end.
	 * @return array<string, array<int, float>>
	 */
	private static function stays( array $events, int $from, int $to ): array {
		$arrived = array();
		$stays   = array();

		foreach ( self::sorted( $events ) as $event ) {
			$at   = (int) ( $event['occurred_at'] ?? 0 );
			$item = (string) ( $event['item_id'] ?? '' );

			if ( ! self::is_move( $event ) || $at > $to ) {
				continue;
			}

			$leaving = (string) ( $event['from_stage'] ?? '' );
			$landing = (string) ( $event['to_stage'] ?? '' );

			/*
			 * Blocked is measured from the blocking itself, not from here. It
			 * is an exception stage with a prior stage behind it, so counting
			 * it as an ordinary stay counts the same hours twice — once as
			 * blocked and once as the stage the work is still notionally in.
			 */
			if ( Stages::BLOCKED !== $leaving && isset( $arrived[ $item ][ $leaving ] ) ) {
				/*
				 * A stay that began before the window counts from where the
				 * window starts. Otherwise a one-week window reports stays of a
				 * month, and nobody can reason about a figure longer than the
				 * period it claims to describe.
				 */
				$began = max( (int) $arrived[ $item ][ $leaving ], $from );

				if ( $at >= $began ) {
					$stays[ $leaving ][] = ( $at - $began ) / self::HOUR;
				}

				unset( $arrived[ $item ][ $leaving ] );
			}

			if ( Stages::BLOCKED !== $landing ) {
				$arrived[ $item ][ $landing ] = $at;
			}
		}

		return $stays;
	}

	/**
	 * Those stays, summarised per stage, with every stage present.
	 *
	 * @param array<string, array<int, float>> $stays Completed stays by stage.
	 * @return array<string, array{median_hours: float|null, count: int}>
	 */
	private static function time_in_stage( array $stays ): array {
		$report = array();

		foreach ( Stages::ALL as $stage ) {
			$report[ $stage ] = self::summarise( $stays[ $stage ] ?? array() );
		}

		return $report;
	}

	// ---- Cycle time ------------------------------------------------------

	/**
	 * First move to release, for work released inside the window.
	 *
	 * Unfinished work has no cycle time and is left out rather than counted as
	 * zero — an item still in flight reported as a cycle of nothing drags every
	 * figure towards a number nobody achieved.
	 *
	 * Work released more than once is measured to its last release: reopening
	 * something and shipping it again is one piece of work that took until then.
	 *
	 * @param array<int, array<string, mixed>> $events The log.
	 * @param int                              $from   Window start.
	 * @param int                              $to     Window end.
	 * @return array{median_hours: float|null, count: int}
	 */
	private static function cycle_time( array $events, int $from, int $to ): array {
		$started  = array();
		$released = array();

		foreach ( self::sorted( $events ) as $event ) {
			$at   = (int) ( $event['occurred_at'] ?? 0 );
			$item = (string) ( $event['item_id'] ?? '' );

			if ( ! self::is_move( $event ) ) {
				continue;
			}

			if ( ! isset( $started[ $item ] ) ) {
				$started[ $item ] = $at;
			}

			if ( Stages::RELEASED === (string) ( $event['to_stage'] ?? '' ) && $at >= $from && $at <= $to ) {
				$released[ $item ] = $at;
			}
		}

		$cycles = array();

		foreach ( $released as $item => $at ) {
			if ( isset( $started[ $item ] ) ) {
				$cycles[] = ( $at - $started[ $item ] ) / self::HOUR;
			}
		}

		return self::summarise( $cycles );
	}

	// ---- Blocked time ----------------------------------------------------

	/**
	 * How long work spent stopped, from the blocking to the unblocking.
	 *
	 * Work still blocked is not counted, for the same reason work still in a
	 * stage is not: it has not finished being blocked, and reporting how long
	 * so far makes the figure fall when somebody finally resolves the worst one.
	 *
	 * @param array<int, array<string, mixed>> $events The log.
	 * @param int                              $from   Window start.
	 * @param int                              $to     Window end.
	 * @return array<int, float>
	 */
	private static function blocked( array $events, int $from, int $to ): array {
		$since   = array();
		$stopped = array();

		foreach ( self::sorted( $events ) as $event ) {
			$at     = (int) ( $event['occurred_at'] ?? 0 );
			$item   = (string) ( $event['item_id'] ?? '' );
			$action = (string) ( $event['action'] ?? '' );

			if ( $at > $to ) {
				continue;
			}

			if ( Events::BLOCKED === $action ) {
				$since[ $item ] = $at;
				continue;
			}

			if ( Events::UNBLOCKED === $action && isset( $since[ $item ] ) ) {
				$began = max( (int) $since[ $item ], $from );

				if ( $at >= $began ) {
					$stopped[] = ( $at - $began ) / self::HOUR;
				}

				unset( $since[ $item ] );
			}
		}

		return $stopped;
	}

	// ---- Review turnaround -----------------------------------------------

	/**
	 * In Review to a decision, whichever way the decision went.
	 *
	 * A review that sends work back is a review that took time to reach, and
	 * leaving it out reports reviews as faster than they were — in the one
	 * direction that flatters. So an approval and a refusal are both decisions
	 * and both stop the clock.
	 *
	 * @param array<int, array<string, mixed>> $events The log.
	 * @param int                              $from   Window start.
	 * @param int                              $to     Window end.
	 * @return array{median_hours: float|null, count: int}
	 */
	private static function review_turnaround( array $events, int $from, int $to ): array {
		$entered = array();
		$taken   = array();

		foreach ( self::sorted( $events ) as $event ) {
			$at   = (int) ( $event['occurred_at'] ?? 0 );
			$item = (string) ( $event['item_id'] ?? '' );

			if ( $at > $to ) {
				continue;
			}

			if ( 'in-review' === (string) ( $event['to_stage'] ?? '' ) ) {
				$entered[ $item ] = $at;
				continue;
			}

			if ( 'in-review' === (string) ( $event['from_stage'] ?? '' ) && isset( $entered[ $item ] ) ) {
				$began = max( (int) $entered[ $item ], $from );

				if ( $at >= $began ) {
					$taken[] = ( $at - $began ) / self::HOUR;
				}

				unset( $entered[ $item ] );
			}
		}

		return self::summarise( $taken );
	}

	// ---- Planned against actual ------------------------------------------

	/**
	 * Work released in the window, against the date it was promised for.
	 *
	 * Work with no promised date is left out rather than counted as on time. A
	 * team that sets no dates would otherwise report a perfect record, which is
	 * the one answer this report must never give.
	 *
	 * @param array<int, array<string, mixed>> $items  Work in reach.
	 * @param array<int, array<string, mixed>> $events The log.
	 * @param int                              $from   Window start.
	 * @param int                              $to     Window end.
	 * @return array{count: int, on_time: int, late: int, median_days_late: float|null}
	 */
	private static function planned_vs_actual( array $items, array $events, int $from, int $to ): array {
		$promised = array();

		foreach ( $items as $item ) {
			$due = (string) ( $item['planned_due'] ?? '' );

			if ( '' !== $due ) {
				$promised[ (string) ( $item['id'] ?? '' ) ] = $due;
			}
		}

		$on_time = 0;
		$late    = 0;
		$slips   = array();

		foreach ( self::sorted( $events ) as $event ) {
			$at   = (int) ( $event['occurred_at'] ?? 0 );
			$item = (string) ( $event['item_id'] ?? '' );

			if ( Stages::RELEASED !== (string) ( $event['to_stage'] ?? '' ) || $at < $from || $at > $to ) {
				continue;
			}

			if ( ! isset( $promised[ $item ] ) ) {
				continue;
			}

			// The promise is a day, not a moment, so a release at any time on
			// the day it was due is on time.
			$due  = (int) strtotime( $promised[ $item ] . ' 23:59:59 UTC' );
			$slip = ( $at - $due ) / DAY_IN_SECONDS;

			if ( $at <= $due ) {
				++$on_time;
			} else {
				++$late;
				$slips[] = $slip;
			}
		}

		return array(
			'count'            => $on_time + $late,
			'on_time'          => $on_time,
			'late'             => $late,
			'median_days_late' => self::median( $slips ),
		);
	}

	// ---- Throughput ------------------------------------------------------

	/**
	 * How much was released, week by week across the window.
	 *
	 * Weeks rather than a single total, because a total cannot show a team
	 * slowing down. Bucketed from the start of the window rather than from
	 * Monday: the window is what somebody chose to look at, and shifting its
	 * first bucket to a calendar week reports days that are not in it.
	 *
	 * @param array<int, array<string, mixed>> $events The log.
	 * @param int                              $from   Window start.
	 * @param int                              $to     Window end.
	 * @return array{weeks: array<int, array{from: int, to: int, released: int}>}
	 */
	private static function throughput( array $events, int $from, int $to ): array {
		$weeks = array();

		for ( $start = $from; $start < $to; $start += self::WEEK ) {
			$weeks[] = array(
				'from'     => $start,
				'to'       => min( $start + self::WEEK, $to ),
				'released' => 0,
			);
		}

		if ( array() === $weeks ) {
			$weeks[] = array(
				'from'     => $from,
				'to'       => $to,
				'released' => 0,
			);
		}

		foreach ( $events as $event ) {
			$at = (int) ( $event['occurred_at'] ?? 0 );

			if ( Stages::RELEASED !== (string) ( $event['to_stage'] ?? '' ) || $at < $from || $at > $to ) {
				continue;
			}

			foreach ( $weeks as $index => $week ) {
				if ( $at >= $week['from'] && ( $at < $week['to'] || $week['to'] === $to ) ) {
					++$weeks[ $index ]['released'];
					break;
				}
			}
		}

		return array( 'weeks' => $weeks );
	}

	// ---- The arithmetic --------------------------------------------------

	/**
	 * A set of durations, as a median and a count.
	 *
	 * @param array<int, float> $durations Hours.
	 * @return array{median_hours: float|null, count: int}
	 */
	private static function summarise( array $durations ): array {
		return array(
			'median_hours' => self::median( $durations ),
			'count'        => count( $durations ),
		);
	}

	/**
	 * The middle value, or nothing at all where there is nothing to take a
	 * middle of.
	 *
	 * Null rather than zero, throughout. Zero is a measurement; an absence is
	 * not, and a screen that cannot tell them apart draws a chart of nothing
	 * and calls it fast delivery.
	 *
	 * @param array<int, float> $values Numbers.
	 * @return float|null
	 */
	private static function median( array $values ): ?float {
		if ( array() === $values ) {
			return null;
		}

		sort( $values );

		$count  = count( $values );
		$middle = intdiv( $count, 2 );

		if ( 0 === $count % 2 ) {
			return round( ( $values[ $middle - 1 ] + $values[ $middle ] ) / 2, 2 );
		}

		return round( $values[ $middle ], 2 );
	}

	/**
	 * Whether an event moved the work between stages.
	 *
	 * Blocking and unblocking are moves in the log and are handled on their own,
	 * so they are not counted here as well.
	 *
	 * @param array<string, mixed> $event One entry.
	 * @return bool
	 */
	private static function is_move( array $event ): bool {
		$action = (string) ( $event['action'] ?? '' );

		if ( Events::BLOCKED === $action || Events::UNBLOCKED === $action ) {
			return false;
		}

		return '' !== (string) ( $event['to_stage'] ?? '' );
	}

	/**
	 * The log, oldest first.
	 *
	 * Asserted rather than assumed. Every reading of this file depends on order,
	 * and a caller that hands over a reverse-sorted list would produce figures
	 * that are wrong and plausible.
	 *
	 * @param array<int, array<string, mixed>> $events The log.
	 * @return array<int, array<string, mixed>>
	 */
	private static function sorted( array $events ): array {
		usort(
			$events,
			static function ( array $one, array $two ): int {
				return (int) ( $one['occurred_at'] ?? 0 ) <=> (int) ( $two['occurred_at'] ?? 0 );
			}
		);

		return $events;
	}
}
