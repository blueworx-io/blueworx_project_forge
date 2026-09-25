<?php
/**
 * What a reminder may say, and what its copies are made of.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Recurring\Reminders;
use PHPUnit\Framework\TestCase;

final class RemindersTest extends TestCase {

	private function good(): array {
		return array(
			'title'     => 'Renew the domain',
			'assignees' => array( 'usr_abc123', 'usr_def456' ),
			'starts_on' => '2026-10-01',
			'ends_on'   => '2026-10-05',
		);
	}

	public function test_a_good_reminder_passes(): void {
		$checked = Reminders::validate( $this->good(), false );

		self::assertSame( array(), $checked['errors'] );
		self::assertSame( array( 'usr_abc123', 'usr_def456' ), $checked['values']['assignees'] );
		self::assertSame( '2026-10-05', $checked['values']['ends_on'] );
	}

	public function test_title_people_and_start_are_required_notes_and_end_are_not(): void {
		$checked = Reminders::validate( array(), false );

		self::assertSame( 'A reminder needs a title.', $checked['errors']['title'] );
		self::assertSame( 'Choose at least one person.', $checked['errors']['assignees'] );
		self::assertArrayHasKey( 'starts_on', $checked['errors'] );
		self::assertArrayNotHasKey( 'ends_on', $checked['errors'] );
		self::assertArrayNotHasKey( 'description', $checked['errors'] );
	}

	public function test_it_cannot_end_before_it_starts(): void {
		$checked = Reminders::validate( array_merge( $this->good(), array( 'ends_on' => '2026-09-30' ) ), false );

		self::assertSame( 'The end date is before the start date.', $checked['errors']['ends_on'] );
	}

	public function test_a_copy_is_one_persons_free_task_over_the_period(): void {
		$source = array(
			'id'          => 'rem_1',
			'title'       => 'Renew the domain',
			'description' => '',
			'starts_on'   => '2026-10-01',
			'ends_on'     => '2026-10-05',
		);

		$values = Reminders::values( $source, 'usr_abc123' );

		self::assertSame( 'Renew the domain', $values['title'] );
		self::assertSame( 'Renew the domain', $values['problem'] );
		self::assertSame( '2026-10-01', $values['planned_start'] );
		self::assertSame( '2026-10-05', $values['planned_due'] );
		self::assertSame( 'free-general', $values['commercial_class'] );
		self::assertSame( array( 'usr_abc123' ), $values['assignees'] );
		self::assertSame( 'rem_1', $values['recurring_id'] );
	}

	public function test_a_one_day_reminder_is_due_the_day_it_starts(): void {
		$values = Reminders::values(
			array(
				'id'          => 'rem_1',
				'title'       => 'Call back',
				'description' => '<p>About the invoice.</p>',
				'starts_on'   => '2026-10-01',
				'ends_on'     => '',
			),
			'usr_abc123'
		);

		self::assertSame( '2026-10-01', $values['planned_due'] );
		self::assertSame( '<p>About the invoice.</p>', $values['problem'] );
	}

	public function test_reminder_copies_are_told_apart_by_their_source_id(): void {
		self::assertTrue( Reminders::is_reminder( 'rem_0001abc' ) );
		self::assertFalse( Reminders::is_reminder( 'rec_0001abc' ) );
		self::assertFalse( Reminders::is_reminder( '' ) );
	}
}
