<?php
/**
 * What a new GitHub issue becomes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\GitHub\Inbound;
use Blueworx\Forge\Work\Validate;
use PHPUnit\Framework\TestCase;

/**
 * #680. A new issue comes in as a Feature-level item, a Bug when GitHub
 * labelled it one.
 */
final class GitHubInboundTest extends TestCase {

	public function test_a_bug_label_makes_a_bug(): void {
		$bug = Inbound::values_for(
			array(
				'title'  => ' Fix the footer ',
				'body'   => 'It overlaps.',
				'labels' => array( array( 'name' => 'Bug' ) ),
			),
			'blueworx-io/alpha'
		);

		self::assertSame( 'bug', $bug['work_type'] );
		self::assertSame( 'feature', $bug['level'] );
		self::assertSame( 'Fix the footer', $bug['title'] );
		self::assertSame( '<p>It overlaps.</p>', $bug['problem'] );
		self::assertSame( 'blueworx-io/alpha', $bug['github_repo'] );

		$plain = Inbound::values_for(
			array(
				'title' => 'Add a map',
				'body'  => null,
			),
			'blueworx-io/alpha'
		);

		self::assertSame( 'feature', $plain['work_type'] );
		self::assertSame( 'feature', $plain['level'] );
		self::assertSame( '', $plain['problem'] );

		$labelled = Inbound::values_for(
			array(
				'title'  => 'Tidy up',
				'labels' => array( array( 'name' => 'enhancement' ), array( 'name' => 'not a bug' ) ),
			),
			'blueworx-io/alpha'
		);

		self::assertSame( 'feature', $labelled['work_type'] );
	}

	public function test_an_issue_meets_the_rules_for_new_work(): void {
		// The repo is set after validation, as opened() does.
		$sent = Inbound::values_for(
			array(
				'title'  => str_repeat( 'x', 250 ),
				'body'   => "One\n\n- a",
				'labels' => array( array( 'name' => 'BUG' ) ),
			),
			'blueworx-io/alpha'
		);
		unset( $sent['github_repo'] );

		$checked = Validate::item( $sent, false );

		self::assertSame( array(), $checked['errors'] );
		self::assertSame( '', $checked['values']['parent_id'] );
		self::assertSame( 'bug', $checked['values']['work_type'] );
		self::assertSame( '<p>One</p><ul><li>a</li></ul>', $checked['values']['problem'] );
	}

	public function test_only_new_issues_are_taken(): void {
		self::assertSame( 'pong', Inbound::handle( 'ping', array( 'zen' => 'x' ) ) );
		self::assertSame( 'ignored', Inbound::handle( 'issues', array( 'action' => 'edited' ) ) );
		self::assertSame( 'ignored', Inbound::handle( 'star', array( 'action' => 'created' ) ) );
	}

	public function test_a_title_edit_counts_only_from_the_title_forge_last_saw(): void {
		$link = array(
			'title_seen' => 'Newest',
			'body_hash'  => '',
		);

		// GitHub's message about an older rename, arriving late.
		self::assertFalse( Inbound::starts_from_seen( 'title', array( 'title' => array( 'from' => 'Older' ) ), $link ) );
		self::assertTrue( Inbound::starts_from_seen( 'title', array( 'title' => array( 'from' => 'Newest' ) ), $link ) );
		self::assertFalse( Inbound::starts_from_seen( 'title', array(), $link ) );
	}

	public function test_a_long_title_is_compared_as_forge_keeps_it(): void {
		$long = str_repeat( 'y', 250 );

		self::assertTrue(
			Inbound::starts_from_seen(
				'title',
				array( 'title' => array( 'from' => $long ) ),
				array(
					'title_seen' => str_repeat( 'y', 191 ),
					'body_hash'  => '',
				)
			)
		);
	}

	public function test_a_body_edit_counts_only_from_the_body_forge_last_saw(): void {
		$link = array(
			'title_seen' => '',
			'body_hash'  => hash( 'sha256', 'Current words.' ),
		);

		self::assertTrue( Inbound::starts_from_seen( 'body', array( 'body' => array( 'from' => 'Current words.' ) ), $link ) );
		self::assertFalse( Inbound::starts_from_seen( 'body', array( 'body' => array( 'from' => 'Old words.' ) ), $link ) );

		// An issue that had no description before.
		self::assertTrue(
			Inbound::starts_from_seen(
				'body',
				array( 'body' => array( 'from' => null ) ),
				array(
					'title_seen' => '',
					'body_hash'  => hash( 'sha256', '' ),
				)
			)
		);
	}

	/**
	 * A task at a stage, as on_close() reads it.
	 *
	 * @param string $stage   The stage.
	 * @param string $outcome Its terminal outcome, if any.
	 * @return array<string, mixed>
	 */
	private static function at( string $stage, string $outcome = '' ): array {
		return array(
			'stage'            => $stage,
			'terminal_outcome' => $outcome,
		);
	}

	public function test_closing_in_development_moves_to_review(): void {
		self::assertSame( 'move', Inbound::on_close( self::at( 'in-development' ), 'completed' ) );
	}

	public function test_closing_earlier_leaves_a_note(): void {
		self::assertSame( 'note', Inbound::on_close( self::at( 'up-next' ), 'completed' ) );
		self::assertSame( 'note', Inbound::on_close( self::at( 'future-idea' ), '' ) );
		self::assertSame( 'note', Inbound::on_close( self::at( 'blocked' ), 'completed' ) );
	}

	public function test_not_planned_only_ever_notes(): void {
		self::assertSame( 'note', Inbound::on_close( self::at( 'in-development' ), 'not_planned' ) );
	}

	public function test_closing_after_forge_closed_does_nothing(): void {
		self::assertSame( 'nothing', Inbound::on_close( self::at( 'released' ), 'completed' ) );
		self::assertSame( 'nothing', Inbound::on_close( self::at( 'up-next', 'cancelled' ), 'not_planned' ) );
	}

	public function test_closing_in_review_does_nothing(): void {
		self::assertSame( 'nothing', Inbound::on_close( self::at( 'in-review' ), 'completed' ) );
		self::assertSame( 'nothing', Inbound::on_close( self::at( 'completed' ), 'completed' ) );
	}
}
