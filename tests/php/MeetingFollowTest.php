<?php
/**
 * What editing a standing meeting changes about the meetings it has coming.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Meetings\Diary;
use PHPUnit\Framework\TestCase;

/**
 * 2026-09-28, #445. A coming meeting is only rewritten when its length or time
 * actually changed, so renaming a series does not touch every row it has.
 */
final class MeetingFollowTest extends TestCase {

	private function row(): array {
		return array(
			'id'            => 'mto_1',
			'on'            => '2026-10-12',
			'at'            => '13:00',
			'starts_at'     => 1791810000,
			'ends_at'       => 1791813600,
			'planned_hours' => 1.0,
		);
	}

	public function test_nothing_changes_when_the_meeting_already_matches(): void {
		$row = $this->row();

		self::assertSame(
			array(),
			Diary::differences(
				$row,
				array(
					'planned_hours' => 1,
					'at'            => '13:00',
					'starts_at'     => 1791810000,
					'ends_at'       => 1791813600,
				)
			)
		);
	}

	public function test_only_what_differs_is_changed(): void {
		self::assertSame(
			array(
				'planned_hours' => 0.5,
				'ends_at'       => 1791811800,
			),
			Diary::differences(
				$this->row(),
				array(
					'planned_hours' => 0.5,
					'at'            => '13:00',
					'starts_at'     => 1791810000,
					'ends_at'       => 1791811800,
				)
			)
		);
	}

	public function test_a_new_time_is_a_change(): void {
		self::assertSame(
			array( 'at' => '14:00' ),
			Diary::differences( $this->row(), array( 'at' => '14:00', 'planned_hours' => 1.0 ) )
		);
	}
}
