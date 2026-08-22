<?php
/**
 * What a client site is allowed to see of a work item.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

use Blueworx\Forge\Tenancy\Contacts;

/**
 * The client-visible projection of a work item (#128).
 *
 * This is an allowlist, and the direction matters more than the contents. A
 * projection written as "the row, minus the private columns" leaks every column
 * added after it was written, and it leaks them silently — the screen simply
 * starts showing something nobody decided to show. Written this way round, a
 * new column is absent until somebody names it here, which is a decision with a
 * diff attached.
 *
 * What is deliberately not here, each ruled out rather than forgotten:
 *
 * - Internal notes, gate records and approver identities (AUTH-5).
 * - The substitute seats. Who stood in for whom is AUTH-4's record, and the
 *   client's question is "who is doing this", not "who is covering".
 * - Planned hours. Clients see an hour ledger under M8, where a number has a
 *   balance around it; per-item estimates without that context are an argument.
 * - Priority and commercial class. Both are internal judgements a client would
 *   be told, not shown — `unclassified` in particular says only that nobody has
 *   decided yet.
 *
 * Every one of those can be added later against a decision. None of them can be
 * un-shown once a client has seen it.
 */
final class ClientView {

	/**
	 * The seats a client sees, and the key each is published under.
	 *
	 * @var array<string, string>
	 */
	private const SEATS = array(
		'primary'   => 'primary_user_id',
		'reviewer'  => 'reviewer_id',
		'deliverer' => 'deliverer_id',
	);

	/**
	 * A list of rows, as a client may see them.
	 *
	 * @param array<int, array<string, mixed>> $rows   Work item rows.
	 * @param callable                         $lookup Takes a user id, returns
	 *                                                 the person or null.
	 * @return array<int, array<string, mixed>>
	 */
	public static function items( array $rows, callable $lookup ): array {
		return array_values(
			array_map(
				static fn( array $row ): array => self::item( $row, $lookup ),
				$rows
			)
		);
	}

	/**
	 * One row, as a client may see it.
	 *
	 * @param array<string, mixed> $row    A work item row.
	 * @param callable             $lookup Takes a user id, returns the person
	 *                                     or null.
	 * @return array<string, mixed>
	 */
	public static function item( array $row, callable $lookup ): array {
		return array(
			'id'             => (string) ( $row['id'] ?? '' ),
			'parent_id'      => (string) ( $row['parent_id'] ?? '' ),
			'title'          => (string) ( $row['title'] ?? '' ),
			'stage'          => (string) ( $row['stage'] ?? '' ),
			'level'          => (string) ( $row['level'] ?? '' ),
			'work_type'      => (string) ( $row['work_type'] ?? '' ),
			'planned_start'  => (string) ( $row['planned_start'] ?? '' ),
			'planned_due'    => (string) ( $row['planned_due'] ?? '' ),
			'review_target'  => (string) ( $row['review_target'] ?? '' ),
			'release_target' => (string) ( $row['release_target'] ?? '' ),
			'people'         => self::people( $row, $lookup ),
		);
	}

	/**
	 * The three seats, each a display name or nothing.
	 *
	 * An empty seat and a seat naming somebody who has since been removed both
	 * come back empty rather than person-shaped, so a screen can say "nobody
	 * yet" without having to guess whether a name failed to load.
	 *
	 * @param array<string, mixed> $row    A work item row.
	 * @param callable             $lookup Takes a user id, returns the person
	 *                                     or null.
	 * @return array<string, array<string, string>>
	 */
	private static function people( array $row, callable $lookup ): array {
		$people = array();

		foreach ( self::SEATS as $seat => $column ) {
			$id = (string) ( $row[ $column ] ?? '' );

			$people[ $seat ] = '' === $id
				? array()
				: Contacts::for_client( $lookup( $id ) );
		}

		return $people;
	}
}
