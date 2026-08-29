<?php
/**
 * What work commits of somebody's time, and over which days.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Capacity;

use Blueworx\Forge\Work\Stages;

/**
 * CAP-2's per-role hours, read as dated commitments (#137).
 *
 * An allocation is one person, committed for some hours, between two dates,
 * because of one piece of work. Defining it that way round rather than as a
 * total on an item is the whole point: every consumer in M7 — the cross-client
 * figure, the capacity view, the gate at Up Next — needs to know *when*, and if
 * each worked the dates out for itself they would disagree about the same item.
 *
 * There is no table behind this. The three seats, the two substitute seats, the
 * three hours figures and the two dates are already columns on the work item,
 * so a commitment is a reading of a row rather than a second record that can
 * drift from it.
 */
final class Allocations {

	/**
	 * The person who does the work.
	 */
	public const PRIMARY = 'primary';

	/**
	 * The person who reviews it.
	 */
	public const REVIEW = 'review';

	/**
	 * The person who ships it.
	 */
	public const DELIVERY = 'delivery';

	/**
	 * The stages at which time is committed.
	 *
	 * Up Next is where hours are reserved (COMM-2), so it is where a person
	 * starts being busy. Counting earlier stages would have everybody
	 * permanently full of ideas that will never be built, and a capacity view
	 * nobody believes is a capacity view nobody opens. Counting later ones —
	 * Completed, Released — would have finished work still taking up next
	 * month.
	 */
	public const COMMITTING = array(
		'up-next',
		'in-development',
		'in-review',
	);

	/**
	 * Each seat: the hours column, whose seat it is, and who may be covering.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const SEATS = array(
		self::PRIMARY  => array( 'hours_primary', 'primary_user_id', '' ),
		self::REVIEW   => array( 'hours_review', 'reviewer_id', 'reviewer_substitute_id' ),
		self::DELIVERY => array( 'hours_delivery', 'deliverer_id', 'deliverer_substitute_id' ),
	);

	/**
	 * Whether an item commits anybody's time at all.
	 *
	 * @param array<string, mixed> $item A hydrated work item.
	 * @return bool
	 */
	public static function counts( array $item ): bool {
		if ( ! empty( $item['archived'] ) ) {
			return false;
		}

		if ( '' !== (string) ( $item['terminal_outcome'] ?? '' ) ) {
			return false;
		}

		$stage = (string) ( $item['stage'] ?? '' );

		/*
		 * Blocked work is still on somebody's plate. It stopped for a reason
		 * outside the person's control, and the day it unblocks it has to fit
		 * somewhere — so dropping it would show room that is already spoken
		 * for. Where it was blocked before it was ever committed, it is still
		 * only an idea.
		 */
		if ( Stages::BLOCKED === $stage ) {
			$stage = (string) ( $item['prior_stage'] ?? '' );
		}

		return in_array( $stage, self::COMMITTING, true );
	}

	/**
	 * The commitments one item makes.
	 *
	 * @param array<string, mixed> $item A hydrated work item.
	 * @return array<int, array<string, mixed>>
	 */
	public static function from_item( array $item ): array {
		if ( ! self::counts( $item ) ) {
			return array();
		}

		return self::seats_of( $item );
	}

	/**
	 * The commitments an item *would* make, asked before it makes them.
	 *
	 * The stage test is deliberately not run. COMMITTING begins at Up Next, so
	 * an item on its way there is not committing anything yet — which is
	 * exactly the move #141 has to weigh. Asking from_item() would return
	 * nothing and permit everything.
	 *
	 * Archived and finished work is still refused: neither is a move anybody
	 * is making.
	 *
	 * @param array<string, mixed> $item A hydrated work item.
	 * @return array<int, array<string, mixed>>
	 */
	public static function proposed( array $item ): array {
		if ( ! empty( $item['archived'] ) || '' !== (string) ( $item['terminal_outcome'] ?? '' ) ) {
			return array();
		}

		return self::seats_of( $item );
	}

	/**
	 * Each filled seat on an item, as an allocation.
	 *
	 * @param array<string, mixed> $item A hydrated work item.
	 * @return array<int, array<string, mixed>>
	 */
	private static function seats_of( array $item ): array {
		$window = self::window( $item );

		if ( array() === $window ) {
			/*
			 * Hours with no dates are a plan nobody has finished making. #141
			 * makes both mandatory before Up Next, as its own requirement with
			 * its own message — so an item like this is left out here rather
			 * than guessed at, and the person is told about the missing dates
			 * rather than about a capacity figure derived from a guess.
			 */
			return array();
		}

		$out = array();

		foreach ( self::SEATS as $role => $columns ) {
			$hours = round( (float) ( $item[ $columns[0] ] ?? 0 ), 2 );
			$seat  = (string) ( $item[ $columns[1] ] ?? '' );
			$cover = '' === $columns[2] ? '' : (string) ( $item[ $columns[2] ] ?? '' );
			$who   = '' !== $cover ? $cover : $seat;

			if ( $hours <= 0 || '' === $who ) {
				continue;
			}

			$out[] = array(
				'item_id'        => (string) ( $item['id'] ?? '' ),
				'title'          => (string) ( $item['title'] ?? '' ),
				'client_id'      => (string) ( $item['client_id'] ?? '' ),
				'client_site_id' => (string) ( $item['client_site_id'] ?? '' ),
				'role'           => $role,
				'user_id'        => $who,
				/*
				 * Who is being covered, where somebody is standing in. The
				 * commitment follows whoever is doing the work (AUTH-4 records
				 * the seat); this says so on the face of it, so a capacity view
				 * can explain why somebody is carrying a review that is not
				 * theirs.
				 */
				'covering'       => '' !== $cover ? $seat : '',
				'hours'          => $hours,
				'from'           => $window[0],
				'to'             => $window[1],
			);
		}

		return $out;
	}

	/**
	 * One allocation's hours, day by day.
	 *
	 * Evenly across the days the person actually works, so a fortnight's job
	 * reads as a fortnight's load. The days come from Availability, which is
	 * why a day of leave carries none of it and the rest of the window absorbs
	 * it.
	 *
	 * @param array<string, mixed>             $allocation One allocation.
	 * @param array<int, array<string, mixed>> $days       Availability::by_day for the person.
	 * @return array<string, float> Date to hours.
	 */
	public static function spread( array $allocation, array $days ): array {
		$from  = (string) ( $allocation['from'] ?? '' );
		$to    = (string) ( $allocation['to'] ?? '' );
		$hours = round( (float) ( $allocation['hours'] ?? 0 ), 2 );

		if ( '' === $from || '' === $to || $hours <= 0 ) {
			return array();
		}

		$within  = array();
		$working = array();

		foreach ( $days as $day ) {
			$date = (string) ( $day['date'] ?? '' );

			if ( $date < $from || $date > $to ) {
				continue;
			}

			$within[] = $date;

			if ( (float) ( $day['hours'] ?? 0 ) > 0 ) {
				$working[] = $date;
			}
		}

		if ( array() === $working ) {
			/*
			 * Nobody works a day in this window — all leave, or their hours
			 * were never set up. The hours still exist and somebody still owes
			 * them, so they land on the first day rather than vanishing. A
			 * total that reconciles to nothing is worse than a total that looks
			 * awkward, because the awkward one gets fixed.
			 */
			$first = array() !== $within ? $within[0] : $from;

			return array( $first => $hours );
		}

		$last   = count( $working ) - 1;
		$each   = round( $hours / count( $working ), 2 );
		$spread = array();
		$run    = 0.0;

		foreach ( $working as $index => $date ) {
			// The last day takes whatever the rounding left, so the days always
			// add back up to the hours that were committed.
			$value = $last === $index ? round( $hours - $run, 2 ) : $each;

			$spread[ $date ] = $value;
			$run            += $value;
		}

		return $spread;
	}

	/**
	 * An item's window, where it has one.
	 *
	 * @param array<string, mixed> $item A hydrated work item.
	 * @return array<int, string> Empty when there are no dates.
	 */
	private static function window( array $item ): array {
		$from = (string) ( $item['planned_start'] ?? '' );
		$to   = (string) ( $item['planned_due'] ?? '' );

		if ( '' === $from && '' === $to ) {
			return array();
		}

		// One date is a plan for a day. Dates the wrong way round are a typo,
		// and reading them literally would produce an empty window that
		// silently drops the hours.
		$from = '' === $from ? $to : $from;
		$to   = '' === $to || $to < $from ? $from : $to;

		return array( $from, $to );
	}
}
