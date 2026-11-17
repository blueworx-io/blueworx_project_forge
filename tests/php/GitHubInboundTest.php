<?php
/**
 * What a new GitHub issue becomes.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\GitHub\Inbound;
use Blueworx\Forge\GitHub\Links;
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

	/**
	 * GitHub may deliver the message about an older change after a newer
	 * one, or lose one altogether. Order comes from the issue's own time.
	 */
	public function test_a_message_older_than_the_last_one_seen_changes_nothing(): void {
		$link = array( 'updated_seen' => strtotime( '2026-11-17T10:00:05Z' ) );

		self::assertTrue( Inbound::older( array( 'updated_at' => '2026-11-17T10:00:04Z' ), $link ) );
		self::assertFalse( Inbound::older( array( 'updated_at' => '2026-11-17T10:00:05Z' ), $link ) );

		// A delivery went missing in between: the next one still counts.
		self::assertFalse( Inbound::older( array( 'updated_at' => '2026-11-17T10:09:00Z' ), $link ) );

		// A link made before Forge kept the time takes anything.
		self::assertFalse( Inbound::older( array( 'updated_at' => '2020-01-01T00:00:00Z' ), array( 'updated_seen' => 0 ) ) );
	}

	/**
	 * GitHub's answer to Forge's own change carries the issue's time. It
	 * only counts as seen when the answer shows nothing Forge hasn't: a
	 * change someone else made just before must still be taken in when its
	 * message arrives.
	 */
	public function test_an_answer_counts_as_seen_only_when_it_shows_nothing_new(): void {
		$link = array(
			'title_seen' => 'Ours',
			'body_hash'  => hash( 'sha256', 'Our words.' ),
			'state_seen' => '',
		);
		$same = array(
			'title' => 'Ours',
			'body'  => 'Our words.',
			'state' => 'open',
		);

		self::assertTrue( Links::matches( $link, $same ) );
		self::assertFalse( Links::matches( $link, array_merge( $same, array( 'title' => 'Theirs' ) ) ) );
		self::assertFalse( Links::matches( $link, array_merge( $same, array( 'body' => 'Their words.' ) ) ) );
		self::assertFalse( Links::matches( $link, array_merge( $same, array( 'state' => 'closed' ) ) ) );
		self::assertTrue( Links::matches( array_merge( $link, array( 'state_seen' => 'closed' ) ), array_merge( $same, array( 'state' => 'closed' ) ) ) );

		// A title is compared as Forge keeps it.
		self::assertTrue( Links::matches( array_merge( $link, array( 'title_seen' => str_repeat( 'y', 191 ) ) ), array_merge( $same, array( 'title' => str_repeat( 'y', 250 ) ) ) ) );
	}

	public function test_github_times_are_read_as_seconds(): void {
		self::assertSame( 1763373605, Links::time_of( array( 'updated_at' => '2025-11-17T10:00:05Z' ) ) );
		self::assertSame( 0, Links::time_of( array() ) );
		self::assertSame( 0, Links::time_of( array( 'updated_at' => 'not a time' ) ) );
	}

	/**
	 * A message about an issue, from someone.
	 *
	 * @param string $sender      Who did it.
	 * @param string $author      Who opened the issue.
	 * @param string $association What GitHub says the author is to the repo.
	 * @return array<string, mixed>
	 */
	private static function from( string $sender, string $author, string $association ): array {
		return array(
			'issue'  => array(
				'user'               => array( 'login' => $author ),
				'author_association' => $association,
			),
			'sender' => array( 'login' => $sender ),
		);
	}

	/**
	 * Anyone can open an issue on a public repo, and its author can always
	 * edit and close it, so only people who belong to the repo change tasks.
	 */
	public function test_only_people_on_the_repo_change_tasks_from_github(): void {
		self::assertTrue( Inbound::trusted( self::from( 'Blueworx-IO', 'stranger', 'NONE' ), 'blueworx-io' ) );
		self::assertTrue( Inbound::trusted( self::from( 'octo', 'octo', 'OWNER' ), 'blueworx-io' ) );
		self::assertTrue( Inbound::trusted( self::from( 'octo', 'octo', 'MEMBER' ), 'blueworx-io' ) );
		self::assertTrue( Inbound::trusted( self::from( 'octo', 'octo', 'COLLABORATOR' ), 'blueworx-io' ) );

		// Only someone on the repo can change another person's issue.
		self::assertTrue( Inbound::trusted( self::from( 'octo', 'stranger', 'NONE' ), 'blueworx-io' ) );

		self::assertFalse( Inbound::trusted( self::from( 'stranger', 'stranger', 'NONE' ), 'blueworx-io' ) );
		self::assertFalse( Inbound::trusted( self::from( 'stranger', 'stranger', 'CONTRIBUTOR' ), 'blueworx-io' ) );
		self::assertFalse( Inbound::trusted( self::from( 'stranger', 'Stranger', 'FIRST_TIME_CONTRIBUTOR' ), 'blueworx-io' ) );
		self::assertFalse( Inbound::trusted( self::from( '', 'stranger', 'NONE' ), 'blueworx-io' ) );
	}

	/**
	 * A reopen in GitHub always gets a note, unless it is Forge's own.
	 */
	public function test_a_reopen_gets_a_note_unless_forge_did_it(): void {
		$open     = self::at( 'in-review' );
		$released = self::at( 'released' );

		self::assertSame( 'note', Inbound::on_reopen( $released, array( 'state_seen' => '' ), false ) );
		self::assertSame( 'note', Inbound::on_reopen( $open, array( 'state_seen' => 'closed' ), false ) );

		// Forge is reopening it itself, and GitHub's message beat the answer.
		self::assertSame( 'nothing', Inbound::on_reopen( $open, array( 'state_seen' => 'closed' ), true ) );
		self::assertSame( 'nothing', Inbound::on_reopen( $open, array( 'state_seen' => '' ), false ) );
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
