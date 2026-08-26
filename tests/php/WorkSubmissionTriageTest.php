<?php
/**
 * What the studio may write onto a request, and what it may never write.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\Capabilities;
use Blueworx\Forge\Tenancy\Roles;
use Blueworx\Forge\Work\Submissions;
use PHPUnit\Framework\TestCase;

/**
 * #131. Triage writes the studio's answer, and only the studio's answer.
 *
 * REQ-1 fixes the client's words at submission, and #129 kept that by simply
 * not writing an update method. This is the first code that updates a
 * submission at all, so it is the first thing that could break the rule — and
 * it would break it silently, by accepting one more key than it meant to.
 *
 * So the allowlist is the unit under test rather than an implementation detail
 * of the route. A write path that filtered its input inside a controller would
 * be one refactor away from a second write path that forgot to.
 */
final class WorkSubmissionTriageTest extends TestCase {

	/**
	 * A context for a role on an interface.
	 *
	 * @param string $role      Role held.
	 * @param string $interface Studio or client.
	 * @return array<string, mixed>
	 */
	private function who( string $role, string $interface = Capabilities::STUDIO ): array {
		return array(
			'role'      => $role,
			'interface' => $interface,
		);
	}

	// ---- What a triage write may set ------------------------------------

	/**
	 * The state is one of the two things triage exists to set.
	 */
	public function test_keeps_a_real_intake_state(): void {
		$changes = Submissions::changes( array( 'intake_state' => 'in-review' ) );

		$this->assertSame( 'in-review', $changes['intake_state'] );
	}

	/**
	 * The reply is the other.
	 */
	public function test_keeps_the_response(): void {
		$changes = Submissions::changes( array( 'response' => 'Booked in for next sprint.' ) );

		$this->assertSame( 'Booked in for next sprint.', $changes['response'] );
	}

	/**
	 * A state nobody defined is not written. An intake state is a closed list,
	 * and a row sitting in a state no screen has a word for is worse than a
	 * refused write.
	 */
	public function test_refuses_an_invented_intake_state(): void {
		$changes = Submissions::changes( array( 'intake_state' => 'urgent' ) );

		$this->assertArrayNotHasKey( 'intake_state', $changes );
	}

	// ---- What it may never set ------------------------------------------

	/**
	 * The client's own words survive a write that tries to change them.
	 *
	 * This is the test the whole class exists for. Every field the client
	 * filled in, offered to the writer at once, and none of them come back.
	 */
	public function test_never_writes_the_words_the_client_sent(): void {
		$changes = Submissions::changes(
			array(
				'title'           => 'Rewritten to match what we delivered',
				'description'     => 'Rewritten.',
				'desired_outcome' => 'Rewritten.',
				'evidence'        => 'Rewritten.',
				'submitted_by'    => 'someone-else',
				'intake_state'    => 'accepted',
			)
		);

		$this->assertSame( array( 'intake_state' => 'accepted' ), $changes );
	}

	/**
	 * The work a request became is not set here. Conversion is #132's job and
	 * has its own rules — the item has to be created for the same client, which
	 * this route cannot check. A triage write that could set the id would be a
	 * way to link a request to another client's work.
	 */
	public function test_never_writes_the_converted_item_id(): void {
		$changes = Submissions::changes( array( 'converted_item_id' => 'itm_someone_elses' ) );

		$this->assertArrayNotHasKey( 'converted_item_id', $changes );
	}

	/**
	 * Which client a request came from is decided by the signature on the
	 * request that carried it (#129), never by whoever is triaging it.
	 */
	public function test_never_moves_a_request_to_another_client(): void {
		$changes = Submissions::changes(
			array(
				'client_id'      => 'cli_belltown',
				'client_site_id' => 'site_belltown',
			)
		);

		$this->assertSame( array(), $changes );
	}

	/**
	 * Nothing offered, nothing written — rather than an empty update that
	 * bumps the record and tells the client something changed.
	 */
	public function test_an_empty_write_changes_nothing(): void {
		$this->assertSame( array(), Submissions::changes( array() ) );
	}

	/**
	 * A key with nothing in it means "not sent", not "set it to empty".
	 *
	 * This is the difference between setting a state and wiping the reply. A
	 * request body carrying only the new state arrives with the response key
	 * present and null, and treating that as an empty string would delete an
	 * answer the client has already read.
	 */
	public function test_a_key_that_was_not_sent_does_not_clear_what_is_there(): void {
		$changes = Submissions::changes(
			array(
				'intake_state' => 'accepted',
				'response'     => null,
			)
		);

		$this->assertSame( array( 'intake_state' => 'accepted' ), $changes );
	}

	/**
	 * Deliberately sending an empty reply still clears it. Somebody deleting
	 * what they wrote and saving means it.
	 */
	public function test_an_explicitly_empty_reply_is_still_written(): void {
		$changes = Submissions::changes( array( 'response' => '' ) );

		$this->assertSame( array( 'response' => '' ), $changes );
	}

	// ---- Who may do it --------------------------------------------------

	/**
	 * Studio staff triage requests. That is the job.
	 */
	public function test_studio_staff_may_triage(): void {
		$this->assertTrue(
			Capabilities::allows( Capabilities::REVIEW_SUBMISSION, $this->who( Roles::STAFF ) )
		);
	}

	/**
	 * A client administrator may send a request and read the reply. Writing
	 * the reply is answering oneself.
	 */
	public function test_a_client_administrator_may_not_triage(): void {
		$this->assertFalse(
			Capabilities::allows( Capabilities::REVIEW_SUBMISSION, $this->who( Roles::CLIENT_ADMIN ) )
		);
	}

	/**
	 * And not from the client interface either, by anybody. The client plugin
	 * contains no studio code (ARCH-1), so this is `no` by construction — the
	 * test is here so that stays true if the grid is ever edited by hand.
	 */
	public function test_nobody_triages_from_the_client_interface(): void {
		foreach ( Roles::ALL as $role ) {
			$this->assertFalse(
				Capabilities::allows( Capabilities::REVIEW_SUBMISSION, $this->who( $role, Capabilities::CLIENT ) ),
				"{$role} can triage from the client interface"
			);
		}
	}
}
