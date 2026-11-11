<?php
/**
 * Tests for what a client sees when they open one item.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\ClientView;
use PHPUnit\Framework\TestCase;

/**
 * #671. An opened item says what it is: its description, its checklist and
 * who it waits on, on top of what a card already shows. Still an allowlist.
 */
final class ClientItemDetailTest extends TestCase {

	/**
	 * A work item row, as Items::get() returns it.
	 *
	 * @param array<string, mixed> $also Columns to add or override.
	 * @return array<string, mixed>
	 */
	private function row( array $also = array() ): array {
		return array_merge(
			array(
				'id'               => 'wrk_1',
				'title'            => 'Rebuild the booking form',
				'stage'            => 'in-development',
				'prior_stage'      => '',
				'problem'          => '<p>People cannot <strong>book</strong>.</p>',
				'checklist'        => array(
					array(
						'text' => 'Agree the fields',
						'done' => true,
					),
					array(
						'text' => 'Build it',
						'done' => false,
					),
				),
				'primary_user_id'  => 'usr_ana',
				'designer_id'      => '',
				'reviewer_id'      => 'usr_sam',
				'deliverer_id'     => 'usr_ana',
				'terminal_outcome' => '',
				'recurring_id'     => '',
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
					'display_name' => 'Ana Fielding',
					'email'        => 'ana@studio.example',
				),
				'usr_sam' => array(
					'display_name' => 'Sam Okoro',
					'email'        => 'sam@studio.example',
				),
			);

			return $people[ $id ] ?? null;
		};
	}

	public function test_the_detail_is_the_card_plus_three_named_things(): void {
		$card   = ClientView::item( $this->row(), $this->lookup() );
		$detail = ClientView::detail( $this->row(), $this->lookup() );

		$this->assertSame(
			array_merge( array_keys( $card ), array( 'description', 'checklist', 'waiting_on' ) ),
			array_keys( $detail )
		);
	}

	public function test_internal_fields_stay_out_of_the_detail(): void {
		$detail = ClientView::detail(
			$this->row(
				array(
					'hours_primary'  => '12.00',
					'internal_notes' => 'Do not show',
					'requirements'   => 'Ours',
					'priority'       => 'low',
				)
			),
			$this->lookup()
		);

		foreach ( array( 'hours_primary', 'internal_notes', 'requirements', 'priority', 'problem' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $detail );
		}
	}

	public function test_the_description_is_the_item_description(): void {
		$detail = ClientView::detail( $this->row(), $this->lookup() );

		$this->assertSame( '<p>People cannot <strong>book</strong>.</p>', $detail['description'] );
	}

	public function test_the_checklist_is_text_and_done_only(): void {
		$detail = ClientView::detail(
			$this->row(
				array(
					'checklist' => array(
						array(
							'text'  => 'Agree the fields',
							'done'  => 1,
							'by'    => 'usr_ana',
							'hours' => 2,
						),
						'not a row',
					),
				)
			),
			$this->lookup()
		);

		$this->assertSame(
			array(
				array(
					'text' => 'Agree the fields',
					'done' => true,
				),
			),
			$detail['checklist']
		);
	}

	/**
	 * Who it waits on follows the stage, the same rule the studio's panel uses.
	 *
	 * @dataProvider turns
	 *
	 * @param array<string, mixed> $also     Columns to override.
	 * @param string               $expected The name, or '' for nobody.
	 */
	public function test_who_it_waits_on( array $also, string $expected ): void {
		$detail = ClientView::detail( $this->row( $also ), $this->lookup() );

		$this->assertSame( $expected, $detail['waiting_on'] );
	}

	/**
	 * Stages, and whose turn each is.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function turns(): array {
		return array(
			'the owner, before review'       => array( array(), 'Ana Fielding' ),
			'the reviewer, in review'        => array( array( 'stage' => 'in-review' ), 'Sam Okoro' ),
			'the deliverer, once completed'  => array( array( 'stage' => 'completed' ), 'Ana Fielding' ),
			'the designer, at design'        => array(
				array(
					'stage'       => 'design-process',
					'designer_id' => 'usr_sam',
				),
				'Sam Okoro',
			),
			'blocked: whose stage it was'    => array(
				array(
					'stage'       => 'blocked',
					'prior_stage' => 'in-review',
				),
				'Sam Okoro',
			),
			'nobody once released'           => array( array( 'stage' => 'released' ), '' ),
			'nobody once ended'              => array( array( 'terminal_outcome' => 'cancelled' ), '' ),
			'nobody while the client reviews' => array(
				array(
					'stage'       => 'in-review',
					'reviewer_id' => 'client',
				),
				'',
			),
			'nobody when the seat is empty'  => array( array( 'primary_user_id' => '' ), '' ),
			'nobody when the person is gone' => array( array( 'primary_user_id' => 'usr_gone' ), '' ),
			'nobody on a recurring task'     => array( array( 'recurring_id' => 'rec_1' ), '' ),
		);
	}
}
