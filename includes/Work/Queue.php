<?php
/**
 * The studio's cross-client view of what clients have asked for.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

use Blueworx\Forge\Tenancy\Reach;

/**
 * Studio request review queue (#131): one place to triage every ask.
 *
 * This is the first studio screen that spans clients on purpose. Everything
 * else the studio looks at is scoped to one site, because the work itself is
 * (ARCH-3) — a board showing two sites at once would be showing two different
 * pieces of work in one column. A request has not become work yet, so it has no
 * such column to sit in, and triage is precisely the job of looking across all
 * of them at once and deciding what matters next.
 *
 * That makes the scoping rule here load-bearing in a way it is not elsewhere. A
 * board that leaks is scoped to one site and leaks that site. A queue that leaks
 * leaks every client at once. So the reach filter is applied to the rows before
 * anything else touches them, in {@see self::visible()}, and the caller's own
 * filters run afterwards over what survived — never the other way round, and
 * never as one combined pass where a mistake in the filter set could widen what
 * is visible rather than narrow it.
 *
 * **This is not the board's filter model.** #123 filters work items, and a work
 * item has a stage, a level and a priority; a submission has none of them. What
 * the two share is the rule rather than the vocabulary: a filter is a closed
 * list, in its name and in its values, and anything unrecognised is dropped
 * rather than applied. A queue that honoured an invented filter name would
 * quietly show everything the day somebody mistyped one.
 */
final class Queue {

	/**
	 * The filters whose values come from a closed list, and where that list is.
	 *
	 * @var array<string, string>
	 */
	private const FROM_LIST = array(
		'intake_state' => 'intake_state',
		'type'         => 'type',
	);

	/**
	 * The filters whose values are ids: anything, but matched exactly.
	 *
	 * A client id is not checked against a list of clients here, deliberately.
	 * Whether a client id means anything to this user is the reach's question,
	 * and it has already been answered by the time these run. A filter that
	 * validated ids too would be a second, weaker copy of that check.
	 *
	 * @var array<int, string>
	 */
	private const BY_ID = array( 'client_id' );

	/**
	 * The filters that take a single free value rather than a set.
	 *
	 * @var array<int, string>
	 */
	private const SINGLE = array( 'search' );

	/**
	 * The columns free-text search reads.
	 *
	 * Both, rather than the title alone: a client writing "the thing we talked
	 * about on Tuesday" as a title puts everything identifying in the body, and
	 * a search that missed it would be a search nobody trusts.
	 *
	 * @var array<int, string>
	 */
	private const SEARCHED = array( 'title', 'description' );

	/**
	 * Reads a filter set, keeping only what is real.
	 *
	 * @param array<string, mixed> $input Whatever arrived.
	 * @return array<string, mixed>
	 */
	public static function sanitise( array $input ): array {
		$filters = array();

		foreach ( self::FROM_LIST as $filter => $vocabulary ) {
			if ( ! array_key_exists( $filter, $input ) ) {
				continue;
			}

			$values = self::keep_known( (array) $input[ $filter ], $vocabulary );

			if ( array() !== $values ) {
				$filters[ $filter ] = $values;
			}
		}

		foreach ( self::BY_ID as $filter ) {
			if ( ! array_key_exists( $filter, $input ) ) {
				continue;
			}

			$values = array_values(
				array_filter(
					array_map( 'strval', (array) $input[ $filter ] ),
					static fn( string $one ): bool => '' !== $one
				)
			);

			if ( array() !== $values ) {
				$filters[ $filter ] = $values;
			}
		}

		foreach ( self::SINGLE as $filter ) {
			if ( ! array_key_exists( $filter, $input ) ) {
				continue;
			}

			$value = trim( (string) $input[ $filter ] );

			if ( '' !== $value ) {
				$filters[ $filter ] = $value;
			}
		}

		return $filters;
	}

	/**
	 * The rows this reach is allowed to see at all.
	 *
	 * Separate from {@see self::keep()} and applied first, because these two
	 * answer different questions and only one of them is a permission. Folding
	 * them together is how a filtering mistake becomes a disclosure.
	 *
	 * @param array<string, mixed>             $reach The caller's reach.
	 * @param array<int, array<string, mixed>> $rows  Submission rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function visible( array $reach, array $rows ): array {
		return Reach::keep_sites( $reach, $rows );
	}

	/**
	 * The rows matching a filter set.
	 *
	 * Every filter present has to match: two filters are an AND. An OR would
	 * make "requests, received" mean "everything received, plus every request",
	 * which is not what anybody working a queue is asking for.
	 *
	 * @param array<int, array<string, mixed>> $rows    Submission rows.
	 * @param array<string, mixed>             $filters Already through sanitise().
	 * @return array<int, array<string, mixed>>
	 */
	public static function keep( array $rows, array $filters ): array {
		$kept = array();

		foreach ( $rows as $row ) {
			if ( self::matches( $row, $filters ) ) {
				$kept[] = $row;
			}
		}

		return $kept;
	}

	/**
	 * Rows as the queue shows them.
	 *
	 * Not an allowlist, unlike the client's projection: everything on a
	 * submission was written either by the client or by the studio, and studio
	 * staff may see all of it. What is added is what a cross-client screen
	 * needs and a single-client one does not — the client's name, resolved
	 * here, because a queue row reading `cli_a3f9` is a row nobody can triage.
	 *
	 * @param array<int, array<string, mixed>> $rows   Submission rows.
	 * @param callable                         $lookup Takes a client id, returns
	 *                                                 the client or null.
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows( array $rows, callable $lookup ): array {
		return array_values(
			array_map(
				static function ( array $row ) use ( $lookup ): array {
					$client = $lookup( (string) ( $row['client_id'] ?? '' ) );
					$state  = (string) ( $row['intake_state'] ?? '' );

					return array_merge(
						$row,
						array(
							'intake_label' => Submissions::label( $state ),
							'client_name'  => is_array( $client ) ? (string) ( $client['display_name'] ?? '' ) : '',
						)
					);
				},
				$rows
			)
		);
	}

	/**
	 * Whether one row satisfies every filter in the set.
	 *
	 * @param array<string, mixed> $row     A submission row.
	 * @param array<string, mixed> $filters Already through sanitise().
	 * @return bool
	 */
	private static function matches( array $row, array $filters ): bool {
		foreach ( array_merge( array_keys( self::FROM_LIST ), self::BY_ID ) as $filter ) {
			if ( ! isset( $filters[ $filter ] ) ) {
				continue;
			}

			if ( ! in_array( (string) ( $row[ $filter ] ?? '' ), (array) $filters[ $filter ], true ) ) {
				return false;
			}
		}

		if ( isset( $filters['search'] ) && ! self::found( $row, (string) $filters['search'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether a search term appears in the client's words.
	 *
	 * @param array<string, mixed> $row  A submission row.
	 * @param string               $term What was typed.
	 * @return bool
	 */
	private static function found( array $row, string $term ): bool {
		$needle = mb_strtolower( $term );

		foreach ( self::SEARCHED as $column ) {
			if ( str_contains( mb_strtolower( (string) ( $row[ $column ] ?? '' ) ), $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The values of a set-valued filter that name something real.
	 *
	 * @param array<int, mixed> $values     What arrived.
	 * @param string            $vocabulary Which closed list to check against.
	 * @return array<int, string>
	 */
	private static function keep_known( array $values, string $vocabulary ): array {
		$kept = array();

		foreach ( $values as $value ) {
			$one = (string) $value;

			$known = 'intake_state' === $vocabulary
				? Submissions::is_state( $one )
				: Submissions::is_type( $one );

			if ( $known && ! in_array( $one, $kept, true ) ) {
				$kept[] = $one;
			}
		}

		return $kept;
	}
}
