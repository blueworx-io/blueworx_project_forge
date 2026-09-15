<?php
/**
 * What a Slack message says.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Slack\Events;
use Blueworx\Forge\Slack\Message;
use Blueworx\Forge\Slack\People;
use PHPUnit\Framework\TestCase;

final class SlackMessageTest extends TestCase {

	private function item(): array {
		return array(
			'id'          => 'wrk_1',
			'title'       => 'Fix the <header> & footer',
			'planned_due' => '2026-09-15',
		);
	}

	public function test_an_assignment_names_the_task_the_client_and_the_date(): void {
		$message = Message::assigned( $this->item(), 'to do', false, 'Acme', 'https://x/forge/#item=wrk_1' );

		self::assertSame( 'Assigned to you to do: Fix the <header> & footer (Acme, due 2026-09-15)', $message['text'] );
		self::assertStringContainsString( '<https://x/forge/#item=wrk_1|Fix the &lt;header&gt; &amp; footer>', $message['blocks'][0]['text']['text'] );
		self::assertSame( 'Acme · due 2026-09-15', $message['blocks'][1]['elements'][0]['text'] );
	}

	public function test_new_work_reads_as_new(): void {
		$message = Message::assigned( $this->item(), 'to do', true, 'Acme', 'https://x' );

		self::assertStringStartsWith( 'New work for you to do:', $message['text'] );
	}

	public function test_ready_says_which_seat(): void {
		self::assertStringStartsWith( 'Ready for your review:', Message::ready( $this->item(), 'review', 'Acme', 'https://x' )['text'] );
		self::assertStringStartsWith( 'Ready for you to ship:', Message::ready( $this->item(), 'delivery', 'Acme', 'https://x' )['text'] );
	}

	public function test_a_comment_is_quoted_and_trimmed(): void {
		$message = Message::comment( $this->item(), 'Sam', "  Looks   good\nto me  ", 'Acme', 'https://x' );

		self::assertSame( 'Sam commented: Fix the <header> & footer (Acme) — Looks good to me', $message['text'] );
		self::assertSame( '> Looks good to me', $message['blocks'][1]['text']['text'] );
	}

	public function test_the_morning_message_lists_today_then_overdue(): void {
		$message = Message::morning(
			array(
				array(
					'title'  => 'A',
					'client' => 'Acme',
					'link'   => 'https://x/a',
				),
			),
			array(
				array(
					'title'       => 'B',
					'client'      => 'Beta',
					'link'        => 'https://x/b',
					'planned_due' => '2026-09-01',
				),
			),
			'Monday 14 September'
		);

		self::assertSame( '1 due today, 1 overdue', $message['text'] );
		self::assertStringContainsString( '*1 due today*', $message['blocks'][1]['text']['text'] );
		self::assertStringContainsString( '<https://x/a|A> · Acme', $message['blocks'][1]['text']['text'] );
		self::assertStringContainsString( '<https://x/b|B> · Beta · 2026-09-01', $message['blocks'][2]['text']['text'] );
	}

	public function test_a_quiet_morning_says_so(): void {
		self::assertSame( 'Nothing due today.', Message::morning( array(), array(), 'Sunday' )['text'] );
	}

	public function test_event_ids_are_stable_and_per_person(): void {
		$a = Events::id_for( 'assigned', 'wrk_1', 'usr_a', 'primary' );

		self::assertSame( $a, Events::id_for( 'assigned', 'wrk_1', 'usr_a', 'primary' ) );
		self::assertNotSame( $a, Events::id_for( 'assigned', 'wrk_1', 'usr_b', 'primary' ) );
		self::assertNotSame( $a, Events::id_for( 'assigned', 'wrk_1', 'usr_a', 'reviewer' ) );
		self::assertSame( '', Events::id_for( 'nonsense', 'wrk_1', 'usr_a', '1' ) );
		self::assertStringStartsWith( 'slk_', $a );
	}

	public function test_preferences_default_on_and_merge(): void {
		self::assertSame(
			array(
				'assigned' => true,
				'ready'    => true,
				'comment'  => true,
				'morning'  => true,
			),
			People::prefs_from( array() )
		);
		self::assertSame(
			array(
				'assigned' => true,
				'ready'    => false,
				'comment'  => true,
				'morning'  => false,
			),
			People::prefs_from( array( 'morning' => false ), array( 'ready' => false ) )
		);
	}

	public function test_only_slack_webhooks_are_accepted(): void {
		self::assertTrue( People::acceptable( 'https://hooks.slack.com/services/T0/B0/xyz' ) );
		self::assertFalse( People::acceptable( 'https://example.com/hook' ) );
		self::assertFalse( People::acceptable( 'http://hooks.slack.com/services/x' ) );
	}
}
