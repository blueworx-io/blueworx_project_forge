<?php
/**
 * Which clients need a commercial conversation, and which are fine.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Commerce\Attention;
use Blueworx\Forge\Commerce\Support;
use PHPUnit\Framework\TestCase;

/**
 * #157, COMM-1 and COMM-4. The cross-client view of who needs selling to.
 *
 * **The whole point is that nobody has to remember to look.** A client whose
 * package runs out next month, or who has four hours left of forty, is a
 * conversation that has to happen before the thing they want doing is refused —
 * and every one of those facts is already in the records. Left to a person
 * checking each site in turn, it is checked when somebody thinks of it, which
 * is usually after the client has been told no.
 *
 * Pure, so what counts as "needs attention" is a rule that can be argued with
 * rather than a query somebody has to read SQL to understand.
 *
 * Four different conversations, and they are deliberately not one flag. A site
 * with no package is a sale; a lapsed one is a renewal and its hours are frozen
 * rather than gone (COMM-4); one running low needs a top-up; one expiring soon
 * needs a diary note. Collapsing them into "needs attention" would put four
 * different jobs on one list with no way to tell them apart.
 */
final class SalesAttentionTest extends TestCase {

	private const TODAY = '2026-03-02';

	/**
	 * A site's commercial position, as Commerce\Support answers it.
	 *
	 * @param string               $state     One of Support::STATES.
	 * @param array<string, mixed> $overrides Anything else.
	 * @return array<string, mixed>
	 */
	private function position( string $state = Support::ACTIVE, array $overrides = array() ): array {
		return array_merge(
			array(
				'state'         => $state,
				'may_use_hours' => Support::ACTIVE === $state,
				'hours_granted' => 40.0,
				'term_ends_on'  => '2026-12-31',
			),
			$overrides
		);
	}

	/* ------------------------------------------------------- nothing to do */

	public function test_a_site_with_plenty_of_hours_and_time_is_not_on_the_list(): void {
		$found = Attention::of( $this->position(), 30.0, self::TODAY );

		$this->assertSame( array(), $found );
		$this->assertFalse( Attention::wanted( $this->position(), 30.0, self::TODAY ) );
	}

	/* ------------------------------------------------- the four conversations */

	public function test_a_site_that_never_bought_anything_is_a_sale(): void {
		$this->assertSame(
			array( Attention::NO_PACKAGE ),
			Attention::of( $this->position( Support::NONE, array( 'hours_granted' => 0.0, 'term_ends_on' => '' ) ), 0.0, self::TODAY )
		);
	}

	public function test_a_site_whose_package_ran_out_is_a_renewal_and_not_a_sale(): void {
		/*
		 * COMM-4 freezes a lapsed site's remaining hours rather than voiding
		 * them, so this is a client who has already paid for something and is
		 * waiting to be allowed to use it. Treating them as a new sale loses
		 * that, and is a different conversation to have.
		 */
		$found = Attention::of( $this->position( Support::LAPSED, array( 'term_ends_on' => '2026-02-01' ) ), 12.0, self::TODAY );

		$this->assertContains( Attention::LAPSED, $found );
		$this->assertNotContains( Attention::NO_PACKAGE, $found );
	}

	public function test_a_site_running_low_needs_a_top_up(): void {
		// Four of forty. The threshold is a share rather than a number, because
		// four hours left of a ten-hour package is a different situation from
		// four left of a hundred.
		$this->assertContains( Attention::LOW_HOURS, Attention::of( $this->position(), 4.0, self::TODAY ) );
	}

	public function test_a_site_with_a_comfortable_share_left_is_not_low(): void {
		$this->assertNotContains( Attention::LOW_HOURS, Attention::of( $this->position(), 12.0, self::TODAY ) );
	}

	public function test_a_term_ending_soon_is_a_diary_note(): void {
		$found = Attention::of( $this->position( Support::ACTIVE, array( 'term_ends_on' => '2026-03-20' ) ), 30.0, self::TODAY );

		$this->assertContains( Attention::EXPIRING, $found );
	}

	public function test_a_term_ending_months_away_is_not(): void {
		$this->assertNotContains( Attention::EXPIRING, Attention::of( $this->position(), 30.0, self::TODAY ) );
	}

	public function test_a_term_with_no_end_never_expires(): void {
		// An open period is running with no end set, which is not the same as
		// one ending today — and reading it as imminent would put every such
		// client on a renewal list for ever.
		$this->assertNotContains(
			Attention::EXPIRING,
			Attention::of( $this->position( Support::ACTIVE, array( 'term_ends_on' => '' ) ), 30.0, self::TODAY )
		);
	}

	/* ------------------------------------------------------ and the overlaps */

	public function test_a_site_can_want_two_conversations_at_once(): void {
		// Running out of hours *and* out of time is the client most worth
		// ringing, and a list that showed only the first reason would hide it.
		$found = Attention::of( $this->position( Support::ACTIVE, array( 'term_ends_on' => '2026-03-10' ) ), 1.0, self::TODAY );

		$this->assertContains( Attention::LOW_HOURS, $found );
		$this->assertContains( Attention::EXPIRING, $found );
	}

	public function test_a_site_with_no_package_is_not_also_reported_as_low_or_expiring(): void {
		/*
		 * They have nothing, so "running low" and "expiring soon" are not facts
		 * about them — they are artefacts of dividing by nought and reading an
		 * empty date. A list saying a client with no package is also low on
		 * hours is a list nobody trusts.
		 */
		$found = Attention::of( $this->position( Support::NONE, array( 'hours_granted' => 0.0, 'term_ends_on' => '' ) ), 0.0, self::TODAY );

		$this->assertSame( array( Attention::NO_PACKAGE ), $found );
	}

	public function test_a_suspended_site_is_its_own_kind_of_attention(): void {
		// Stopped deliberately. It is on the list because somebody should
		// decide whether it stays stopped, not because anything is wrong.
		$this->assertContains(
			Attention::SUSPENDED,
			Attention::of( $this->position( Support::SUSPENDED ), 30.0, self::TODAY )
		);
	}

	public function test_every_reason_reads_as_something_a_person_would_say(): void {
		// The list is a to-do list for a human being, so each row has to say
		// what the job is rather than name a constant.
		foreach ( Attention::REASONS as $reason ) {
			$this->assertNotSame( '', Attention::label( $reason ), $reason . ' has no wording' );
			$this->assertStringNotContainsString( '_', Attention::label( $reason ) );
		}
	}

	public function test_an_unknown_reason_does_not_pretend_to_have_wording(): void {
		$this->assertSame( '', Attention::label( 'invented' ) );
	}

	/* --------------------------------------------------------- the threshold */

	public function test_the_low_water_mark_is_a_share_of_what_was_bought(): void {
		$small = $this->position( Support::ACTIVE, array( 'hours_granted' => 10.0 ) );
		$large = $this->position( Support::ACTIVE, array( 'hours_granted' => 100.0 ) );

		// Four hours left: comfortable on a ten-hour package, alarming on a
		// hundred-hour one.
		$this->assertNotContains( Attention::LOW_HOURS, Attention::of( $small, 4.0, self::TODAY ) );
		$this->assertContains( Attention::LOW_HOURS, Attention::of( $large, 4.0, self::TODAY ) );
	}

	public function test_a_site_that_has_run_out_entirely_is_low(): void {
		$this->assertContains( Attention::LOW_HOURS, Attention::of( $this->position(), 0.0, self::TODAY ) );
	}

	public function test_a_site_somehow_overdrawn_is_low_rather_than_fine(): void {
		// COMM-3 allows a negative balance behind a Primary administrator's
		// override. A comparison that only caught "less than a fifth" and not
		// "below nought" would quietly drop exactly the client most in need of
		// the conversation.
		$this->assertContains( Attention::LOW_HOURS, Attention::of( $this->position(), -3.0, self::TODAY ) );
	}
}
