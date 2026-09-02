<?php
/**
 * Individual meetings that can move without rewriting the rule.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Meetings\Occurrence;
use Blueworx\Forge\Meetings\Recurrence;
use Blueworx\Forge\Meetings\Series;
use PHPUnit\Framework\TestCase;

/**
 * #153, MEET-2 and MEET-5. What a rule says, and what actually happened.
 *
 * **Most meetings are not stored anywhere.** A weekly series running for two
 * years is one row, and the hundred meetings it implies are worked out when
 * somebody asks. A meeting gets a row of its own only when something happens to
 * it — it moves, it is cancelled, it is held — and that row is tied to the slot
 * in the rule it came from rather than to a date.
 *
 * That tie is the whole design. Moving one meeting has to change one meeting:
 * not the rule, not the meetings after it, and not the same weekday for ever
 * more. Storing an exception against "the meeting the rule put on 12 January"
 * does that; storing it against "12 January" does not survive the rule
 * changing, and rewriting the rule does not survive anything at all.
 */
final class MeetingOccurrenceTest extends TestCase {

	/**
	 * A weekly series, Mondays at ten.
	 *
	 * @param array<string, mixed> $overrides Anything to say differently.
	 * @return array<string, mixed>
	 */
	private function series( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'            => 'mts_one',
				'state'         => Series::ACTIVE,
				'frequency'     => Recurrence::WEEKLY,
				'starts_on'     => '2026-01-05',
				'ends_on'       => '',
				'time_of_day'   => '10:00',
				'duration_mins' => 60,
				'timezone'      => 'Europe/London',
				'planned_hours' => 0,
			),
			$overrides
		);
	}

	/**
	 * A stored exception, as the table holds one.
	 *
	 * @param string               $from      The date the rule put it on.
	 * @param string               $on        Where it actually is.
	 * @param array<string, mixed> $overrides Anything else.
	 * @return array<string, mixed>
	 */
	private function exception( string $from, string $on, array $overrides = array() ): array {
		return array_merge(
			array(
				'id'            => 'mto_' . str_replace( '-', '', $from ),
				'series_id'     => 'mts_one',
				'excepted_from' => $from,
				'on'            => $on,
				'at'            => '10:00',
				'status'        => Occurrence::SCHEDULED,
				'planned_hours' => 1.0,
				'meeting_link'  => '',
			),
			$overrides
		);
	}

	/**
	 * The dates a merged view holds.
	 *
	 * @param array<int, array<string, mixed>> $merged What merge() returned.
	 * @return array<int, string>
	 */
	private function dates( array $merged ): array {
		return array_column( $merged, 'on' );
	}

	/* --------------------------------------------- a rule with nothing against it */

	public function test_a_series_nobody_has_touched_is_all_rule_and_no_rows(): void {
		$merged = Occurrence::merge(
			Series::occurrences( $this->series(), '2026-01-01', '2026-02-01' ),
			array(),
			'2026-01-01',
			'2026-02-01'
		);

		$this->assertSame( array( '2026-01-05', '2026-01-12', '2026-01-19', '2026-01-26' ), $this->dates( $merged ) );

		// And every one of them is a meeting nobody has stored, which is what
		// lets the rule still be changed underneath them.
		$this->assertSame( array( false, false, false, false ), array_column( $merged, 'stored' ) );
	}

	public function test_every_calculated_meeting_is_scheduled_until_somebody_says_otherwise(): void {
		$merged = Occurrence::merge( Series::occurrences( $this->series(), '2026-01-01', '2026-01-12' ), array() );

		$this->assertSame( Occurrence::SCHEDULED, $merged[0]['status'] );
	}

	/* ------------------------------------------------------ moving exactly one */

	public function test_rescheduling_one_meeting_changes_one_meeting(): void {
		/*
		 * #153's acceptance criterion, asserted as the whole picture rather
		 * than as the moved date alone — because every wrong implementation of
		 * this gets the moved meeting right and something else wrong. Rewriting
		 * the rule moves them all; storing against the date rather than the
		 * slot moves the wrong one when the rule later changes.
		 */
		$before = Occurrence::merge( Series::occurrences( $this->series(), '2026-01-01', '2026-02-01' ), array() );

		$after = Occurrence::merge(
			Series::occurrences( $this->series(), '2026-01-01', '2026-02-01' ),
			array( $this->exception( '2026-01-12', '2026-01-14' ) )
		);

		$this->assertSame( array( '2026-01-05', '2026-01-14', '2026-01-19', '2026-01-26' ), $this->dates( $after ) );
		$this->assertCount( count( $before ), $after );

		// The three nobody touched are untouched, down to the instant.
		foreach ( array( 0, 2, 3 ) as $index ) {
			$this->assertSame( $before[ $index ]['starts_at'], $after[ $index ]['starts_at'] );
		}
	}

	public function test_a_moved_meeting_says_where_it_came_from(): void {
		// So a screen can say "moved from the twelfth" rather than showing a
		// meeting on a Wednesday with no explanation.
		$merged = Occurrence::merge(
			Series::occurrences( $this->series(), '2026-01-01', '2026-02-01' ),
			array( $this->exception( '2026-01-12', '2026-01-14' ) )
		);

		$this->assertSame( '2026-01-12', $merged[1]['excepted_from'] );
		$this->assertTrue( $merged[1]['moved'] );
		$this->assertFalse( $merged[0]['moved'] );
	}

	public function test_a_meeting_moved_out_of_the_window_leaves_it(): void {
		// Asked what is in January, a meeting moved to February is not.
		$merged = Occurrence::merge(
			Series::occurrences( $this->series(), '2026-01-01', '2026-01-31' ),
			array( $this->exception( '2026-01-26', '2026-02-04' ) ),
			'2026-01-01',
			'2026-01-31'
		);

		$this->assertSame( array( '2026-01-05', '2026-01-12', '2026-01-19' ), $this->dates( $merged ) );
	}

	public function test_a_meeting_moved_into_the_window_appears_in_it(): void {
		/*
		 * The half that a naive overlay misses. A February meeting pulled
		 * forward into January belongs in January's list, and it has no
		 * calculated date there to be matched against — so an implementation
		 * that only ever replaces calculated dates loses it entirely.
		 */
		$merged = Occurrence::merge(
			Series::occurrences( $this->series(), '2026-01-01', '2026-01-31' ),
			array( $this->exception( '2026-02-02', '2026-01-28' ) ),
			'2026-01-01',
			'2026-01-31'
		);

		$this->assertSame(
			array( '2026-01-05', '2026-01-12', '2026-01-19', '2026-01-26', '2026-01-28' ),
			$this->dates( $merged )
		);
	}

	public function test_meetings_come_back_in_the_order_they_happen(): void {
		// Including one that moved backwards past its neighbour, which is the
		// case that catches an implementation appending exceptions at the end.
		$merged = Occurrence::merge(
			Series::occurrences( $this->series(), '2026-01-01', '2026-02-01' ),
			array( $this->exception( '2026-01-26', '2026-01-06' ) ),
			'2026-01-01',
			'2026-02-01'
		);

		$this->assertSame( array( '2026-01-05', '2026-01-06', '2026-01-12', '2026-01-19' ), $this->dates( $merged ) );
	}

	/* ---------------------------------------------------------- what happened */

	public function test_a_cancelled_meeting_stays_on_the_list_saying_it_was_cancelled(): void {
		/*
		 * Rather than vanishing. A meeting that disappears is one nobody can
		 * tell from one that never existed, and the client asking "what
		 * happened to Tuesday" gets no answer.
		 */
		$merged = Occurrence::merge(
			Series::occurrences( $this->series(), '2026-01-01', '2026-01-31' ),
			array( $this->exception( '2026-01-12', '2026-01-12', array( 'status' => Occurrence::CANCELLED ) ) )
		);

		$this->assertSame( array( '2026-01-05', '2026-01-12', '2026-01-19', '2026-01-26' ), $this->dates( $merged ) );
		$this->assertSame( Occurrence::CANCELLED, $merged[1]['status'] );
	}

	public function test_the_four_things_that_can_have_happened(): void {
		// MEET-5's whole vocabulary, and no more of it. Anything else is a
		// state somebody invented, and it would decide hours by accident.
		$this->assertSame(
			array( 'scheduled', 'held', 'cancelled', 'no-show' ),
			Occurrence::STATUSES
		);
	}

	public function test_only_a_held_meeting_has_happened_for_hours_purposes(): void {
		/*
		 * MEET-5. There is no late-cancellation charge and no no-show charge,
		 * so this is the single question #154 asks of a status — and it is
		 * asked here, once, rather than by each caller comparing strings.
		 */
		$this->assertTrue( Occurrence::draws_hours( Occurrence::HELD ) );

		foreach ( array( Occurrence::SCHEDULED, Occurrence::CANCELLED, Occurrence::NO_SHOW ) as $status ) {
			$this->assertFalse( Occurrence::draws_hours( $status ), $status . ' drew hours' );
		}
	}

	public function test_a_meeting_that_has_happened_is_settled(): void {
		// Held, cancelled and no-show are all answers. Scheduled is the absence
		// of one, and it is the only status a meeting can be left sitting in.
		$this->assertFalse( Occurrence::settled( Occurrence::SCHEDULED ) );
		$this->assertTrue( Occurrence::settled( Occurrence::HELD ) );
		$this->assertTrue( Occurrence::settled( Occurrence::CANCELLED ) );
		$this->assertTrue( Occurrence::settled( Occurrence::NO_SHOW ) );
	}

	public function test_an_unknown_status_is_not_one(): void {
		$this->assertFalse( Occurrence::exists( 'postponed' ) );
		$this->assertFalse( Occurrence::draws_hours( 'postponed' ) );
	}

	/* ------------------------------------------------ what a stored row keeps */

	public function test_a_stored_meeting_keeps_its_own_hours_rather_than_the_series(): void {
		/*
		 * MEET-3 makes planned hours editable per occurrence as well as per
		 * series. A meeting somebody agreed would run long costs what they
		 * agreed, not what the pattern says.
		 */
		$merged = Occurrence::merge(
			Series::occurrences( $this->series( array( 'duration_mins' => 60 ) ), '2026-01-01', '2026-01-31' ),
			array( $this->exception( '2026-01-12', '2026-01-12', array( 'planned_hours' => 3.0 ) ) )
		);

		$this->assertSame( 1.0, $merged[0]['planned_hours'] );
		$this->assertSame( 3.0, $merged[1]['planned_hours'] );
	}

	public function test_a_stored_meeting_carries_its_id_and_a_calculated_one_has_none(): void {
		// How a screen knows whether pressing "cancel" writes a row or edits
		// one, and how #154 knows what to hang a ledger entry on.
		$merged = Occurrence::merge(
			Series::occurrences( $this->series(), '2026-01-01', '2026-01-19' ),
			array( $this->exception( '2026-01-12', '2026-01-12' ) )
		);

		$this->assertSame( '', $merged[0]['id'] );
		$this->assertSame( 'mto_20260112', $merged[1]['id'] );
	}

	public function test_an_exception_against_a_slot_the_rule_no_longer_has_is_still_honoured(): void {
		/*
		 * The rule changed from weekly to fortnightly after somebody had
		 * already moved a meeting. The slot it was excepted from is gone, but
		 * the meeting was agreed with a client and is still happening — so it
		 * stays, rather than being silently dropped because its origin no
		 * longer computes.
		 */
		$merged = Occurrence::merge(
			Series::occurrences( $this->series( array( 'frequency' => Recurrence::FORTNIGHTLY ) ), '2026-01-01', '2026-01-31' ),
			array( $this->exception( '2026-01-12', '2026-01-14' ) )
		);

		$this->assertSame( array( '2026-01-05', '2026-01-14', '2026-01-19' ), $this->dates( $merged ) );
	}

	public function test_two_exceptions_landing_on_one_day_both_survive(): void {
		// Two meetings on one day is unusual and it is not impossible, and
		// keying the merged list by date would silently eat one of them.
		$merged = Occurrence::merge(
			Series::occurrences( $this->series(), '2026-01-01', '2026-01-31' ),
			array(
				$this->exception( '2026-01-12', '2026-01-13' ),
				$this->exception( '2026-01-19', '2026-01-13' ),
			),
			'2026-01-01',
			'2026-01-31'
		);

		$this->assertSame( array( '2026-01-05', '2026-01-13', '2026-01-13', '2026-01-26' ), $this->dates( $merged ) );
	}
}
