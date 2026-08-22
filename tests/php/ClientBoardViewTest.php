<?php
/**
 * Tests for what a client site is allowed to see of a work item.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\ClientView;
use PHPUnit\Framework\TestCase;

/**
 * #128. The client's boards render a projection of a work item, not the row.
 *
 * The projection is an allowlist rather than a list of things to strip, and
 * these tests are what makes that true rather than intended: a column added to
 * the table later must not appear on a client's screen because nobody
 * remembered to remove it.
 */
final class ClientBoardViewTest extends TestCase {

	/**
	 * The keys a client may see, and the whole of them.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED = array(
		'id',
		'parent_id',
		'title',
		'stage',
		'stage_label',
		'level',
		'work_type',
		'planned_start',
		'planned_due',
		'review_target',
		'release_target',
		'people',
	);

	/**
	 * A work item row, in the shape Items::for_site() returns.
	 *
	 * @param array<string, mixed> $also Columns to add or override.
	 * @return array<string, mixed>
	 */
	private function row( array $also = array() ): array {
		return array_merge(
			array(
				'id'              => 'wrk_1',
				'client_site_id'  => 'cst_1',
				'client_id'       => 'cli_1',
				'parent_id'       => '',
				'level'           => 'item',
				'work_type'       => 'feature',
				'title'           => 'Rebuild the booking form',
				'stage'           => 'in-development',
				'planned_start'   => '2026-09-01',
				'planned_due'     => '2026-09-14',
				'review_target'   => '',
				'release_target'  => '',
				'primary_user_id' => 'usr_ana',
				'reviewer_id'     => '',
				'deliverer_id'    => '',
			),
			$also
		);
	}

	/**
	 * Looks a person up the way Users::get() does.
	 *
	 * @return callable
	 */
	private function lookup(): callable {
		return static function ( string $id ): ?array {
			$people = array(
				'usr_ana' => array(
					'id'           => 'usr_ana',
					'display_name' => 'Ana Fielding',
					'email'        => 'ana@studio.example',
					'grants'       => 'cross_client',
					'wp_user_id'   => 7,
				),
			);

			return $people[ $id ] ?? null;
		};
	}

	// -----------------------------------------------------------------------
	// The allowlist.
	// -----------------------------------------------------------------------

	/**
	 * The projection emits the named keys and no others.
	 */
	public function test_the_projection_emits_exactly_the_allowed_keys(): void {
		$item = ClientView::item( $this->row(), $this->lookup() );

		$this->assertSame( self::ALLOWED, array_keys( $item ) );
	}

	/**
	 * The point of an allowlist: a row carrying things a client must never see
	 * produces the same keys, because those keys were never named.
	 */
	public function test_internal_columns_do_not_survive_the_projection(): void {
		$item = ClientView::item(
			$this->row(
				array(
					'commercial_class'       => 'free-bug',
					'priority'               => 'low',
					'hours_primary'          => '12.00',
					'hours_review'           => '3.00',
					'override_reason'        => 'Client escalation, approved by Luke',
					'reviewer_substitute_id' => 'usr_sam',
					'record_version'         => 4,
				)
			),
			$this->lookup()
		);

		$this->assertSame( self::ALLOWED, array_keys( $item ) );
	}

	/**
	 * A column nobody has thought of yet is refused by construction. This is
	 * the regression test for the whole design: it fails the moment somebody
	 * rewrites the projection as "the row, minus these".
	 */
	public function test_a_column_added_later_is_absent_by_default(): void {
		$item = ClientView::item( $this->row( array( 'internal_risk_note' => 'Client is unhappy' ) ), $this->lookup() );

		$this->assertArrayNotHasKey( 'internal_risk_note', $item );
	}


	/**
	 * The stage arrives named as well as slugged. A client screen that has to
	 * turn 'up-next' into words is a screen holding its own copy of the state
	 * machine, in a second language, that nobody updates.
	 */
	public function test_the_stage_carries_the_name_the_studio_gives_it(): void {
		$item = ClientView::item( $this->row(), $this->lookup() );

		$this->assertSame( 'In development', $item['stage_label'] );
	}
	// -----------------------------------------------------------------------
	// The people on a card.
	// -----------------------------------------------------------------------

	/**
	 * A seat that is filled names the person, and names nothing else about
	 * them: not their address, not their grants, not their WordPress account.
	 */
	public function test_a_filled_seat_gives_a_display_name_and_nothing_else(): void {
		$item = ClientView::item( $this->row(), $this->lookup() );

		$this->assertSame( array( 'display_name' => 'Ana Fielding' ), $item['people']['primary'] );
	}

	/**
	 * An empty seat is empty rather than a person-shaped blank, so a screen can
	 * tell "nobody yet" from "somebody whose name did not load".
	 */
	public function test_an_unfilled_seat_is_empty(): void {
		$item = ClientView::item( $this->row(), $this->lookup() );

		$this->assertSame( array(), $item['people']['reviewer'] );
		$this->assertSame( array(), $item['people']['deliverer'] );
	}

	/**
	 * A seat naming somebody who no longer exists is empty too, not a fatal.
	 */
	public function test_a_seat_naming_a_missing_person_is_empty(): void {
		$item = ClientView::item( $this->row( array( 'primary_user_id' => 'usr_gone' ) ), $this->lookup() );

		$this->assertSame( array(), $item['people']['primary'] );
	}

	/**
	 * The substitute seats are not shown. Who stood in for whom is AUTH-4's
	 * record and the studio's business.
	 */
	public function test_substitutes_are_not_among_the_people(): void {
		$item = ClientView::item(
			$this->row( array( 'reviewer_substitute_id' => 'usr_ana' ) ),
			$this->lookup()
		);

		$this->assertSame( array( 'primary', 'reviewer', 'deliverer' ), array_keys( $item['people'] ) );
	}

	// -----------------------------------------------------------------------
	// Lists.
	// -----------------------------------------------------------------------

	/**
	 * A list of rows projects to a list of items, in the order given.
	 */
	public function test_a_list_of_rows_projects_in_order(): void {
		$items = ClientView::items(
			array(
				$this->row( array( 'id' => 'wrk_1' ) ),
				$this->row( array( 'id' => 'wrk_2' ) ),
			),
			$this->lookup()
		);

		$this->assertSame( array( 'wrk_1', 'wrk_2' ), array_column( $items, 'id' ) );
	}
}
