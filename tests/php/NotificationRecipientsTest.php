<?php
/**
 * Who a client-facing email goes to.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Notifications\Recipients;
use PHPUnit\Framework\TestCase;

/**
 * NOTIF-1. The submitter and the client's nominated people, once each.
 *
 * The tests below are in two halves, and the second half is the one that
 * matters. The first says who gets the email. The second says what can never
 * become an address — because the thing being sent is a client's confidential
 * update about their own work, and a rule about where addresses come from is
 * only worth having if it holds when somebody puts something odd in a field.
 */
final class NotificationRecipientsTest extends TestCase {

	/**
	 * A verified person on the client.
	 *
	 * @param string $email Their address.
	 * @return array<string, mixed>
	 */
	private function person( string $email ): array {
		return array(
			'id'           => 'usr_' . md5( $email ),
			'email'        => $email,
			'display_name' => ucfirst( strtok( $email, '@' ) ),
		);
	}

	/* ------------------------------------------------------------ who gets it */

	public function test_the_clients_people_get_it(): void {
		$this->assertSame(
			array( 'sam@example.test', 'jo@example.test' ),
			Recipients::choose( array( $this->person( 'sam@example.test' ), $this->person( 'jo@example.test' ) ) )
		);
	}

	public function test_the_submitter_is_first(): void {
		/*
		 * Not cosmetic. An email whose first recipient is the person who asked
		 * reads as a reply to them and the rest as being kept informed, which
		 * is what it is.
		 */
		$this->assertSame(
			array( 'jo@example.test', 'sam@example.test' ),
			Recipients::choose(
				array( $this->person( 'sam@example.test' ), $this->person( 'jo@example.test' ) ),
				'jo@example.test'
			)
		);
	}

	public function test_the_submitter_is_not_sent_two_copies(): void {
		// The submitter is very often also a nominated recipient, and two
		// identical emails about one thing reads as a fault in the product.
		$this->assertSame(
			array( 'jo@example.test' ),
			Recipients::choose( array( $this->person( 'jo@example.test' ) ), 'jo@example.test' )
		);
	}

	public function test_one_person_recorded_twice_is_one_inbox(): void {
		$this->assertSame(
			array( 'jo@example.test' ),
			Recipients::choose( array( $this->person( 'jo@example.test' ), $this->person( 'JO@example.test' ) ) )
		);
	}

	public function test_a_client_with_nobody_set_up_gets_nothing(): void {
		$this->assertSame( array(), Recipients::choose( array() ) );
		$this->assertFalse( Recipients::any( array() ) );
		$this->assertTrue( Recipients::any( array( $this->person( 'jo@example.test' ) ) ) );
	}

	/* --------------------------------------------- what cannot become an address */

	public function test_the_free_text_submitter_never_becomes_an_address(): void {
		/*
		 * The rule the whole class exists for. `submitted_by` is whatever the
		 * client's own site said the person was called; it selects among people
		 * Forge has verified records for, and supplies nothing.
		 */
		$this->assertSame(
			array( 'sam@example.test' ),
			Recipients::choose( array( $this->person( 'sam@example.test' ) ), 'attacker@elsewhere.test' )
		);
	}

	public function test_a_submitter_matching_nobody_is_simply_not_on_the_list(): void {
		// Not an error, and not a reason to send to nobody. A client site can
		// legitimately name somebody who has no Forge record.
		$this->assertSame(
			array( 'sam@example.test' ),
			Recipients::choose( array( $this->person( 'sam@example.test' ) ), 'Somebody From Reception' )
		);
	}

	public function test_a_person_with_no_usable_address_is_left_out(): void {
		$this->assertSame(
			array( 'sam@example.test' ),
			Recipients::choose(
				array(
					$this->person( 'sam@example.test' ),
					array( 'id' => 'usr_blank', 'email' => '', 'display_name' => 'Blank' ),
					array( 'id' => 'usr_junk', 'email' => 'not an address', 'display_name' => 'Junk' ),
				)
			)
		);
	}

	public function test_an_address_carrying_a_header_is_refused(): void {
		/*
		 * A newline in an address is how a second header gets added to an
		 * email. It cannot reach a verified record through the people screen,
		 * and it is refused here as well rather than relied upon not to.
		 */
		$this->assertSame(
			array(),
			Recipients::choose( array( $this->person( "sam@example.test\nBcc: elsewhere@example.test" ) ) )
		);
	}

	public function test_matching_is_on_the_address_and_never_on_a_name(): void {
		// Two people called Sam is an ordinary situation, and guessing between
		// them sends one person's work to the other.
		$people = array( $this->person( 'sam@example.test' ), $this->person( 'jo@example.test' ) );

		$this->assertSame( Recipients::choose( $people ), Recipients::choose( $people, 'Sam' ) );
	}
}
