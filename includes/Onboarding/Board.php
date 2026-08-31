<?php
/**
 * Every client's launch readiness in one view.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Onboarding;

/**
 * #165. The studio's cross-client onboarding board.
 *
 * The second studio screen that spans clients on purpose, after the request
 * queue (#131). Onboarding is the one thing the studio genuinely does need to
 * see across every client at once: a client is onboarding for a few weeks and
 * then never again, so the question is never "how is this one client doing" but
 * "which of the six we are onboarding is about to slip".
 *
 * **A filter narrows what is shown, never what is counted.** Every figure on a
 * row — completion, launch readiness, what is blocking it — is worked out from
 * that site's whole checklist, by {@see Progress}, exactly as the client's own
 * page works it out. Only the list of steps under the row narrows. The other
 * way round would give the same client two different completion figures
 * depending on what somebody last clicked, and a board people cannot quote from
 * is a board people stop opening.
 *
 * That is also what "reconciles to the same step records the client sees" means
 * in practice: nothing here recalculates anything. Lateness comes from
 * {@see Statuses::is_overdue}, settledness from {@see Statuses::SETTLED},
 * completion and readiness from {@see Progress::of}. A second implementation of
 * any of them is how the studio's board and the client's page come to disagree
 * about the same step, in front of the client.
 *
 * **A filter is a closed list, in its name and in its values.** Anything
 * unrecognised is dropped rather than applied, following Work\Queue — a board
 * that honoured an invented filter would quietly show everything the first time
 * somebody mistyped one.
 *
 * There is nothing in this file that reads the database, on purpose. The rules
 * above are the part worth being sure of, and they are all provable without a
 * WordPress runtime; Rest\OnboardingController does the reading and the
 * tenant-scoping, in that order.
 */
final class Board {

	/**
	 * Filters over the steps, whose values come from a closed list.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const STEP_LISTS = array(
		'status'     => Statuses::ALL,
		'owner_side' => TemplateSteps::SIDES,
	);

	/**
	 * Filters over the steps, whose values are ids taken as given.
	 *
	 * @var array<int, string>
	 */
	private const STEP_IDS = array( 'owner_id' );

	/**
	 * Filters over the steps that are simply on or off.
	 *
	 * Overdue and blocked are their own filters rather than two more statuses,
	 * because neither is one. Overdue is a fact about today's date that crosses
	 * every status, and blocked is a status somebody very often wants alongside
	 * another — "submitted or blocked, and late" is a real question and would
	 * not be askable if these were folded into the status list.
	 *
	 * @var array<int, string>
	 */
	private const STEP_FLAGS = array( 'overdue', 'blocked' );

	/**
	 * Filters over the sites, whose values are ids taken as given.
	 *
	 * Not checked against a list of real clients, deliberately. Whether a
	 * client id means anything to this person is the reach's question, and it
	 * has been answered before any of this runs. A second, weaker copy of that
	 * check here is the one somebody would eventually trust.
	 *
	 * @var array<int, string>
	 */
	private const SITE_IDS = array( 'client_id', 'template_id', 'contact_id' );

	/**
	 * The launch-readiness filter's two answers.
	 *
	 * @var array<int, string>
	 */
	private const LAUNCH = array( 'ready', 'not-ready' );

	/**
	 * The value a flag has to carry to mean anything.
	 */
	private const YES = 'yes';

	/**
	 * Reads a filter set, keeping only what is real.
	 *
	 * @param array<string, mixed> $input Whatever arrived.
	 * @return array<string, mixed>
	 */
	public static function sanitise( array $input ): array {
		$filters = array();

		foreach ( self::STEP_LISTS as $filter => $vocabulary ) {
			$values = array_values( array_intersect( self::listed( $input[ $filter ] ?? null ), $vocabulary ) );

			if ( array() !== $values ) {
				$filters[ $filter ] = $values;
			}
		}

		foreach ( array_merge( self::STEP_IDS, self::SITE_IDS ) as $filter ) {
			$values = self::listed( $input[ $filter ] ?? null );

			if ( array() !== $values ) {
				$filters[ $filter ] = $values;
			}
		}

		foreach ( self::STEP_FLAGS as $filter ) {
			if ( self::YES === (string) ( $input[ $filter ] ?? '' ) ) {
				$filters[ $filter ] = self::YES;
			}
		}

		if ( in_array( (string) ( $input['launch'] ?? '' ), self::LAUNCH, true ) ) {
			$filters['launch'] = (string) $input['launch'];
		}

		return $filters;
	}

	/**
	 * One site's row: what is true of it, and the steps somebody asked about.
	 *
	 * @param array<string, mixed>             $site    Who the row is about — client, site, template, contact.
	 * @param array<int, array<string, mixed>> $steps   Every step on that site's checklist.
	 * @param array<string, mixed>             $filters Already through sanitise().
	 * @param string                           $today   YYYY-MM-DD.
	 * @return array<string, mixed>
	 */
	public static function row( array $site, array $steps, array $filters, string $today ): array {
		$dated    = array();
		$matching = array();

		$awaiting = 0;
		$overdue  = 0;
		$blocked  = 0;
		$next_due = '';

		foreach ( $steps as $step ) {
			$step = Steps::with_lateness( $step, $today );

			$dated[] = $step;

			if ( Statuses::SUBMITTED === (string) $step['status'] ) {
				++$awaiting;
			}

			if ( Statuses::BLOCKED === (string) $step['status'] ) {
				++$blocked;
			}

			if ( $step['overdue'] ) {
				++$overdue;
			}

			$due = (string) ( $step['due_on'] ?? '' );

			// The soonest date anybody is still waiting on. A date on finished
			// work is not a date anybody is waiting for.
			if ( '' !== $due && ! Progress::is_settled( $step ) && ( '' === $next_due || $due < $next_due ) ) {
				$next_due = $due;
			}

			if ( self::step_matches( $step, $filters ) ) {
				$matching[] = $step;
			}
		}

		$progress = Progress::of( $dated );

		return array_merge(
			$site,
			array(
				'required'        => $progress['required'],
				'approved'        => $progress['approved'],
				'completion'      => $progress['completion'],
				'launch_ready'    => $progress['launch_ready'],
				'blocking'        => $progress['blocking'],
				'total'           => count( $dated ),
				'awaiting_review' => $awaiting,
				'overdue'         => $overdue,
				'blocked'         => $blocked,
				'next_due'        => $next_due,
				'steps'           => $matching,
			)
		);
	}

	/**
	 * The rows a filter set leaves on the board.
	 *
	 * Two things happen here, and the second is the one worth naming. The site
	 * filters drop rows that are not being asked about. Then, where the filter
	 * set says anything about steps at all, a site with none of them drops off
	 * too — because a step filter is somebody asking "who has one of these?",
	 * and a row with an empty list under it is not an answer to that question.
	 *
	 * A site with no steps whatsoever still appears when nothing is filtered. A
	 * checklist assigned this morning and untouched is precisely the row
	 * somebody needs to see; hiding it would hide the clients furthest from
	 * launching.
	 *
	 * @param array<int, array<string, mixed>> $rows    Rows from row().
	 * @param array<string, mixed>             $filters Already through sanitise().
	 * @return array<int, array<string, mixed>>
	 */
	public static function keep( array $rows, array $filters ): array {
		$by_step = self::filters_steps( $filters );
		$kept    = array();

		foreach ( $rows as $row ) {
			if ( ! self::site_matches( $row, $filters ) ) {
				continue;
			}

			if ( $by_step && array() === $row['steps'] ) {
				continue;
			}

			$kept[] = $row;
		}

		return $kept;
	}

	/**
	 * What the board as a whole says, for the line above it.
	 *
	 * Counted over the rows that survived the filters rather than over every
	 * site there is, so the summary always describes the thing on screen.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows from keep().
	 * @return array<string, int>
	 */
	public static function totals( array $rows ): array {
		$totals = array(
			'sites'           => count( $rows ),
			'launch_ready'    => 0,
			'awaiting_review' => 0,
			'overdue'         => 0,
			'blocked'         => 0,
		);

		foreach ( $rows as $row ) {
			if ( ! empty( $row['launch_ready'] ) ) {
				++$totals['launch_ready'];
			}

			$totals['awaiting_review'] += (int) $row['awaiting_review'];
			$totals['overdue']         += (int) $row['overdue'];
			$totals['blocked']         += (int) $row['blocked'];
		}

		return $totals;
	}

	/**
	 * Whether one step is one of the ones being asked about.
	 *
	 * Every filter present has to match: two filters are an AND. An OR would
	 * make "submitted, overdue" mean "everything submitted, plus everything
	 * late", which is not what anybody working a board is asking for.
	 *
	 * @param array<string, mixed> $step    A step, with lateness already worked out.
	 * @param array<string, mixed> $filters Already through sanitise().
	 * @return bool
	 */
	private static function step_matches( array $step, array $filters ): bool {
		foreach ( self::STEP_LISTS as $filter => $unused ) {
			if ( isset( $filters[ $filter ] ) && ! in_array( (string) ( $step[ $filter ] ?? '' ), $filters[ $filter ], true ) ) {
				return false;
			}
		}

		foreach ( self::STEP_IDS as $filter ) {
			if ( isset( $filters[ $filter ] ) && ! in_array( (string) ( $step[ $filter ] ?? '' ), $filters[ $filter ], true ) ) {
				return false;
			}
		}

		if ( isset( $filters['overdue'] ) && empty( $step['overdue'] ) ) {
			return false;
		}

		return ! isset( $filters['blocked'] ) || Statuses::BLOCKED === (string) ( $step['status'] ?? '' );
	}

	/**
	 * Whether one site is one of the ones being asked about.
	 *
	 * @param array<string, mixed> $row     A row from row().
	 * @param array<string, mixed> $filters Already through sanitise().
	 * @return bool
	 */
	private static function site_matches( array $row, array $filters ): bool {
		foreach ( self::SITE_IDS as $filter ) {
			if ( isset( $filters[ $filter ] ) && ! in_array( (string) ( $row[ $filter ] ?? '' ), $filters[ $filter ], true ) ) {
				return false;
			}
		}

		if ( ! isset( $filters['launch'] ) ) {
			return true;
		}

		return ( 'ready' === $filters['launch'] ) === (bool) $row['launch_ready'];
	}

	/**
	 * Whether the filter set says anything about steps.
	 *
	 * @param array<string, mixed> $filters Already through sanitise().
	 * @return bool
	 */
	private static function filters_steps( array $filters ): bool {
		$named = array_merge( array_keys( self::STEP_LISTS ), self::STEP_IDS, self::STEP_FLAGS );

		return array() !== array_intersect( $named, array_keys( $filters ) );
	}

	/**
	 * A filter value as a list of strings.
	 *
	 * A set can arrive as a repeated parameter or as one comma-separated value,
	 * because both are how a query string carries one and the screen should not
	 * have to care which the caller wrote.
	 *
	 * @param mixed $value Whatever arrived.
	 * @return array<int, string>
	 */
	private static function listed( $value ): array {
		if ( null === $value ) {
			return array();
		}

		$parts = is_array( $value ) ? $value : explode( ',', (string) $value );

		return array_values(
			array_filter(
				array_map(
					static fn( $one ): string => trim( (string) $one ),
					$parts
				),
				static fn( string $one ): bool => '' !== $one
			)
		);
	}
}
