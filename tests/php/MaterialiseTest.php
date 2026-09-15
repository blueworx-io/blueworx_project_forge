<?php
/**
 * What a due day's task is made of.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Recurring\Materialise;
use PHPUnit\Framework\TestCase;

final class MaterialiseTest extends TestCase {

	private function source(): array {
		return array(
			'id'              => 'rec_1',
			'kind'            => 'schedule',
			'title'           => 'Weekly backups',
			'description'     => '',
			'work_type'       => 'task',
			'primary_user_id' => 'usr_a',
			'reviewer_id'     => '',
			'deliverer_id'    => 'usr_c',
			'hours_primary'   => 1.5,
			'hours_review'    => 0.0,
			'hours_delivery'  => 0.25,
		);
	}

	public function test_the_title_carries_the_date(): void {
		self::assertSame( 'Weekly backups — 14 Sep', Materialise::title( 'Weekly backups', '2026-09-14' ) );
		self::assertSame( 'Weekly backups — 1 Jan', Materialise::title( 'Weekly backups', '2027-01-01' ) );
	}

	public function test_a_task_is_due_on_its_day_with_the_seats_and_hours(): void {
		$values = Materialise::values( $this->source(), '2026-09-14' );

		self::assertSame( 'Weekly backups — 14 Sep', $values['title'] );
		self::assertSame( 'Weekly backups', $values['problem'] );
		self::assertSame( '2026-09-14', $values['planned_due'] );
		self::assertSame( '2026-09-14', $values['planned_start'] );
		self::assertSame( 'usr_a', $values['primary_user_id'] );
		self::assertSame( '', $values['reviewer_id'] );
		self::assertSame( 1.5, $values['hours_primary'] );
		self::assertSame( 0.25, $values['hours_delivery'] );
		self::assertSame( 'task', $values['work_type'] );
		self::assertSame( 'normal', $values['priority'] );
		self::assertSame( 'rec_1', $values['recurring_id'] );
	}

	public function test_a_description_becomes_the_problem(): void {
		$values = Materialise::values( array_merge( $this->source(), array( 'description' => 'Run the backup script and check the log.' ) ), '2026-09-14' );

		self::assertSame( 'Run the backup script and check the log.', $values['problem'] );
	}
}
