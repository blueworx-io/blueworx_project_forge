<?php
/**
 * What the client's landing view picks out of their work.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

use Blueworx\Forge\Client\Digest;
use PHPUnit\Framework\TestCase;

/**
 * #127. The dashboard answers two questions a client actually asks: what is
 * coming, and what has gone wrong.
 *
 * The rules are here rather than in the screen because they are judgements, not
 * markup. "Overdue" in particular is one somebody will want to argue with, and
 * an argument about a rule is much easier when the rule is a function with
 * tests than when it is an if-statement inside a printf.
 */
final class ClientDigestTest extends TestCase {

	/**
	 * Today, for every test here.
	 */
	private const TODAY = '2026-09-10';

	/**
	 * An item, in the shape the board route sends.
	 *
	 * @param array<string, mixed> $also Fields to add or override.
	 * @return array<string, mixed>
	 */
	private function item( array $also = array() ): array {
		return array_merge(
			array(
				'id'             => 'wrk_1',
				'title'          => 'Rebuild the booking form',
				'stage'          => 'in-development',
				'stage_label'    => 'In development',
				'planned_start'  => '',
				'planned_due'    => '',
				'review_target'  => '',
				'release_target' => '',
			),
			$also
		);
	}

	// -----------------------------------------------------------------------
	// What is coming.
	// -----------------------------------------------------------------------

	/**
	 * Work due later is upcoming, soonest first — which is the order somebody
	 * reads a "what is coming" list in.
	 */
	public function test_upcoming_work_is_soonest_first(): void {
		$upcoming = Digest::upcoming(
			array(
				$this->item( array( 'id' => 'wrk_later', 'planned_due' => '2026-09-30' ) ),
				$this->item( array( 'id' => 'wrk_sooner', 'planned_due' => '2026-09-12' ) ),
			),
			self::TODAY
		);

		$this->assertSame( array( 'wrk_sooner', 'wrk_later' ), array_column( $upcoming, 'id' ) );
	}

	/**
	 * Work due today is still coming. A due date is a day, not a moment, and
	 * dropping it at midnight would take today's work off the list at the exact
	 * point somebody is looking for it.
	 */
	public function test_work_due_today_is_still_coming(): void {
		$upcoming = Digest::upcoming(
			array( $this->item( array( 'planned_due' => self::TODAY ) ) ),
			self::TODAY
		);

		$this->assertCount( 1, $upcoming );
	}

	/**
	 * Work already past its date is not "coming". It is a problem, and it is
	 * listed as one.
	 */
	public function test_overdue_work_is_not_listed_as_coming(): void {
		$upcoming = Digest::upcoming(
			array( $this->item( array( 'planned_due' => '2026-09-01' ) ) ),
			self::TODAY
		);

		$this->assertSame( array(), $upcoming );
	}

	/**
	 * Work with no date cannot be coming up, because nobody has said when.
	 */
	public function test_undated_work_is_not_coming_up(): void {
		$upcoming = Digest::upcoming( array( $this->item() ), self::TODAY );

		$this->assertSame( array(), $upcoming );
	}

	/**
	 * Finished work is not coming, whatever its dates say.
	 */
	public function test_finished_work_is_not_coming(): void {
		$upcoming = Digest::upcoming(
			array(
				$this->item( array( 'stage' => 'released', 'planned_due' => '2026-09-20' ) ),
				$this->item( array( 'stage' => 'completed', 'planned_due' => '2026-09-21' ) ),
			),
			self::TODAY
		);

		$this->assertSame( array(), $upcoming );
	}

	/**
	 * A landing view is a summary. Everything is on the board.
	 */
	public function test_the_list_is_capped(): void {
		$items = array();

		foreach ( range( 1, 12 ) as $n ) {
			$items[] = $this->item(
				array(
					'id'          => 'wrk_' . $n,
					'planned_due' => sprintf( '2026-09-%02d', $n + 10 ),
				)
			);
		}

		$this->assertCount( Digest::LIMIT, Digest::upcoming( $items, self::TODAY ) );
	}

	// -----------------------------------------------------------------------
	// What has gone wrong.
	// -----------------------------------------------------------------------

	/**
	 * Work past its due date wants attention, and says why in words rather than
	 * leaving the reader to compare dates.
	 */
	public function test_overdue_work_wants_attention(): void {
		$attention = Digest::attention(
			array( $this->item( array( 'planned_due' => '2026-09-01' ) ) ),
			self::TODAY
		);

		$this->assertCount( 1, $attention );
		$this->assertSame( 'overdue', $attention[0]['reason'] );
	}

	/**
	 * Blocked work wants attention whether or not it has a date. Blocked is the
	 * state a client most needs to know about, because it is the one where
	 * nothing will happen until somebody does something.
	 */
	public function test_blocked_work_wants_attention(): void {
		$attention = Digest::attention(
			array( $this->item( array( 'stage' => 'blocked' ) ) ),
			self::TODAY
		);

		$this->assertSame( 'blocked', $attention[0]['reason'] );
	}

	/**
	 * Blocked and overdue is one entry, not two. A client counting problems
	 * should count things, not reasons.
	 */
	public function test_work_that_is_both_appears_once(): void {
		$attention = Digest::attention(
			array( $this->item( array( 'stage' => 'blocked', 'planned_due' => '2026-09-01' ) ) ),
			self::TODAY
		);

		$this->assertCount( 1, $attention );
		$this->assertSame( 'blocked', $attention[0]['reason'] );
	}

	/**
	 * Work that finished after its due date is not a problem now. It was late;
	 * it is done, and a dashboard that keeps shouting about it is one people
	 * stop reading.
	 */
	public function test_finished_work_is_never_overdue(): void {
		$attention = Digest::attention(
			array( $this->item( array( 'stage' => 'released', 'planned_due' => '2026-09-01' ) ) ),
			self::TODAY
		);

		$this->assertSame( array(), $attention );
	}

	/**
	 * Nothing wrong means nothing listed, which is what lets the screen say so
	 * rather than showing an empty box.
	 */
	public function test_healthy_work_wants_nothing(): void {
		$attention = Digest::attention(
			array( $this->item( array( 'planned_due' => '2026-09-30' ) ) ),
			self::TODAY
		);

		$this->assertSame( array(), $attention );
	}
}
