<?php
/**
 * What a client may send when they ask for something.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\Submissions;
use Blueworx\Forge\Work\Validate;
use PHPUnit\Framework\TestCase;

/**
 * #129. A client can ask for things whether or not they pay for support, and
 * what they send is fixed the moment they send it.
 *
 * The rules worth testing are the ones a client will hit by accident: an empty
 * form, a type they did not choose from the list, a description long enough to
 * be a document. What is deliberately not tested here is storage — the
 * immutability promise is kept by there being no route and no method that
 * writes a second time, and that is proved against a real site.
 */
final class SubmissionValidateTest extends TestCase {

	/**
	 * A complete submission, as a client site would send it.
	 *
	 * @param array<string, mixed> $also Fields to add or override.
	 * @return array<string, mixed>
	 */
	private function sent( array $also = array() ): array {
		return array_merge(
			array(
				'type'            => 'request',
				'title'           => 'Add a booking form to the contact page',
				'description'     => 'People keep ringing to book, and we would rather they did it themselves.',
				'desired_outcome' => 'A form on the contact page that emails the office.',
				'evidence'        => 'https://example.test/contact',
			),
			$also
		);
	}

	// -----------------------------------------------------------------------
	// The ordinary case.
	// -----------------------------------------------------------------------

	/**
	 * A complete submission is accepted, and comes back trimmed.
	 */
	public function test_a_complete_submission_is_accepted(): void {
		$checked = Validate::submission( $this->sent( array( 'title' => '  Spaced out  ' ) ) );

		$this->assertSame( array(), $checked['errors'] );
		$this->assertSame( 'Spaced out', $checked['values']['title'] );
	}

	/**
	 * All three types are real. This is the list a client chooses from, and it
	 * exists so "can we have X" and "here is an idea" do not arrive as the same
	 * thing and get triaged the same way.
	 */
	public function test_every_type_a_client_may_choose_is_accepted(): void {
		foreach ( Submissions::TYPES as $type ) {
			$checked = Validate::submission( $this->sent( array( 'type' => $type ) ) );

			$this->assertSame( array(), $checked['errors'], $type . ' was refused' );
		}
	}

	// -----------------------------------------------------------------------
	// What is refused.
	// -----------------------------------------------------------------------

	/**
	 * A type nobody offered is refused rather than quietly corrected. A
	 * submission filed under a type the studio does not triage is one that sits
	 * in a queue nobody works.
	 */
	public function test_a_type_nobody_offered_is_refused(): void {
		$checked = Validate::submission( $this->sent( array( 'type' => 'complaint' ) ) );

		$this->assertArrayHasKey( 'type', $checked['errors'] );
	}

	/**
	 * Something has to be asked for.
	 */
	public function test_a_submission_needs_a_title(): void {
		$checked = Validate::submission( $this->sent( array( 'title' => '   ' ) ) );

		$this->assertArrayHasKey( 'title', $checked['errors'] );
	}

	/**
	 * And it has to say what is wanted. A title alone is a subject line, and
	 * triaging it means guessing or going back to ask.
	 */
	public function test_a_submission_needs_a_description(): void {
		$checked = Validate::submission( $this->sent( array( 'description' => '' ) ) );

		$this->assertArrayHasKey( 'description', $checked['errors'] );
	}

	/**
	 * The outcome and the evidence are optional. A client who knows what they
	 * want but not what good looks like should still be able to ask.
	 */
	public function test_the_outcome_and_evidence_are_optional(): void {
		$checked = Validate::submission(
			$this->sent( array( 'desired_outcome' => '', 'evidence' => '' ) )
		);

		$this->assertSame( array(), $checked['errors'] );
	}

	/**
	 * A title longer than the column is refused rather than truncated. A
	 * silently shortened request is one the client and the studio remember
	 * differently.
	 */
	public function test_an_over_long_title_is_refused_rather_than_cut(): void {
		$checked = Validate::submission(
			$this->sent( array( 'title' => str_repeat( 'a', Validate::MAX_TITLE + 1 ) ) )
		);

		$this->assertArrayHasKey( 'title', $checked['errors'] );
	}

	// -----------------------------------------------------------------------
	// What a client may not decide.
	// -----------------------------------------------------------------------

	/**
	 * The intake state is the studio's answer, never part of the question. A
	 * client who could send one could file their own request as accepted.
	 */
	public function test_a_client_cannot_set_the_intake_state(): void {
		$checked = Validate::submission( $this->sent( array( 'intake_state' => 'accepted' ) ) );

		$this->assertArrayHasKey( 'intake_state', $checked['errors'] );
	}

	/**
	 * Nor the response, for the same reason.
	 */
	public function test_a_client_cannot_write_the_response(): void {
		$checked = Validate::submission( $this->sent( array( 'response' => 'Yes, of course.' ) ) );

		$this->assertArrayHasKey( 'response', $checked['errors'] );
	}

	/**
	 * Nor which work it became. Conversion is the studio's act (REQ-1), and a
	 * link a client could set is a link into somebody else's pipeline.
	 */
	public function test_a_client_cannot_link_the_submission_to_work(): void {
		$checked = Validate::submission( $this->sent( array( 'converted_item_id' => 'wrk_1' ) ) );

		$this->assertArrayHasKey( 'converted_item_id', $checked['errors'] );
	}

	/**
	 * And nothing a client sends names which client they are. The site is the
	 * one that signed the request; a client_id in the body would be whatever
	 * they typed (D-2).
	 */
	public function test_a_client_cannot_name_the_client_the_submission_is_for(): void {
		$checked = Validate::submission(
			$this->sent( array( 'client_id' => 'cli_somebody_else', 'client_site_id' => 'cst_theirs' ) )
		);

		$this->assertArrayHasKey( 'client_id', $checked['errors'] );
		$this->assertArrayHasKey( 'client_site_id', $checked['errors'] );
	}
}
