<?php
/**
 * What goes in a client's daily and weekly email.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Notifications;

use Blueworx\Forge\Capacity\Allocations;
use Blueworx\Forge\Work\ClientReviewer;
use Blueworx\Forge\Work\Stages;
use DateTimeImmutable;
use DateTimeZone;

/**
 * #673. Two emails a client gets without anything having to happen first.
 *
 * The daily goes out on a weekday that involves them: the studio has work
 * booked for them that day, or something is waiting on them. A day with
 * neither sends nothing, which is the point of it — an email every morning
 * is an email nobody opens by the second week.
 *
 * The weekly is the round-up: what finished last week, what is booked or due
 * this week, and the support hours left.
 *
 * Everything here is worked out from records handed in. Reading them, and
 * deciding who it goes to, is DigestRun's.
 *
 * Pure.
 */
final class Digests {

	/**
	 * The hour, on the client's own clock, from which the day's email may go.
	 */
	public const FROM_HOUR = 8;

	/**
	 * Onboarding statuses where the step is no longer the client's to do. The
	 * client site's own checklist keeps the same list (Client\Checklist).
	 *
	 * @var array<int, string>
	 */
	private const NOT_THEIRS_NOW = array( 'submitted', 'approved', 'not-applicable' );

	/**
	 * Which emails are due at a moment on the client's clock, and the number
	 * each is raised under.
	 *
	 * The number is what makes each one go once: the daily is named after its
	 * day and the weekly after its week's Monday. So a client site that was
	 * asleep at eight still gets the daily at ten, one that was asleep all of
	 * Monday still gets the weekly on Tuesday, and neither is ever sent twice.
	 *
	 * @param DateTimeImmutable $local Now, in the client's timezone.
	 * @return array<string, int> Event kind to occurrence.
	 */
	public static function due( DateTimeImmutable $local ): array {
		$weekday = (int) $local->format( 'N' );

		if ( $weekday > 5 || (int) $local->format( 'G' ) < self::FROM_HOUR ) {
			return array();
		}

		return array(
			Events::DAILY  => (int) $local->format( 'Ymd' ),
			Events::WEEKLY => (int) $local->modify( '-' . ( $weekday - 1 ) . ' days' )->format( 'Ymd' ),
		);
	}

	/**
	 * The day an occurrence names, as YYYY-MM-DD, or '' when it names none.
	 *
	 * @param int $occurrence The number from {@see self::due()}.
	 * @return string
	 */
	public static function day_of( int $occurrence ): string {
		$digits = (string) $occurrence;

		if ( 8 !== strlen( $digits ) ) {
			return '';
		}

		$day = substr( $digits, 0, 4 ) . '-' . substr( $digits, 4, 2 ) . '-' . substr( $digits, 6, 2 );

		return false === DateTimeImmutable::createFromFormat( '!Y-m-d', $day ) ? '' : $day;
	}

	/**
	 * The daily: what the studio is working on today, and what waits on the
	 * client.
	 *
	 * Waiting on the client is four things: questions we asked that nobody
	 * has answered, work in review that is theirs to approve, work on hold
	 * until we hear from them, and onboarding steps of theirs due today or
	 * overdue.
	 *
	 * @param array<int, array<string, mixed>> $items     The site's work items.
	 * @param array<string, int>               $questions Item id to how many
	 *                                                    questions are unanswered.
	 * @param array<int, array<string, mixed>> $steps     The site's onboarding steps.
	 * @param string                           $today     YYYY-MM-DD.
	 * @return array{working: array<int, array<string, string>>, waiting: array<int, array<string, string>>}
	 */
	public static function daily( array $items, array $questions, array $steps, string $today ): array {
		$working = array();
		$waiting = array();

		foreach ( $items as $item ) {
			if ( ! self::is_open( $item ) ) {
				continue;
			}

			$id    = (string) ( $item['id'] ?? '' );
			$stage = (string) ( $item['stage'] ?? '' );
			$line  = array(
				'id'    => $id,
				'title' => (string) ( $item['title'] ?? '' ),
			);

			if ( Stages::BLOCKED !== $stage && self::booked_on( $item, $today, $today ) ) {
				$working[] = $line;
			}

			$asked = (int) ( $questions[ $id ] ?? 0 );

			if ( $asked > 0 ) {
				$waiting[] = $line + array( 'why' => 1 === $asked ? '1 question to answer' : $asked . ' questions to answer' );
			} elseif ( ClientReviewer::awaiting( $item ) ) {
				$waiting[] = $line + array( 'why' => 'ready for your review' );
			} elseif ( Stages::BLOCKED === $stage && ! empty( $item['blocked_on_client'] ) ) {
				$waiting[] = $line + array( 'why' => 'on hold until we hear from you' );
			}
		}

		foreach ( $steps as $step ) {
			$due = (string) ( $step['due_on'] ?? '' );

			if ( 'client' !== (string) ( $step['owner_side'] ?? '' )
				|| in_array( (string) ( $step['status'] ?? '' ), self::NOT_THEIRS_NOW, true )
				|| '' === $due
				|| $due > $today
			) {
				continue;
			}

			$waiting[] = array(
				'id'    => '',
				'title' => (string) ( $step['title'] ?? '' ),
				'why'   => $due === $today ? 'onboarding step due today' : 'onboarding step overdue',
			);
		}

		return array(
			'working' => $working,
			'waiting' => $waiting,
		);
	}

	/**
	 * The weekly: what finished last week, and what is booked or due this one.
	 *
	 * The support hours are added by whoever reads the ledger; with them
	 * absent, the email has no hours line.
	 *
	 * @param array<int, array<string, mixed>> $items    The site's work items.
	 * @param array<string, string>            $finished Item id to the stage it
	 *                                                   reached last week
	 *                                                   (completed or released).
	 * @param string                           $monday   This week's Monday, YYYY-MM-DD.
	 * @return array{finished: array<int, array<string, string>>, coming: array<int, array<string, string>>}
	 */
	public static function weekly( array $items, array $finished, string $monday ): array {
		$sunday = ( new DateTimeImmutable( $monday, new DateTimeZone( 'UTC' ) ) )->modify( '+6 days' )->format( 'Y-m-d' );
		$done   = array();
		$coming = array();

		foreach ( $items as $item ) {
			$id   = (string) ( $item['id'] ?? '' );
			$line = array(
				'id'    => $id,
				'title' => (string) ( $item['title'] ?? '' ),
			);

			if ( isset( $finished[ $id ] ) && empty( $item['archived'] ) ) {
				$done[] = $line + array( 'why' => Stages::RELEASED === $finished[ $id ] ? 'now live' : 'done, going live soon' );
				continue;
			}

			if ( ! self::is_open( $item ) ) {
				continue;
			}

			$due = (string) ( $item['planned_due'] ?? '' );

			if ( '' !== $due && $due >= $monday && $due <= $sunday ) {
				$coming[] = $line + array( 'why' => 'due ' . self::short_day( $due ) );
			} elseif ( self::booked_on( $item, $monday, $sunday ) ) {
				$coming[] = $line + array( 'why' => 'being worked on' );
			}
		}

		return array(
			'finished' => $done,
			'coming'   => $coming,
		);
	}

	/**
	 * Whether a digest has nothing in it, and so is not worth sending.
	 *
	 * @param array<string, mixed> $digest From {@see self::daily()} or
	 *                                     {@see self::weekly()}, with or without hours.
	 * @return bool
	 */
	public static function is_empty( array $digest ): bool {
		foreach ( $digest as $part ) {
			if ( is_array( $part ) ? array() !== $part : null !== $part ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the work is still going: not finished, ended or archived.
	 *
	 * @param array<string, mixed> $item A work item.
	 * @return bool
	 */
	private static function is_open( array $item ): bool {
		return empty( $item['archived'] )
			&& '' === (string) ( $item['terminal_outcome'] ?? '' )
			&& ! in_array( (string) ( $item['stage'] ?? '' ), array( Stages::COMPLETED, Stages::RELEASED ), true );
	}

	/**
	 * Whether anybody has a part of this still to do, booked between two days.
	 *
	 * Capacity's own answer, so the email and the studio's calendar cannot
	 * disagree about what is booked when.
	 *
	 * @param array<string, mixed> $item A work item.
	 * @param string               $from YYYY-MM-DD.
	 * @param string               $to   YYYY-MM-DD.
	 * @return bool
	 */
	private static function booked_on( array $item, string $from, string $to ): bool {
		foreach ( Allocations::from_item( $item ) as $allocation ) {
			if ( Allocations::TO_DO === $allocation['status'] && $allocation['from'] <= $to && $allocation['to'] >= $from ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A date as "11 Nov".
	 *
	 * @param string $day YYYY-MM-DD.
	 * @return string
	 */
	private static function short_day( string $day ): string {
		return ( new DateTimeImmutable( $day, new DateTimeZone( 'UTC' ) ) )->format( 'j M' );
	}
}
