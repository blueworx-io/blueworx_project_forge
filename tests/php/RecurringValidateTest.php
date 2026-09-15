<?php
/**
 * What a recurring task definition is allowed to say.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Recurring\Validate;
use PHPUnit\Framework\TestCase;

final class RecurringValidateTest extends TestCase {

	private function good(): array {
		return array(
			'title'           => 'Weekly backups',
			'work_type'       => 'task',
			'rule'            => array(
				'every' => 'week',
				'days'  => array( 1 ),
			),
			'primary_user_id' => 'usr_abc123',
			'hours_primary'   => '1.5',
			'starts_on'       => '2026-09-14',
			'ends_on'         => '',
		);
	}

	public function test_a_good_definition_passes_cleaned(): void {
		$checked = Validate::source( $this->good(), false );

		self::assertSame( array(), $checked['errors'] );
		self::assertSame( 'Weekly backups', $checked['values']['title'] );
		self::assertSame( array( 'every' => 'week', 'days' => array( 1 ) ), $checked['values']['rule'] );
		self::assertSame( '1.5', $checked['values']['hours_primary'] );
		self::assertSame( '', $checked['values']['ends_on'] );
	}

	public function test_a_title_is_required(): void {
		$checked = Validate::source( array_merge( $this->good(), array( 'title' => ' ' ) ), false );

		self::assertArrayHasKey( 'title', $checked['errors'] );
	}

	public function test_a_bad_rule_is_refused(): void {
		$checked = Validate::source( array_merge( $this->good(), array( 'rule' => array( 'every' => 'fortnight' ) ) ), false );

		self::assertArrayHasKey( 'rule', $checked['errors'] );
	}

	public function test_negative_hours_are_refused(): void {
		$checked = Validate::source( array_merge( $this->good(), array( 'hours_review' => '-1' ) ), false );

		self::assertArrayHasKey( 'hours_review', $checked['errors'] );
	}

	public function test_it_cannot_end_before_it_starts(): void {
		$checked = Validate::source( array_merge( $this->good(), array( 'ends_on' => '2026-09-01' ) ), false );

		self::assertArrayHasKey( 'ends_on', $checked['errors'] );
	}

	public function test_a_seat_is_a_person_or_nobody(): void {
		$checked = Validate::source( array_merge( $this->good(), array( 'reviewer_id' => 'bob' ) ), false );
		self::assertArrayHasKey( 'reviewer_id', $checked['errors'] );

		$checked = Validate::source( array_merge( $this->good(), array( 'reviewer_id' => '' ) ), false );
		self::assertSame( '', $checked['values']['reviewer_id'] );
	}

	public function test_an_edit_may_say_only_what_it_changes(): void {
		$checked = Validate::source( array( 'status' => 'paused' ), true );

		self::assertSame( array(), $checked['errors'] );
		self::assertSame( array( 'status' => 'paused' ), $checked['values'] );
	}

	public function test_ending_is_not_a_status_you_can_set(): void {
		$checked = Validate::source( array( 'status' => 'ended' ), true );

		self::assertArrayHasKey( 'status', $checked['errors'] );
	}
}
