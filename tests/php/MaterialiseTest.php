<?php
/**
 * What a due day's task is made of.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Recurring\Materialise;
use Blueworx\Forge\Recurring\Sources;
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
		self::assertSame( 'Subscription Renewal: Acme - (£10.00)', Materialise::title( 'Subscription Renewal: Acme - (£10.00)', '2027-01-01', Sources::SUBSCRIPTION ) );
	}

	public function test_a_task_is_due_on_its_day_with_the_seats_and_hours(): void {
		$values = Materialise::values( $this->source(), '2026-09-14' );

		self::assertSame( 'Weekly backups — 14 Sep', $values['title'] );
		self::assertSame( '<p>Weekly backups</p>', $values['problem'] );
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

	/**
	 * Each person gets a task of their own (2026-09-24): one copy each, with
	 * only them on it, to do and tick off independently.
	 */
	public function test_each_person_gets_their_own_copy(): void {
		$source = array_merge(
			$this->source(),
			array(
				'assignees'  => array( 'usr_x', 'usr_y' ),
				'hours_each' => 0.5,
			)
		);

		$copies = Materialise::copies( $source, '2026-09-14' );

		self::assertCount( 2, $copies );
		self::assertSame( array( 'usr_x' ), $copies[0]['assignees'] );
		self::assertSame( array( 'usr_y' ), $copies[1]['assignees'] );
		self::assertSame( 0.5, $copies[1]['hours_each'] );
	}

	/**
	 * A recurring task carries no checklist (#382): every copy starts with
	 * an empty one, whatever the source (even an old one still holding
	 * checklist lines from before this changed) says.
	 */
	public function test_a_copy_never_carries_a_checklist(): void {
		$source = array_merge(
			$this->source(),
			array(
				'assignees' => array( 'usr_x' ),
				'checklist' => array( array( 'text' => 'Check the log', 'done' => false ) ),
			)
		);

		$copies = Materialise::copies( $source, '2026-09-14' );

		self::assertSame( '[]', $copies[0]['checklist'] );
	}

	/**
	 * A subscription check-in names one person through its primary seat, and
	 * gets one copy for them.
	 */
	public function test_a_check_in_is_one_copy_for_its_primary_seat(): void {
		$copies = Materialise::copies( array_merge( $this->source(), array( 'kind' => Sources::SUBSCRIPTION ) ), '2026-09-14' );

		self::assertCount( 1, $copies );
		self::assertSame( array( 'usr_a' ), $copies[0]['assignees'] );
	}

	/**
	 * Recurring tasks are free (2026-09-24): nobody pays for them, so nobody
	 * is asked who does.
	 */
	public function test_recurring_tasks_are_free(): void {
		self::assertSame( 'free-general', Materialise::values( $this->source(), '2026-09-14' )['commercial_class'] );
	}

	public function test_a_description_becomes_the_problem(): void {
		$values = Materialise::values( array_merge( $this->source(), array( 'description' => 'Run the backup script and check the log.' ) ), '2026-09-14' );

		self::assertSame( 'Run the backup script and check the log.', $values['problem'] );
	}

	public function test_a_title_used_as_the_problem_is_escaped_not_trusted_as_html(): void {
		$values = Materialise::values( array_merge( $this->source(), array( 'title' => '<img src=x onerror=alert(1)>' ) ), '2026-09-14' );

		self::assertStringNotContainsString( '<img', $values['problem'] );
		self::assertStringContainsString( '&lt;img', $values['problem'] );
	}

	/**
	 * #393. A seat whose person has lost access to the client is left empty,
	 * and the day's task is still made with the seats that do reach.
	 */
	public function test_a_seat_without_access_is_left_empty_and_the_task_still_made(): void {
		$seated = Materialise::seated(
			array_merge(
				$this->source(),
				array(
					'client_id'      => 'cli_1',
					'client_site_id' => 'cst_1',
				)
			),
			static fn( string $id ): bool => 'usr_c' === $id
		);

		$copies = Materialise::copies( $seated, '2026-09-14' );

		self::assertCount( 1, $copies );
		self::assertSame( 'Weekly backups — 14 Sep', $copies[0]['title'] );
		self::assertSame( '', $copies[0]['primary_user_id'] );
		self::assertSame( 'usr_c', $copies[0]['deliverer_id'] );
		self::assertSame( array(), $copies[0]['assignees'] );
	}

	/*
	 * #491. Somebody on leave gets no task for that day, and the day is not
	 * made up later: there is nothing to tick off when they are back.
	 */
	public function test_nobody_on_leave_gets_a_copy(): void {
		$source = array_merge(
			$this->source(),
			array(
				'assignees'  => array( 'usr_x', 'usr_y' ),
				'hours_each' => 0.5,
			)
		);
		$away   = static fn( string $person, string $date ): bool => 'usr_x' === $person && '2026-09-14' === $date;

		$copies = Materialise::copies( $source, '2026-09-14', $away );

		self::assertCount( 1, $copies );
		self::assertSame( array( 'usr_y' ), $copies[0]['assignees'] );
		self::assertCount( 2, Materialise::copies( $source, '2026-09-15', $away ), 'back the next day' );
	}

	public function test_a_day_everybody_is_away_makes_nothing(): void {
		$away = static fn(): bool => true;

		self::assertSame( array(), Materialise::copies( $this->source(), '2026-09-14', $away ), 'the primary seat is on leave' );
	}

	// #648. A person whose day has already begun gets its tasks, though the site's has not.
	public function test_tasks_are_made_up_to_the_viewers_day_when_it_is_a_day_ahead(): void {
		self::assertSame( '2026-10-26', Materialise::day( '2026-10-25', '2026-10-26' ) );
		self::assertSame( '2026-11-01', Materialise::day( '2026-10-31', '2026-11-01' ), 'across a month' );
	}

	public function test_the_sites_day_stands_otherwise(): void {
		self::assertSame( '2026-10-25', Materialise::day( '2026-10-25', '' ), 'nobody said' );
		self::assertSame( '2026-10-25', Materialise::day( '2026-10-25', '2026-10-25' ), 'the same day' );
		self::assertSame( '2026-10-25', Materialise::day( '2026-10-25', '2026-10-24' ), 'behind the site' );
		self::assertSame( '2026-10-25', Materialise::day( '2026-10-25', '2026-10-27' ), 'more than a day ahead' );
		self::assertSame( '2026-10-25', Materialise::day( '2026-10-25', '2026-02-31' ), 'not a date' );
		self::assertSame( '2026-10-25', Materialise::day( '2026-10-25', 'tomorrow' ), 'not a date' );
	}
}
