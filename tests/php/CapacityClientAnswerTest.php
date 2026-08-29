<?php
/**
 * What a client is told about capacity.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Capacity\ClientAnswer;
use Blueworx\Forge\Capacity\Position;
use PHPUnit\Framework\TestCase;

/**
 * The privacy-safe availability result (#140).
 *
 * Two things have to be true at once: it has to be useful enough for a client
 * to plan around, and it has to contain nothing traceable to anybody else. The
 * assertions below are as much about what is absent as about what is there.
 */
final class CapacityClientAnswerTest extends TestCase {

	/**
	 * One person's position.
	 *
	 * @param float $available Hours.
	 * @param float $committed Hours.
	 * @return array<string, mixed>
	 */
	private function position( float $available, float $committed ): array {
		return Position::calculate( $available, $committed, true );
	}

	public function test_a_quiet_studio_has_room(): void {
		$band = ClientAnswer::band(
			array(
				'usr_a' => $this->position( 40.0, 4.0 ),
				'usr_b' => $this->position( 40.0, 8.0 ),
			)
		);

		$this->assertSame( ClientAnswer::ROOM, $band );
	}

	public function test_a_nearly_full_studio_is_tight(): void {
		$band = ClientAnswer::band(
			array(
				'usr_a' => $this->position( 40.0, 34.0 ),
				'usr_b' => $this->position( 40.0, 34.0 ),
			)
		);

		$this->assertSame( ClientAnswer::TIGHT, $band );
	}

	public function test_a_full_studio_has_none(): void {
		$band = ClientAnswer::band(
			array(
				'usr_a' => $this->position( 40.0, 48.0 ),
				'usr_b' => $this->position( 40.0, 40.0 ),
			)
		);

		$this->assertSame( ClientAnswer::NONE, $band );
	}

	/**
	 * One person being buried does not mean the studio is.
	 *
	 * The answer is about whether there is room for the next piece of work, and
	 * a band taken from the worst-off person would report "none" for a studio
	 * with three people sitting idle.
	 */
	public function test_one_overloaded_person_does_not_close_the_studio(): void {
		$band = ClientAnswer::band(
			array(
				'usr_a' => $this->position( 40.0, 60.0 ),
				'usr_b' => $this->position( 40.0, 0.0 ),
				'usr_c' => $this->position( 40.0, 0.0 ),
			)
		);

		$this->assertSame( ClientAnswer::ROOM, $band );
	}

	public function test_a_studio_nobody_has_set_up_promises_nothing(): void {
		$band = ClientAnswer::band(
			array(
				'usr_a' => Position::calculate( 0.0, 0.0, false ),
			)
		);

		$this->assertSame( ClientAnswer::NONE, $band, 'no hours recorded is not a promise of room' );
	}

	public function test_nobody_at_all_promises_nothing(): void {
		$this->assertSame( ClientAnswer::NONE, ClientAnswer::band( array() ) );
	}

	/**
	 * Somebody nobody has set up neither adds room nor takes it away.
	 */
	public function test_an_unrecorded_person_is_left_out_of_the_aggregate(): void {
		$band = ClientAnswer::band(
			array(
				'usr_a' => $this->position( 40.0, 4.0 ),
				'usr_b' => Position::calculate( 0.0, 0.0, false ),
			)
		);

		$this->assertSame( ClientAnswer::ROOM, $band );
	}

	public function test_the_answer_carries_nothing_but_a_band_and_a_date(): void {
		$answer = ClientAnswer::compose( ClientAnswer::ROOM, '2026-09-14', '2026-09-07', '2026-10-04' );

		$this->assertSame(
			array( 'availability', 'earliest', 'from', 'to' ),
			array_keys( $answer ),
			'nothing else may appear in a client answer, ever'
		);
		$this->assertSame( ClientAnswer::ROOM, $answer['availability'] );
		$this->assertSame( '2026-09-14', $answer['earliest'] );
	}

	public function test_the_earliest_date_is_the_first_week_with_room(): void {
		$earliest = ClientAnswer::earliest(
			array(
				array(
					'from' => '2026-09-07',
					'band' => ClientAnswer::NONE,
				),
				array(
					'from' => '2026-09-14',
					'band' => ClientAnswer::TIGHT,
				),
				array(
					'from' => '2026-09-21',
					'band' => ClientAnswer::ROOM,
				),
			)
		);

		$this->assertSame( '2026-09-21', $earliest );
	}

	public function test_no_room_in_the_window_gives_no_date(): void {
		$earliest = ClientAnswer::earliest(
			array(
				array(
					'from' => '2026-09-07',
					'band' => ClientAnswer::NONE,
				),
			)
		);

		$this->assertSame( '', $earliest, 'a date invented outside the window would be a promise' );
	}
}
