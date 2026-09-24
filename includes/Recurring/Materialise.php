<?php
/**
 * Turning due days into tasks.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Recurring;

use Blueworx\Forge\Commerce\SureCart\Sync;
use Blueworx\Forge\Work\Items;
use Blueworx\Forge\Work\Stages;
use Blueworx\Forge\Work\Transition;
use DateInterval;
use DateTimeImmutable;

/**
 * No cron, deliberately, like the rest of Forge: a due day becomes a task
 * the first time anyone opens something that lists work, not when a timer
 * fires. A studio nobody opened on Monday finds Monday's task waiting on
 * Tuesday, dated Monday, rather than never finding it at all.
 *
 * Every due day since a source was last looked at is made, one task each,
 * so missed days stack up visibly instead of being quietly absorbed — that
 * was the choice made for this feature. A paused source has its days moved
 * past without anything being made.
 *
 * Each task is claimed before it is created ({@see Occurrences::claim()}),
 * which is what makes two people opening the board at once produce one task
 * rather than two.
 */
final class Materialise {

	/**
	 * How long a run holds the door before the next one may scan.
	 */
	private const EVERY = 60;

	/**
	 * The transient that says a run happened lately.
	 */
	private const RAN = 'bwx_forge_recurring_ran';

	/**
	 * Whether this request has already run it.
	 *
	 * @var bool
	 */
	private static $done = false;

	/**
	 * Runs at most once a request and once a minute, from the routes that
	 * list work. Returns how many tasks were made.
	 *
	 * @return int
	 */
	public static function maybe(): int {
		if ( self::$done || false !== get_transient( self::RAN ) ) {
			return 0;
		}

		self::$done = true;
		set_transient( self::RAN, 1, self::EVERY );

		// Renewal dates first, so a subscription that renews today has its
		// source pinned to today before today's tasks are made.
		Sync::maybe();

		return self::run( wp_date( 'Y-m-d' ) );
	}

	/**
	 * Makes every task due on or before a day, for every source.
	 *
	 * @param string $today YYYY-MM-DD.
	 * @return int How many tasks were made.
	 */
	public static function run( string $today ): int {
		$made = 0;

		foreach ( Sources::due( $today ) as $source ) {
			$made += self::run_one( $source, $today );
		}

		return $made;
	}

	/**
	 * One source's due days up to a day.
	 *
	 * @param array<string, mixed> $source The source.
	 * @param string               $today  YYYY-MM-DD.
	 * @return int How many tasks were made.
	 */
	public static function run_one( array $source, string $today ): int {
		$made  = 0;
		$rule  = (array) $source['rule'];
		$dates = Rule::due_between( $rule, (string) $source['next_due'], $today, (string) $source['ends_on'] );

		if ( Sources::ACTIVE === (string) $source['status'] ) {
			foreach ( $dates as $date ) {
				if ( ! Occurrences::claim( (string) $source['id'], $date ) ) {
					continue;
				}

				// One copy per person (2026-09-24). The day is claimed once;
				// the occurrence remembers the first copy.
				$first = '';

				foreach ( self::copies( $source, $date ) as $values ) {
					$item = Items::create(
						(string) $source['client_site_id'],
						(string) $source['client_id'],
						$values,
						0
					);

					if ( null === $item ) {
						continue;
					}

					Transition::record_creation( $item, 0 );
					Transition::place( $item, Stages::UP_NEXT, 0, self::why( $source, $date ) );

					if ( '' === $first ) {
						$first = (string) $item['id'];
						Occurrences::record_item( (string) $source['id'], $date, $first );
					}

					++$made;
				}
			}
		}

		/*
		 * A schedule works out its own next day. A subscription source does
		 * not: its next day is the next renewal, which only SureCart knows,
		 * so it waits with no date until the next sync pins one.
		 */
		if ( Sources::SUBSCRIPTION === (string) $source['kind'] ) {
			Sources::advance( (string) $source['id'], '', 0 < $made, false );

			return $made;
		}

		$tomorrow = DateTimeImmutable::createFromFormat( '!Y-m-d', $today, wp_timezone() );
		$next     = false === $tomorrow ? '' : Rule::next_on_or_after( $rule, $tomorrow->add( new DateInterval( 'P1D' ) )->format( 'Y-m-d' ) );

		if ( '' !== (string) $source['ends_on'] && $next > (string) $source['ends_on'] ) {
			$next = '';
		}

		Sources::advance( (string) $source['id'], $next, 0 < $made );

		return $made;
	}

	/**
	 * A due day's tasks: one for each person on it, with only them assigned
	 * (2026-09-24), so each does and ticks off their own. A source with nobody
	 * on it still makes one. Pure, so the shape can be tested.
	 *
	 * @param array<string, mixed> $source The source.
	 * @param string               $date   YYYY-MM-DD.
	 * @return array<int, array<string, mixed>>
	 */
	public static function copies( array $source, string $date ): array {
		$values = self::values( $source, $date );
		$people = (array) $values['assignees'];

		if ( count( $people ) <= 1 ) {
			return array( $values );
		}

		return array_map(
			static fn( string $person ): array => array_merge( $values, array( 'assignees' => array( $person ) ) ),
			array_values( array_map( 'strval', $people ) )
		);
	}

	/**
	 * What a due day's task is made of. Pure, so the shape can be tested.
	 *
	 * @param array<string, mixed> $source The source.
	 * @param string               $date   YYYY-MM-DD.
	 * @return array<string, mixed>
	 */
	public static function values( array $source, string $date ): array {
		$description = trim( (string) $source['description'] );

		/*
		 * Who ticks it (2026-09-19). A schedule names its people. A
		 * subscription check-in names one through its connection's primary
		 * seat, and that person is its assignee, so it is ticked off the same
		 * way and goes the same place: straight to Released.
		 */
		$assignees = (array) ( $source['assignees'] ?? array() );
		$each      = (float) ( $source['hours_each'] ?? 0 );

		if ( array() === $assignees && '' !== (string) $source['primary_user_id'] ) {
			$assignees = array( (string) $source['primary_user_id'] );
			$each      = $each > 0 ? $each : (float) $source['hours_primary'];
		}

		return array(
			'title'            => self::title( (string) $source['title'], $date, (string) $source['kind'] ),
			'problem'          => '' === $description ? (string) $source['title'] : $description,
			'level'            => 'sub-feature',
			'work_type'        => (string) $source['work_type'],
			'primary_user_id'  => (string) $source['primary_user_id'],
			'reviewer_id'      => (string) $source['reviewer_id'],
			'deliverer_id'     => (string) $source['deliverer_id'],
			'hours_primary'    => (float) $source['hours_primary'],
			'hours_review'     => (float) $source['hours_review'],
			'hours_delivery'   => (float) $source['hours_delivery'],
			'priority'         => 'normal',
			'planned_start'    => $date,
			'planned_due'      => $date,
			// Recurring tasks are free (2026-09-24): nobody pays for them.
			'commercial_class' => 'free-general',
			'recurring_id'     => (string) $source['id'],
			// Who does it, each ticking their own (2026-09-18).
			'assignees'        => $assignees,
			'hours_each'       => $each,
			// The checklist it starts with, every line open (2026-09-19).
			'checklist'        => (string) wp_json_encode( array_values( (array) ( $source['checklist'] ?? array() ) ) ),
		);
	}

	/**
	 * "Weekly backups — 14 Sep": dated, so two weeks' worth do not look alike.
	 *
	 * A subscription reminder keeps its title as it is: it is made once per
	 * renewal, and the title already names the customer and the amount.
	 *
	 * @param string $title The source's title.
	 * @param string $date  YYYY-MM-DD.
	 * @param string $kind  Sources::SCHEDULE or Sources::SUBSCRIPTION.
	 * @return string
	 */
	public static function title( string $title, string $date, string $kind = Sources::SCHEDULE ): string {
		if ( Sources::SUBSCRIPTION === $kind ) {
			return $title;
		}

		$day = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );

		return false === $day ? $title : $title . ' — ' . $day->format( 'j M' );
	}

	/**
	 * The line in the task's history saying how it got to Up Next.
	 *
	 * @param array<string, mixed> $source The source.
	 * @param string               $date   YYYY-MM-DD.
	 * @return string
	 */
	private static function why( array $source, string $date ): string {
		return Sources::SUBSCRIPTION === (string) $source['kind']
			/* translators: %s: a date */
			? sprintf( __( 'Subscription renews on %s', 'blueworx-forge' ), $date )
			/* translators: %s: a date */
			: sprintf( __( 'Recurring task due %s', 'blueworx-forge' ), $date );
	}
}
