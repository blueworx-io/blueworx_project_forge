<?php
/**
 * One meeting: what the rule says, and what actually happened.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Meetings;

/**
 * #153, MEET-2 and MEET-5. Pure, and the only place the two halves are joined.
 *
 * **Most meetings have no row.** A weekly series running for two years is one
 * record, and the hundred meetings it implies are worked out when somebody
 * asks. A meeting gets a row of its own only when something happens to it: it
 * moves, it is cancelled, it is held, or its hours are changed by hand.
 *
 * **A stored meeting is tied to the slot it came from, not to a date.** That is
 * the difference between moving one meeting and breaking a series. An exception
 * saying "the meeting the rule put on 12 January is now on the 14th" survives
 * the rule changing underneath it; one saying "12 January is now the 14th"
 * starts applying to a different meeting the moment the pattern moves. And
 * rewriting the rule to make one meeting late moves every meeting after it.
 *
 * {@see merge()} is where the two are put together, and it is deliberately not
 * a simple overlay: a meeting can move *out* of the window somebody is asking
 * about and another can move *in*, and an implementation that only replaces
 * calculated dates loses the second one entirely.
 */
final class Occurrence {

	/**
	 * Id prefix for a stored occurrence.
	 */
	public const PREFIX = 'mto';

	/**
	 * Arranged, and nothing has happened to it yet.
	 */
	public const SCHEDULED = 'scheduled';

	/**
	 * It happened. The only status that costs a client anything (MEET-5).
	 */
	public const HELD = 'held';

	/**
	 * Called off. No charge, however late (MEET-5).
	 */
	public const CANCELLED = 'cancelled';

	/**
	 * Nobody came. Also no charge (MEET-5).
	 */
	public const NO_SHOW = 'no-show';

	/**
	 * Everything that can have become of a meeting.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = array(
		self::SCHEDULED,
		self::HELD,
		self::CANCELLED,
		self::NO_SHOW,
	);

	/**
	 * The statuses that mean the question has been answered.
	 *
	 * @var array<int, string>
	 */
	public const SETTLED = array( self::HELD, self::CANCELLED, self::NO_SHOW );

	/**
	 * Whether a string is one of the four.
	 *
	 * @param string $status Candidate.
	 * @return bool
	 */
	public static function exists( string $status ): bool {
		return in_array( $status, self::STATUSES, true );
	}

	/**
	 * Whether a meeting in this state takes hours from the client.
	 *
	 * MEET-5, and the single question #154 asks of a status. Only a held
	 * meeting draws hours: there is no late-cancellation charge and no
	 * no-show charge, and clients are not required to cancel at all. Asked here
	 * once rather than by each caller comparing strings, because the caller who
	 * gets it wrong bills somebody for a meeting that never happened.
	 *
	 * @param string $status One of {@see self::STATUSES}.
	 * @return bool
	 */
	public static function draws_hours( string $status ): bool {
		return self::HELD === $status;
	}

	/**
	 * Whether anything has become of a meeting yet.
	 *
	 * @param string $status One of {@see self::STATUSES}.
	 * @return bool
	 */
	public static function settled( string $status ): bool {
		return in_array( $status, self::SETTLED, true );
	}

	/**
	 * How a status reads to a person.
	 *
	 * @param string $status One of {@see self::STATUSES}.
	 * @return string
	 */
	public static function label( string $status ): string {
		switch ( $status ) {
			case self::HELD:
				return __( 'Held', 'blueworx-forge' );
			case self::CANCELLED:
				return __( 'Cancelled', 'blueworx-forge' );
			case self::NO_SHOW:
				return __( 'Nobody came', 'blueworx-forge' );
			case self::SCHEDULED:
				return __( 'Scheduled', 'blueworx-forge' );
			default:
				return __( 'Unknown', 'blueworx-forge' );
		}
	}

	/**
	 * The meetings in a window: the rule's, with what actually happened over it.
	 *
	 * @param array<int, array<string, mixed>> $calculated What the rule implies
	 *                                                     for the window, from
	 *                                                     {@see Series::occurrences()}.
	 * @param array<int, array<string, mixed>> $stored     Every exception whose
	 *                                                     origin *or* whose date
	 *                                                     touches the window.
	 * @param string                           $from       YYYY-MM-DD, inclusive.
	 * @param string                           $to         YYYY-MM-DD, inclusive.
	 * @return array<int, array<string, mixed>> In the order they happen.
	 */
	public static function merge( array $calculated, array $stored, string $from = '', string $to = '' ): array {
		$excepted = array();

		foreach ( $stored as $exception ) {
			$slot = (string) ( $exception['excepted_from'] ?? '' );

			if ( '' !== $slot ) {
				$excepted[ $slot ] = true;
			}
		}

		$meetings = array();

		foreach ( $calculated as $occurrence ) {
			/*
			 * A slot with an exception against it is represented by that
			 * exception and not by the rule. Keeping both would show the
			 * meeting twice — once where it was arranged and once where it
			 * actually is — which is exactly what somebody moving a meeting is
			 * trying to stop happening.
			 */
			if ( isset( $excepted[ (string) $occurrence['on'] ] ) ) {
				continue;
			}

			$meetings[] = self::from_rule( $occurrence );
		}

		/*
		 * Every exception that lands in the window, wherever it came from. Both
		 * directions matter and they are not symmetrical:
		 *
		 * - A meeting moved *out* has had its calculated date dropped above and
		 *   is not added back here, so it leaves the window entirely.
		 * - A meeting moved *in* from another month has no calculated date here
		 *   to be matched against, so an overlay that only ever replaced would
		 *   lose it — and a client would arrive at a meeting the studio's own
		 *   screen says is not happening.
		 *
		 * The window is filtered here rather than in the query that fetched
		 * these, because this is the half that is subtle and this is the half
		 * that has tests.
		 */
		foreach ( $stored as $exception ) {
			if ( ! self::within( (string) ( $exception['on'] ?? '' ), $from, $to ) ) {
				continue;
			}

			$meetings[] = self::from_exception( $exception );
		}

		usort(
			$meetings,
			static function ( array $a, array $b ): int {
				if ( $a['on'] !== $b['on'] ) {
					return (string) $a['on'] <=> (string) $b['on'];
				}

				// Two meetings on one day is unusual and not impossible, so the
				// tie is broken by time and then by id rather than by whichever
				// happened to be added first.
				return array( $a['at'], $a['id'] ) <=> array( $b['at'], $b['id'] );
			}
		);

		return $meetings;
	}

	/**
	 * Whether a date falls inside the window being asked about.
	 *
	 * An unset end of the window means unbounded that way, so a caller that
	 * genuinely wants everything can say so rather than inventing dates far
	 * enough out to be safe.
	 *
	 * @param string $date One date, YYYY-MM-DD.
	 * @param string $from Start of the window, or '' for no start.
	 * @param string $to   End of the window, or '' for no end.
	 * @return bool
	 */
	private static function within( string $date, string $from, string $to ): bool {
		if ( '' === $date ) {
			return false;
		}

		if ( '' !== $from && $date < $from ) {
			return false;
		}

		return '' === $to || $date <= $to;
	}

	/**
	 * A meeting the rule implies and nobody has touched.
	 *
	 * @param array<string, mixed> $occurrence One calculated occurrence.
	 * @return array<string, mixed>
	 */
	private static function from_rule( array $occurrence ): array {
		return array(
			'id'            => '',
			'series_id'     => (string) ( $occurrence['series_id'] ?? '' ),
			'on'            => (string) $occurrence['on'],
			'at'            => (string) $occurrence['at'],
			'starts_at'     => (int) $occurrence['starts_at'],
			'ends_at'       => (int) $occurrence['ends_at'],
			'planned_hours' => (float) $occurrence['planned_hours'],
			'status'        => self::SCHEDULED,
			'status_label'  => self::label( self::SCHEDULED ),
			'excepted_from' => '',
			'meeting_link'  => '',

			// Whether there is a row behind this, which decides whether acting
			// on it writes one or edits one.
			'stored'        => false,
			'moved'         => false,
		);
	}

	/**
	 * A meeting something has happened to.
	 *
	 * @param array<string, mixed> $exception One stored occurrence.
	 * @return array<string, mixed>
	 */
	private static function from_exception( array $exception ): array {
		$on     = (string) ( $exception['on'] ?? '' );
		$from   = (string) ( $exception['excepted_from'] ?? '' );
		$status = (string) ( $exception['status'] ?? self::SCHEDULED );
		$status = self::exists( $status ) ? $status : self::SCHEDULED;

		return array(
			'id'            => (string) ( $exception['id'] ?? '' ),
			'series_id'     => (string) ( $exception['series_id'] ?? '' ),
			'on'            => $on,
			'at'            => (string) ( $exception['at'] ?? '' ),
			'starts_at'     => (int) ( $exception['starts_at'] ?? 0 ),
			'ends_at'       => (int) ( $exception['ends_at'] ?? 0 ),
			'planned_hours' => (float) ( $exception['planned_hours'] ?? 0 ),
			'status'        => $status,
			'status_label'  => self::label( $status ),
			'excepted_from' => $from,
			'meeting_link'  => (string) ( $exception['meeting_link'] ?? '' ),
			'stored'        => true,

			// So a screen can say "moved from the twelfth" rather than showing a
			// meeting on a Wednesday with no explanation for it.
			'moved'         => '' !== $from && $from !== $on,
		);
	}
}
