<?php
/**
 * What Forge tells an issue about its task's stage and ending.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\GitHub\Sync;
use Blueworx\Forge\Work\Stages;
use PHPUnit\Framework\TestCase;

/**
 * #682. The label for a stage, and whether an item's issue is open or closed.
 */
final class GitHubSyncTest extends TestCase {

	public function test_the_label_names_the_stage(): void {
		$label = Sync::label_for( 'up-next' );

		$this->assertStringStartsWith( Sync::LABEL_PREFIX, $label );
		$this->assertSame( 'forge: ' . Stages::label( 'up-next' ), $label );
	}

	public function test_released_closes_as_completed(): void {
		$this->assertSame(
			array(
				'state'  => 'closed',
				'reason' => 'completed',
			),
			Sync::state_for(
				array(
					'stage'            => 'released',
					'terminal_outcome' => '',
				)
			)
		);
	}

	public function test_a_closing_outcome_closes_as_not_planned(): void {
		$this->assertSame(
			array(
				'state'  => 'closed',
				'reason' => 'not_planned',
			),
			Sync::state_for(
				array(
					'stage'            => 'in-development',
					'terminal_outcome' => 'cancelled',
				)
			)
		);
	}

	public function test_anything_else_is_open(): void {
		$this->assertSame(
			array(
				'state'  => 'open',
				'reason' => '',
			),
			Sync::state_for(
				array(
					'stage'            => 'in-development',
					'terminal_outcome' => '',
				)
			)
		);
	}

	/**
	 * A link as read, for the tests below.
	 *
	 * @param string $origin     Where the issue started.
	 * @param string $title_seen The title Forge last saw on it.
	 * @return array<string, mixed>
	 */
	private static function link( string $origin, string $title_seen = 'Old' ): array {
		return array(
			'item_id'    => 'itm_1',
			'origin'     => $origin,
			'title_seen' => $title_seen,
			'body_hash'  => '',
		);
	}

	public function test_a_title_forge_already_sent_is_not_sent_again(): void {
		$this->assertSame(
			array(),
			Sync::wanted(
				array(
					'id'    => 'itm_1',
					'title' => 'Old',
				),
				array(
					'id'    => 'itm_1',
					'title' => 'New',
				),
				self::link( 'forge', 'New' )
			)
		);
	}

	public function test_a_new_title_is_sent(): void {
		$this->assertSame(
			array( 'set_title' => array( 'title' => 'New' ) ),
			Sync::wanted(
				array(
					'id'    => 'itm_1',
					'title' => 'Old',
				),
				array(
					'id'    => 'itm_1',
					'title' => 'New',
				),
				self::link( 'forge' )
			)
		);
	}

	public function test_nothing_is_sent_for_an_item_without_an_issue(): void {
		$this->assertSame(
			array(),
			Sync::wanted(
				array(
					'id'      => 'itm_1',
					'title'   => 'Old',
					'problem' => 'a',
				),
				array(
					'id'      => 'itm_1',
					'title'   => 'New',
					'problem' => 'b',
				),
				null
			)
		);
	}

	public function test_github_born_issues_never_get_their_body_written(): void {
		$this->assertArrayNotHasKey(
			'set_body',
			Sync::wanted(
				array(
					'id'      => 'itm_1',
					'title'   => 'Same',
					'problem' => '<p>Before</p>',
				),
				array(
					'id'      => 'itm_1',
					'title'   => 'Same',
					'problem' => '<p>After</p>',
				),
				self::link( 'github', 'Same' )
			)
		);
	}

	public function test_forge_born_issues_get_their_body_rewritten(): void {
		$this->assertArrayHasKey(
			'set_body',
			Sync::wanted(
				array(
					'id'    => 'itm_1',
					'title' => 'Same',
					'scope' => '<p>Before</p>',
				),
				array(
					'id'    => 'itm_1',
					'title' => 'Same',
					'scope' => '<p>After</p>',
				),
				self::link( 'forge', 'Same' )
			)
		);
	}

	/**
	 * A save while the job for the last one is running: the runner only puts
	 * a job back in the queue when what it asks for has changed, so each
	 * save has to ask for something different.
	 */
	public function test_each_save_asks_for_something_different(): void {
		$link   = self::link( 'forge', 'First' );
		$first  = Sync::wanted(
			array(
				'title'          => 'First',
				'scope'          => 'a',
				'record_version' => 1,
			),
			array(
				'title'          => 'Second',
				'scope'          => 'b',
				'record_version' => 2,
			),
			$link
		);
		$second = Sync::wanted(
			array(
				'title'          => 'Second',
				'scope'          => 'b',
				'record_version' => 2,
			),
			array(
				'title'          => 'Third',
				'scope'          => 'c',
				'record_version' => 3,
			),
			$link
		);

		$this->assertNotEquals( $first['set_title'], $second['set_title'] );
		$this->assertNotEquals( $first['set_body'], $second['set_body'] );
	}

	/**
	 * Work sent back from review after GitHub closed its issue: open on both
	 * sides of the move, but the issue is closed, so it has to reopen.
	 */
	public function test_a_task_open_on_both_sides_still_reopens_an_issue_github_closed(): void {
		$review = array(
			'stage'            => 'in-review',
			'terminal_outcome' => '',
		);
		$back   = array(
			'stage'            => 'in-development',
			'terminal_outcome' => '',
		);

		$this->assertSame(
			array(
				'state'  => 'open',
				'reason' => '',
			),
			Sync::state_wanted( $review, $back, array( 'state_seen' => 'closed' ) )
		);
		$this->assertNull( Sync::state_wanted( $review, $back, array( 'state_seen' => 'open' ) ) );
		$this->assertNull( Sync::state_wanted( $review, $back, array( 'state_seen' => '' ) ) );

		// A move that changes it is sent whatever was seen.
		$released = Sync::state_wanted(
			$review,
			array(
				'stage'            => 'released',
				'terminal_outcome' => '',
			),
			array( 'state_seen' => '' )
		);
		$this->assertSame( 'closed', $released['state'] ?? '' );
	}

	public function test_the_body_fields_are_the_issue_body_sections(): void {
		$this->assertSame( array( 'problem', 'scope', 'requirements', 'acceptance_criteria' ), Sync::BODY_FIELDS );
	}
}
