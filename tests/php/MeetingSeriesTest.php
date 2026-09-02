<?php
/**
 * What a support meeting series has to say before it can exist.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Meetings\Recurrence;
use Blueworx\Forge\Meetings\Validate;
use PHPUnit\Framework\TestCase;

/**
 * #152, MEET-1 to MEET-3. The rules a series is held to.
 *
 * A series is a standing commitment against a client's hours, so the things it
 * must say are the things somebody would otherwise have to guess later: when it
 * starts, how often, how long, in whose clock, and who owns it. Every one of
 * those has a wrong answer that looks like a blank field.
 *
 * The host matters more than it looks. MEET-5 makes marking a meeting held the
 * sole trigger for drawing a client's hours, and MEET-1 gives that to the
 * site's Point of Contact — so a series with nobody named is a series whose
 * hours nobody can ever draw, and it would sit there looking scheduled for ever.
 */
final class MeetingSeriesTest extends TestCase {

	/**
	 * A series as somebody would submit one.
	 *
	 * @param array<string, mixed> $overrides Anything to say differently.
	 * @return array<string, mixed>
	 */
	private function series( array $overrides = array() ): array {
		return array_merge(
			array(
				'client_site_id' => 'cst_one',
				'title'          => 'Fortnightly catch-up',
				'frequency'      => Recurrence::FORTNIGHTLY,
				'starts_on'      => '2026-01-05',
				'ends_on'        => '',
				'time_of_day'    => '10:00',
				'duration_mins'  => 60,
				'timezone'       => 'Europe/London',
				'host_user_id'   => 'usr_one',
				'attendees'      => 'Someone at the client',
				'planned_hours'  => 0,
			),
			$overrides
		);
	}

	/* --------------------------------------------------------- what passes */

	public function test_a_complete_series_is_accepted(): void {
		$checked = Validate::series( $this->series() );

		$this->assertSame( array(), $checked['errors'] );
		$this->assertSame( 'fortnightly', $checked['values']['frequency'] );
		$this->assertSame( 60, $checked['values']['duration_mins'] );
	}

	public function test_an_open_ended_series_is_ordinary(): void {
		// Most standing meetings have no agreed last one. An end date is the
		// exception, not a field somebody has to invent a value for.
		$this->assertSame( array(), Validate::series( $this->series( array( 'ends_on' => '' ) ) )['errors'] );
	}

	/* -------------------------------------------------------- and what does not */

	public function test_a_series_has_to_name_who_owns_it(): void {
		$errors = Validate::series( $this->series( array( 'host_user_id' => '' ) ) )['errors'];

		$this->assertArrayHasKey( 'host_user_id', $errors );
	}

	public function test_a_series_has_to_say_what_it_is(): void {
		$this->assertArrayHasKey( 'title', Validate::series( $this->series( array( 'title' => '  ' ) ) )['errors'] );
	}

	public function test_a_series_belongs_to_a_site(): void {
		$this->assertArrayHasKey( 'client_site_id', Validate::series( $this->series( array( 'client_site_id' => '' ) ) )['errors'] );
	}

	public function test_only_the_four_patterns_are_accepted(): void {
		// MEET-2 chose four on purpose. Anything else here is a caller inventing
		// a fifth, and a rule nobody can expand books nobody in.
		$this->assertArrayHasKey( 'frequency', Validate::series( $this->series( array( 'frequency' => 'daily' ) ) )['errors'] );
	}

	public function test_a_start_date_has_to_be_a_date(): void {
		foreach ( array( '', 'soon', '2026-13-01', '05/01/2026' ) as $bad ) {
			$this->assertArrayHasKey(
				'starts_on',
				Validate::series( $this->series( array( 'starts_on' => $bad ) ) )['errors'],
				$bad . ' was accepted as a date'
			);
		}
	}

	public function test_a_series_cannot_end_before_it_starts(): void {
		$errors = Validate::series( $this->series( array( 'ends_on' => '2025-12-01' ) ) )['errors'];

		$this->assertArrayHasKey( 'ends_on', $errors );
	}

	public function test_a_series_may_end_on_the_day_it_starts(): void {
		// One meeting, arranged as a series. Odd, but not wrong, and refusing it
		// would mean explaining why a one-off has to be entered differently.
		$this->assertSame( array(), Validate::series( $this->series( array( 'ends_on' => '2026-01-05' ) ) )['errors'] );
	}

	public function test_a_time_of_day_has_to_be_a_time(): void {
		foreach ( array( '25:00', '10:70', 'morning', '' ) as $bad ) {
			$this->assertArrayHasKey(
				'time_of_day',
				Validate::series( $this->series( array( 'time_of_day' => $bad ) ) )['errors'],
				$bad . ' was accepted as a time'
			);
		}
	}

	public function test_a_timezone_has_to_be_one(): void {
		$this->assertArrayHasKey( 'timezone', Validate::series( $this->series( array( 'timezone' => 'Middle/Earth' ) ) )['errors'] );
	}

	public function test_a_meeting_has_to_have_a_length_somebody_meant(): void {
		/*
		 * Nought is a meeting that costs nothing and takes no time, which is
		 * not a meeting. The upper bound catches the other typo: eight hours is
		 * already a long day, and a four-figure duration is somebody entering
		 * minutes where seconds were meant or the other way round.
		 */
		$this->assertArrayHasKey( 'duration_mins', Validate::series( $this->series( array( 'duration_mins' => 0 ) ) )['errors'] );
		$this->assertArrayHasKey( 'duration_mins', Validate::series( $this->series( array( 'duration_mins' => 1000 ) ) )['errors'] );
		$this->assertSame( array(), Validate::series( $this->series( array( 'duration_mins' => 480 ) ) )['errors'] );
	}

	public function test_planned_hours_cannot_be_negative(): void {
		$this->assertArrayHasKey( 'planned_hours', Validate::series( $this->series( array( 'planned_hours' => -1 ) ) )['errors'] );
	}

	/* ------------------------------------------------- what the hours default to */

	public function test_planned_hours_left_alone_mean_work_it_out_from_the_length(): void {
		/*
		 * MEET-3. Nought is stored rather than the derived figure copied in, so
		 * a series whose meetings get longer costs more without anybody having
		 * to remember to update a second field — and a figure somebody did
		 * type is left exactly as they typed it.
		 */
		$checked = Validate::series( $this->series( array( 'planned_hours' => 0 ) ) );

		$this->assertSame( 0.0, $checked['values']['planned_hours'] );
		$this->assertSame( 1.0, Validate::hours_for( $checked['values'] ) );
	}

	public function test_an_hours_figure_somebody_set_is_the_one_that_counts(): void {
		$checked = Validate::series( $this->series( array( 'duration_mins' => 60, 'planned_hours' => 2 ) ) );

		$this->assertSame( 2.0, Validate::hours_for( $checked['values'] ) );
	}

	public function test_a_longer_meeting_costs_more_on_its_own(): void {
		$checked = Validate::series( $this->series( array( 'duration_mins' => 100 ) ) );

		$this->assertSame( 2.0, Validate::hours_for( $checked['values'] ) );
	}

	/* ---------------------------------------------------------- and the edges */

	public function test_everything_wrong_is_reported_at_once(): void {
		// The same rule the workflow gates follow (#107). Told about one missing
		// thing at a time, somebody fixes it, resubmits, and is refused again.
		$errors = Validate::series(
			$this->series(
				array(
					'title'        => '',
					'host_user_id' => '',
					'frequency'    => 'daily',
					'timezone'     => 'Middle/Earth',
				)
			)
		)['errors'];

		$this->assertSame(
			array( 'title', 'frequency', 'timezone', 'host_user_id' ),
			array_keys( $errors )
		);
	}

	public function test_a_title_longer_than_the_column_is_cut_rather_than_refused(): void {
		// Refusing a long title makes somebody count characters. Cutting it
		// loses nothing anybody needed, because the field is a label.
		$checked = Validate::series( $this->series( array( 'title' => str_repeat( 'a', 300 ) ) ) );

		$this->assertSame( array(), $checked['errors'] );
		$this->assertSame( Validate::MAX_TITLE, mb_strlen( $checked['values']['title'] ) );
	}
}
