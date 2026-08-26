<?php
/**
 * The studio's cross-client view of what clients have asked for.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\Reach;
use Blueworx\Forge\Work\Queue;
use PHPUnit\Framework\TestCase;

/**
 * #131. One place to triage everything clients have asked for.
 *
 * The queue is the first studio screen that deliberately spans clients, so the
 * tests that matter most here are the ones about what it must NOT show. A board
 * that leaks is scoped to one site and leaks that site; a queue that leaks
 * leaks every client at once.
 *
 * The filter set is its own, not the board's (#123 covers work items, which
 * have stages and levels; a submission has neither). What the two share is the
 * rule: a filter is a closed list, and anything unrecognised is dropped rather
 * than applied.
 */
final class WorkQueueTest extends TestCase {

	/**
	 * A submission row, in the shape the table stores.
	 *
	 * @param array<string, mixed> $also Keys to add or override.
	 * @return array<string, mixed>
	 */
	private function row( array $also = array() ): array {
		return array_merge(
			array(
				'id'             => 'sub_1',
				'client_id'      => 'cli_acme',
				'client_site_id' => 'site_acme',
				'type'           => 'request',
				'title'          => 'A booking form that takes deposits',
				'description'    => 'People ring up to pay.',
				'intake_state'   => 'received',
				'created_at'     => 1000,
			),
			$also
		);
	}

	/**
	 * A reach built from one staff membership, with the grants given.
	 *
	 * @param string $client_id The client the membership is on.
	 * @param string $grants    The user's grants column.
	 * @return array<string, mixed>
	 */
	private function reach( string $client_id = 'cli_acme', string $grants = '' ): array {
		return Reach::for_memberships(
			array(
				array(
					'client_id' => $client_id,
					'role'      => 'staff',
					'status'    => 'active',
				),
			),
			$grants
		);
	}

	// ---- The filter set -------------------------------------------------

	/**
	 * An intake state that exists survives sanitising.
	 */
	public function test_keeps_a_real_intake_state(): void {
		$filters = Queue::sanitise( array( 'intake_state' => array( 'received', 'accepted' ) ) );

		$this->assertSame( array( 'received', 'accepted' ), $filters['intake_state'] );
	}

	/**
	 * A state nobody defined is dropped, and dropping it does not take the
	 * real ones with it.
	 */
	public function test_drops_an_invented_intake_state(): void {
		$filters = Queue::sanitise( array( 'intake_state' => array( 'received', 'urgent' ) ) );

		$this->assertSame( array( 'received' ), $filters['intake_state'] );
	}

	/**
	 * A type that exists survives; one that does not is dropped.
	 */
	public function test_keeps_only_real_types(): void {
		$filters = Queue::sanitise( array( 'type' => array( 'idea', 'complaint' ) ) );

		$this->assertSame( array( 'idea' ), $filters['type'] );
	}

	/**
	 * Client ids are matched exactly rather than checked against a list, the
	 * same rule the board's id filters follow: what a client id is allowed to
	 * be is the reach's question, not the filter's.
	 */
	public function test_keeps_client_ids_as_given(): void {
		$filters = Queue::sanitise( array( 'client_id' => array( 'cli_acme', 'cli_belltown' ) ) );

		$this->assertSame( array( 'cli_acme', 'cli_belltown' ), $filters['client_id'] );
	}

	/**
	 * Search is one string, not a set.
	 */
	public function test_keeps_search_as_a_single_value(): void {
		$filters = Queue::sanitise( array( 'search' => 'deposits' ) );

		$this->assertSame( 'deposits', $filters['search'] );
	}

	/**
	 * A filter this screen does not have is not silently honoured. Naming a
	 * work-item filter here should do nothing rather than half-work.
	 */
	public function test_drops_a_filter_that_is_not_the_queues(): void {
		$filters = Queue::sanitise(
			array(
				'stage'    => array( 'triage' ),
				'priority' => array( 'high' ),
			)
		);

		$this->assertSame( array(), $filters );
	}

	// ---- Applying them --------------------------------------------------

	/**
	 * No filters means everything the caller was given.
	 */
	public function test_no_filters_keeps_every_row(): void {
		$rows = array( $this->row(), $this->row( array( 'id' => 'sub_2' ) ) );

		$this->assertCount( 2, Queue::keep( $rows, array() ) );
	}

	/**
	 * A state filter keeps the rows in those states and no others.
	 */
	public function test_filters_by_intake_state(): void {
		$rows = array(
			$this->row(),
			$this->row(
				array(
					'id'           => 'sub_2',
					'intake_state' => 'declined',
				)
			),
		);

		$kept = Queue::keep( $rows, array( 'intake_state' => array( 'declined' ) ) );

		$this->assertSame( array( 'sub_2' ), array_column( $kept, 'id' ) );
	}

	/**
	 * Two filters together are an AND, not an OR.
	 */
	public function test_two_filters_both_have_to_match(): void {
		$rows = array(
			$this->row(
				array(
					'id'           => 'sub_1',
					'type'         => 'idea',
					'intake_state' => 'received',
				)
			),
			$this->row(
				array(
					'id'           => 'sub_2',
					'type'         => 'idea',
					'intake_state' => 'declined',
				)
			),
			$this->row(
				array(
					'id'           => 'sub_3',
					'type'         => 'request',
					'intake_state' => 'received',
				)
			),
		);

		$kept = Queue::keep(
			$rows,
			array(
				'type'         => array( 'idea' ),
				'intake_state' => array( 'received' ),
			)
		);

		$this->assertSame( array( 'sub_1' ), array_column( $kept, 'id' ) );
	}

	/**
	 * Search reads the client's words — the title and the description both,
	 * because half of what identifies a request is in the second one.
	 */
	public function test_search_matches_the_description_as_well_as_the_title(): void {
		$rows = array(
			$this->row(
				array(
					'id'          => 'sub_1',
					'title'       => 'Nothing relevant',
					'description' => 'People ring up to pay.',
				)
			),
			$this->row(
				array(
					'id'          => 'sub_2',
					'title'       => 'Nothing relevant',
					'description' => 'Something else.',
				)
			),
		);

		$kept = Queue::keep( $rows, array( 'search' => 'ring up' ) );

		$this->assertSame( array( 'sub_1' ), array_column( $kept, 'id' ) );
	}

	/**
	 * Searching is case-insensitive. Nobody types the capital letter a client
	 * happened to use.
	 */
	public function test_search_ignores_case(): void {
		$kept = Queue::keep( array( $this->row() ), array( 'search' => 'BOOKING' ) );

		$this->assertCount( 1, $kept );
	}

	// ---- What a row says -------------------------------------------------

	/**
	 * Each row carries the client's name, resolved from the client record.
	 *
	 * A queue row reading `cli_a3f9` is a row nobody can triage, which makes
	 * this the one field the cross-client screen cannot do without — and it is
	 * the field a single-client screen never needed, so nothing else was
	 * proving it.
	 */
	public function test_a_row_names_the_client_it_came_from(): void {
		$rows = Queue::rows(
			array( $this->row() ),
			static fn( string $id ): ?array => 'cli_acme' === $id
				? array(
					'id'           => 'cli_acme',
					'display_name' => 'Acme Joinery',
				)
				: null
		);

		$this->assertSame( 'Acme Joinery', $rows[0]['client_name'] );
	}

	/**
	 * A client that has since gone leaves the name empty rather than making
	 * the row disappear. The request was still sent, and still needs answering.
	 */
	public function test_a_missing_client_leaves_the_name_empty(): void {
		$rows = Queue::rows( array( $this->row() ), static fn(): ?array => null );

		$this->assertSame( '', $rows[0]['client_name'] );
		$this->assertCount( 1, $rows );
	}

	/**
	 * The intake state arrives with the words a person reads, so no screen has
	 * to keep its own copy of that vocabulary.
	 */
	public function test_a_row_carries_the_words_for_its_state(): void {
		$rows = Queue::rows(
			array( $this->row( array( 'intake_state' => 'in-review' ) ) ),
			static fn(): ?array => null
		);

		$this->assertSame( 'Being looked at', $rows[0]['intake_label'] );
	}

	// ---- What it must not show ------------------------------------------

	/**
	 * A reach covering one client does not see another's submissions.
	 */
	public function test_a_client_outside_reach_is_never_shown(): void {
		$rows = array(
			$this->row(),
			$this->row(
				array(
					'id'             => 'sub_2',
					'client_id'      => 'cli_belltown',
					'client_site_id' => 'site_belltown',
				)
			),
		);

		$visible = Queue::visible( $this->reach(), $rows );

		$this->assertSame( array( 'sub_1' ), array_column( $visible, 'id' ) );
	}

	/**
	 * Asking for a client you cannot reach returns nothing, rather than
	 * returning everything on the grounds that the filter matched nothing.
	 */
	public function test_filtering_to_an_unreachable_client_returns_nothing(): void {
		$rows = array(
			$this->row(
				array(
					'client_id'      => 'cli_belltown',
					'client_site_id' => 'site_belltown',
				)
			),
		);

		$visible = Queue::keep(
			Queue::visible( $this->reach(), $rows ),
			array( 'client_id' => array( 'cli_belltown' ) )
		);

		$this->assertSame( array(), $visible );
	}

	/**
	 * The cross-client grant is what widens the queue to every client, and it
	 * is the only thing that does.
	 */
	public function test_the_cross_client_grant_sees_every_client(): void {
		$rows = array(
			$this->row(),
			$this->row(
				array(
					'id'             => 'sub_2',
					'client_id'      => 'cli_belltown',
					'client_site_id' => 'site_belltown',
				)
			),
		);

		$this->assertCount( 2, Queue::visible( $this->reach( 'cli_acme', 'cross_client' ), $rows ) );
	}
}
