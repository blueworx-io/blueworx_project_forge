<?php
/**
 * Tests for who our contact is at a client, and what the client may see of them.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\Contacts;
use PHPUnit\Framework\TestCase;

/**
 * #95. Every client has one current internal contact, history is not lost when
 * it changes, and a contact who leaves surfaces for reassignment rather than
 * quietly vanishing.
 *
 * The two halves that need no database are here: what a set of assignments adds
 * up to, and what a client is allowed to see of the person named. Storing and
 * reading the rows is proved against real WordPress.
 */
final class ClientContactTest extends TestCase {

	/**
	 * A person, in the shape Users::get() returns.
	 *
	 * @param string $status Their status.
	 * @return array<string, mixed>
	 */
	private function person( string $status = 'active' ): array {
		return array(
			'id'           => 'usr_ana',
			'display_name' => 'Ana Fielding',
			'email'        => 'ana@studio.example',
			'status'       => $status,
			'wp_user_id'   => 7,
			'grants'       => 'cross_client',
		);
	}

	// -----------------------------------------------------------------------
	// What the assignments add up to.
	// -----------------------------------------------------------------------

	/**
	 * A client nobody has been assigned to has no contact, and says so — this is
	 * a client that needs one, not an error.
	 */
	public function test_a_client_with_no_assignment_has_no_contact(): void {
		$state = Contacts::resolve( null, null );

		$this->assertNull( $state['contact'] );
		$this->assertTrue( $state['needs_reassignment'] );
	}

	/**
	 * The ordinary case: somebody is assigned, they are still here, and nothing
	 * needs anybody's attention.
	 */
	public function test_a_current_contact_who_is_still_here_needs_nothing(): void {
		$state = Contacts::resolve( array( 'user_id' => 'usr_ana' ), $this->person() );

		$this->assertSame( 'usr_ana', $state['contact']['id'] );
		$this->assertFalse( $state['needs_reassignment'] );
	}

	/**
	 * The case #95 exists for. Somebody leaves, every membership they held is
	 * deactivated, and the clients they were the contact for must surface rather
	 * than silently keep pointing at somebody who has gone.
	 */
	public function test_a_contact_who_has_left_is_flagged_for_reassignment(): void {
		$state = Contacts::resolve( array( 'user_id' => 'usr_ana' ), $this->person( 'inactive' ) );

		$this->assertTrue( $state['needs_reassignment'] );
	}

	/**
	 * And they are still shown while it is being sorted out. Blanking the name
	 * loses the one piece of information somebody reassigning the client needs:
	 * who it used to be.
	 */
	public function test_a_contact_who_has_left_is_still_named(): void {
		$state = Contacts::resolve( array( 'user_id' => 'usr_ana' ), $this->person( 'inactive' ) );

		$this->assertSame( 'Ana Fielding', $state['contact']['display_name'] );
	}

	/**
	 * An assignment pointing at a person who is not there at all — a row that
	 * outlived its user — is the same situation, and gets the same answer rather
	 * than a crash.
	 */
	public function test_an_assignment_to_somebody_missing_is_flagged(): void {
		$state = Contacts::resolve( array( 'user_id' => 'usr_gone' ), null );

		$this->assertNull( $state['contact'] );
		$this->assertTrue( $state['needs_reassignment'] );
	}

	/**
	 * An assignment naming nobody is the deliberate form of the same thing: the
	 * contact left and we have not named the next one. It is stored so that
	 * "never had one" and "has not got one right now" are different facts.
	 */
	public function test_an_assignment_naming_nobody_needs_a_contact(): void {
		$state = Contacts::resolve( array( 'user_id' => '' ), null );

		$this->assertNull( $state['contact'] );
		$this->assertTrue( $state['needs_reassignment'] );
	}

	/**
	 * The fallback is defined rather than left to whoever is looking: while
	 * there is no usable contact, the client's contact is the studio itself.
	 * Somebody has to answer the phone.
	 */
	public function test_the_fallback_is_the_studio(): void {
		$this->assertSame( Contacts::FALLBACK_STUDIO, Contacts::resolve( null, null )['fallback'] );
	}

	/**
	 * And there is no fallback while the contact is a real person, so a screen
	 * never shows both.
	 */
	public function test_there_is_no_fallback_while_the_contact_holds(): void {
		$state = Contacts::resolve( array( 'user_id' => 'usr_ana' ), $this->person() );

		$this->assertSame( '', $state['fallback'] );
	}

	// -----------------------------------------------------------------------
	// What the client may see.
	// -----------------------------------------------------------------------

	/**
	 * "The client site shows the contact with no private staff data exposed."
	 * A name is what a client needs; an address, a WordPress account and the
	 * grants somebody holds are all ours.
	 */
	public function test_a_client_sees_a_name_and_nothing_else(): void {
		$shown = Contacts::for_client( $this->person() );

		$this->assertSame( array( 'display_name' ), array_keys( $shown ) );
		$this->assertSame( 'Ana Fielding', $shown['display_name'] );
	}

	/**
	 * Named individually because it is the one that would be least obvious in a
	 * payload and the most damaging in it: the grants column says what somebody
	 * may do, and no client has any business reading it.
	 */
	public function test_a_client_never_sees_what_somebody_is_allowed_to_do(): void {
		$this->assertArrayNotHasKey( 'grants', Contacts::for_client( $this->person() ) );
	}

	/**
	 * With nobody assigned there is nothing to show, and the client gets an
	 * empty answer rather than a placeholder person.
	 */
	public function test_a_client_with_no_contact_is_shown_nobody(): void {
		$this->assertSame( array(), Contacts::for_client( null ) );
	}
}
