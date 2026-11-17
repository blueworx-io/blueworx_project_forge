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
		$checked = Validate::item(
			Inbound::values_for(
				array(
					'title'  => str_repeat( 'x', 250 ),
					'body'   => "One\n\n- a",
					'labels' => array( array( 'name' => 'BUG' ) ),
				),
				'blueworx-io/alpha'
			),
			false
		);

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
}
