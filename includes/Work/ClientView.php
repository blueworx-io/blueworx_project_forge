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
			'stage_label'    => Stages::label( (string) ( $row['stage'] ?? '' ) ),
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
	 * A list of submissions, as the client who sent them may see them (#130).
	 *
	 * @param array<int, array<string, mixed>> $rows   Submission rows.
	 * @param callable                         $lookup Takes a work item id,
	 *                                                 returns the item or null.
	 * @return array<int, array<string, mixed>>
	 */
	public static function submissions( array $rows, callable $lookup ): array {
		return array_values(
			array_map(
				static fn( array $row ): array => self::submission( $row, $lookup ),
				$rows
			)
		);
	}

	/**
	 * One submission, as the client who sent it may see it.
	 *
	 * An allowlist for the same reason as the item projection above, and one
	 * addition: the id of the work this became is resolved rather than
	 * published. An id tells a client nothing, and the act of resolving it is
	 * where the tenancy check belongs.
	 *
	 * @param array<string, mixed> $row    A submission row.
	 * @param callable             $lookup Takes a work item id, returns the item
	 *                                     or null.
	 * @return array<string, mixed>
	 */
	public static function submission( array $row, callable $lookup ): array {
		$state = (string) ( $row['intake_state'] ?? '' );

		return array(
			'id'              => (string) ( $row['id'] ?? '' ),
			'type'            => (string) ( $row['type'] ?? '' ),
			'title'           => (string) ( $row['title'] ?? '' ),
			'description'     => (string) ( $row['description'] ?? '' ),
			'desired_outcome' => (string) ( $row['desired_outcome'] ?? '' ),
			'evidence'        => (string) ( $row['evidence'] ?? '' ),
			'submitted_by'    => (string) ( $row['submitted_by'] ?? '' ),
			'intake_state'    => $state,
			'intake_label'    => Submissions::label( $state ),
			'response'        => (string) ( $row['response'] ?? '' ),
			'converted'       => self::converted( $row, $lookup ),
			'created_at'      => (int) ( $row['created_at'] ?? 0 ),
			'updated_at'      => (int) ( $row['updated_at'] ?? 0 ),
		);
	}

	/**
	 * The work a submission became, where it became any of this client's.
	 *
	 * Three ways this comes back empty, and they are deliberately the same
	 * answer: nothing was converted, the item has since gone, or the item
	 * belongs to another client site. The last is the one that matters. A
	 * conversion recorded against the wrong site is a studio mistake, and a
	 * studio mistake must not become a hole a client reads another client's work
	 * through — so the site the submission came from is checked here, every
	 * read, rather than trusted to have been checked when it was written.
	 *
	 * @param array<string, mixed> $row    A submission row.
	 * @param callable             $lookup Takes a work item id, returns the item
	 *                                     or null.
	 * @return array<string, string>
	 */
	private static function converted( array $row, callable $lookup ): array {
		$id = (string) ( $row['converted_item_id'] ?? '' );

		if ( '' === $id ) {
			return array();
		}

		$item = $lookup( $id );

		if ( ! is_array( $item ) ) {
			return array();
		}

		if ( (string) ( $item['client_site_id'] ?? '' ) !== (string) ( $row['client_site_id'] ?? '' ) ) {
			return array();
		}

		$stage = (string) ( $item['stage'] ?? '' );

		return array(
			'id'          => (string) ( $item['id'] ?? '' ),
			'title'       => (string) ( $item['title'] ?? '' ),
			'stage'       => $stage,
			'stage_label' => Stages::label( $stage ),
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
