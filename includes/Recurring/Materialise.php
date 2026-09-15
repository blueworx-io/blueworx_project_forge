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

				$item = Items::create(
					(string) $source['client_site_id'],
					(string) $source['client_id'],
					self::values( $source, $date ),
					0
				);

				if ( null === $item ) {
					continue;
				}

				Transition::record_creation( $item, 0 );
				Transition::place( $item, Stages::UP_NEXT, 0, self::why( $source, $date ) );
				Occurrences::record_item( (string) $source['id'], $date, (string) $item['id'] );

				++$made;
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
	 * What a due day's task is made of. Pure, so the shape can be tested.
	 *
	 * @param array<string, mixed> $source The source.
	 * @param string               $date   YYYY-MM-DD.
	 * @return array<string, mixed>
	 */
	public static function values( array $source, string $date ): array {
		$description = trim( (string) $source['description'] );

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
			'commercial_class' => 'unclassified',
			'recurring_id'     => (string) $source['id'],
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
