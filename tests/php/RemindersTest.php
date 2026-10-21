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
		self::assertSame( '<p>Renew the domain</p>', $values['problem'] );
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

	public function test_a_title_used_as_the_problem_is_escaped_not_trusted_as_html(): void {
		$values = Reminders::values(
			array(
				'id'          => 'rem_1',
				'title'       => '<img src=x onerror=alert(1)>',
				'description' => '',
				'starts_on'   => '2026-10-01',
				'ends_on'     => '',
			),
			'usr_abc123'
		);

		self::assertStringNotContainsString( '<img', $values['problem'] );
		self::assertStringContainsString( '&lt;img', $values['problem'] );
	}

	public function test_reminder_copies_are_told_apart_by_their_source_id(): void {
		self::assertTrue( Reminders::is_reminder( 'rem_0001abc' ) );
		self::assertFalse( Reminders::is_reminder( 'rec_0001abc' ) );
		self::assertFalse( Reminders::is_reminder( '' ) );
	}

	public function test_the_type_defaults_to_general_and_must_be_one_of_seven(): void {
		self::assertSame( 'general', Reminders::validate( $this->good(), false )['values']['category'] );
		self::assertSame( 'deadline', Reminders::validate( array_merge( $this->good(), array( 'category' => 'deadline' ) ), false )['values']['category'] );
		self::assertSame( 'sales', Reminders::validate( array_merge( $this->good(), array( 'category' => 'sales' ) ), false )['values']['category'] );
		self::assertSame( 'finance', Reminders::validate( array_merge( $this->good(), array( 'category' => 'finance' ) ), false )['values']['category'] );
		self::assertSame( 'Pick a type.', Reminders::validate( array_merge( $this->good(), array( 'category' => 'party' ) ), false )['errors']['category'] );
		self::assertArrayNotHasKey( 'category', Reminders::validate( array( 'title' => 'Only the title' ), true )['values'] );
		self::assertSame( array( 'general', 'campaign', 'marketing', 'sales', 'finance', 'deadline', 'other' ), array_keys( Reminders::CATEGORIES ) );
	}

	public function test_a_day_the_calendar_does_not_have_is_not_a_date(): void {
		self::assertSame( 'That is not a date.', Reminders::validate( array_merge( $this->good(), array( 'starts_on' => '2026-02-31' ) ), false )['errors']['starts_on'] );
		self::assertSame( 'That is not a date.', Reminders::validate( array_merge( $this->good(), array( 'ends_on' => '2026-13-01' ) ), false )['errors']['ends_on'] );
		self::assertSame( 'That is not a date.', Reminders::validate( array( 'starts_on' => '2026-00-10' ), true )['errors']['starts_on'] );
		self::assertArrayNotHasKey( 'starts_on', Reminders::validate( array( 'starts_on' => '2028-02-29' ), true )['errors'] );
	}

	public function test_a_time_is_optional_and_counts_as_nothing_when_left_out(): void {
		self::assertSame( '0', Reminders::validate( $this->good(), false )['values']['hours_each'] );
		self::assertSame( '0.5', Reminders::validate( array_merge( $this->good(), array( 'hours_each' => '0.5' ) ), false )['values']['hours_each'] );
		self::assertSame( '0.17', Reminders::validate( array_merge( $this->good(), array( 'hours_each' => 0.17 ) ), false )['values']['hours_each'] );
		self::assertSame( '0', Reminders::validate( array_merge( $this->good(), array( 'hours_each' => '' ) ), false )['values']['hours_each'] );
		self::assertArrayNotHasKey( 'hours_each', Reminders::validate( array( 'title' => 'Only the title' ), true )['values'] );
	}

	public function test_a_time_is_one_of_the_steps_up_to_five_hours(): void {
		foreach ( array( '6', '-1', 'lots', '0.33' ) as $wrong ) {
			self::assertSame( 'Pick a time from the list.', Reminders::validate( array_merge( $this->good(), array( 'hours_each' => $wrong ) ), false )['errors']['hours_each'] );
		}

		self::assertSame( '5', Reminders::validate( array_merge( $this->good(), array( 'hours_each' => '5' ) ), false )['values']['hours_each'] );
	}

	public function test_each_persons_copy_carries_the_full_time(): void {
		$source = array(
			'id'          => 'rem_1',
			'title'       => 'Check the ads',
			'description' => '',
			'starts_on'   => '2026-10-01',
			'ends_on'     => '',
			'hours_each'  => 0.5,
		);

		self::assertSame( 0.5, Reminders::values( $source, 'usr_abc123' )['hours_each'] );
		self::assertSame( 0.5, Reminders::values( $source, 'usr_def456' )['hours_each'] );
		self::assertSame( 0.0, Reminders::values( array_merge( $source, array( 'hours_each' => 0 ) ), 'usr_abc123' )['hours_each'] );
	}

	public function test_a_converted_task_carries_the_title_people_and_time_on_the_reminders_first_day(): void {
		$values = Reminders::task_values(
			array(
				'id'          => 'rem_1',
				'title'       => 'Deadline: File the VAT return',
				'description' => '',
				'starts_on'   => '2026-10-01',
				'ends_on'     => '2026-10-03',
				'hours_each'  => 1.0,
			),
			array( 'usr_abc123', 'usr_def456' ),
			'2026-09-28'
		);

		self::assertSame( 'Deadline: File the VAT return', $values['title'] );
		self::assertSame( '<p>Deadline: File the VAT return</p>', $values['problem'] );
		self::assertSame( array( 'usr_abc123', 'usr_def456' ), $values['assignees'] );
		self::assertSame( 1.0, $values['hours_each'] );
		self::assertSame( '2026-10-01', $values['planned_start'] );
		self::assertSame( '2026-10-01', $values['planned_due'] );
		// A task of its own now, no longer one of the reminder's copies.
		self::assertSame( '', $values['recurring_id'] );
	}

	public function test_a_task_converted_after_the_first_day_is_due_today_not_late(): void {
		$values = Reminders::task_values(
			array(
				'id'          => 'rem_1',
				'title'       => 'Campaign: October push',
				'description' => '',
				'starts_on'   => '2026-10-01',
				'ends_on'     => '2026-10-31',
				'hours_each'  => 0.5,
			),
			array( 'usr_abc123' ),
			'2026-10-21'
		);

		self::assertSame( '2026-10-21', $values['planned_start'] );
		self::assertSame( '2026-10-21', $values['planned_due'] );
	}

	public function test_a_blocked_or_closed_copy_cannot_be_turned_into_a_task(): void {
		$copy = array(
			'stage'            => 'up-next',
			'archived'         => 0,
			'terminal_outcome' => '',
		);

		self::assertTrue( Reminders::closable( $copy ) );
		self::assertFalse( Reminders::closable( array_merge( $copy, array( 'stage' => 'blocked' ) ) ) );
		self::assertFalse( Reminders::closable( array_merge( $copy, array( 'terminal_outcome' => 'cancelled' ) ) ) );
		self::assertFalse( Reminders::closable( array_merge( $copy, array( 'archived' => 1 ) ) ) );
	}

	public function test_a_converted_copy_names_its_task(): void {
		self::assertSame(
			'itm_9',
			Reminders::converted_to(
				array(
					'terminal_outcome' => 'cancelled',
					'duplicate_of'     => 'itm_9',
				)
			)
		);
		self::assertSame(
			'',
			Reminders::converted_to(
				array(
					'terminal_outcome' => '',
					'duplicate_of'     => '',
				)
			)
		);
	}
}
