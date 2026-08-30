<?php
/**
 * The one door an answer to an onboarding step goes through.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Onboarding\Answers;
use PHPUnit\Framework\TestCase;

/**
 * #167. Every write of a client's answer passes here, and here is where a
 * credential is turned away.
 *
 * The check lives on the write path rather than in a controller for the same
 * reason the schema has no credential column: a rule that each caller has to
 * remember is a rule the next caller forgets. There is one door, and the
 * screens are on the other side of it.
 */
final class OnboardingAnswersTest extends TestCase {

	public function test_a_password_in_the_notes_is_refused_and_the_field_is_named(): void {
		$refusal = Answers::refusal( array( 'response' => 'password: hunter2' ) );

		$this->assertSame( 'response', $refusal['field'] );
		$this->assertNotSame( '', $refusal['message'] );
	}

	public function test_a_key_in_any_field_is_refused(): void {
		$this->assertSame(
			'account_identifier',
			Answers::refusal( array( 'account_identifier' => 'sk_live_51H8xTgKq9wPmNvRt2Jc4Lb' ) )['field']
		);
	}

	public function test_the_refusal_says_what_to_do_instead(): void {
		/*
		 * ONB-3 does not merely forbid credentials, it says what to do instead
		 * — invite our named account. A refusal that stops at "no" leaves
		 * somebody stuck and emailing the password to us instead, which is
		 * worse than what they tried.
		 */
		$message = Answers::refusal( array( 'response' => 'password: hunter2' ) )['message'];

		$this->assertStringContainsString( 'invite', strtolower( $message ) );
	}

	public function test_an_ordinary_answer_is_not_refused(): void {
		$this->assertSame(
			array(),
			Answers::refusal(
				array(
					'response'           => 'Invited your account on Monday as an administrator.',
					'provider'           => 'Cloudflare',
					'account_identifier' => 'accounts@example.co.uk',
				)
			)
		);
	}

	/* -------------------------------------------------------------- writable */

	public function test_only_the_answer_fields_may_be_written(): void {
		/*
		 * The list is short and closed. A client editing their own step must
		 * not be able to reach the things the studio decides about it — who
		 * reviews it, whether it gates a launch, when it is due, or where it
		 * sits in the list.
		 */
		$writable = Answers::writable(
			array(
				'response'        => 'Done.',
				'launch_critical' => 1,
				'reviewer_id'     => 'usr_someone',
				'due_on'          => '2026-01-01',
				'position'        => 1,
				'status'          => 'approved',
				'client_site_id'  => 'cst_elsewhere',
			)
		);

		$this->assertSame( array( 'response' => 'Done.' ), $writable );
	}

	public function test_the_access_handover_fields_are_writable(): void {
		// The things ONB-3 says Forge does keep.
		$writable = Answers::writable(
			array(
				'provider'             => 'Cloudflare',
				'account_identifier'   => 'accounts@example.co.uk',
				'account_owner'        => 'Priya Raman',
				'access_role'          => 'Administrator',
				'invitation_status'    => 'sent',
				'verification_outcome' => 'verified',
			)
		);

		$this->assertCount( 6, $writable );
	}

	public function test_answer_text_is_bounded(): void {
		$writable = Answers::writable( array( 'response' => str_repeat( 'x', Answers::MAX_RESPONSE + 500 ) ) );

		$this->assertSame( Answers::MAX_RESPONSE, mb_strlen( (string) $writable['response'] ) );
	}

	/* ---------------------------------------------------------------- moves */

	public function test_a_client_may_move_a_step_only_to_the_two_places_that_are_theirs(): void {
		/*
		 * ONB-2: a client may never approve their own step, even one they own.
		 * Getting on with it and handing it over are the whole of what is
		 * theirs to say.
		 */
		$this->assertTrue( Answers::client_may_move_to( 'in-progress' ) );
		$this->assertTrue( Answers::client_may_move_to( 'submitted' ) );

		foreach ( array( 'approved', 'not-applicable', 'returned', 'blocked', 'not-started' ) as $status ) {
			$this->assertFalse( Answers::client_may_move_to( $status ), $status . ' must not be a client move' );
		}
	}

	public function test_an_invented_status_is_not_a_move(): void {
		$this->assertFalse( Answers::client_may_move_to( 'done' ) );
		$this->assertFalse( Answers::client_may_move_to( '' ) );
	}
}
