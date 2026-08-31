<?php
/**
 * What a client-facing email says.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Notifications\Events;
use Blueworx\Forge\Notifications\Templates;
use PHPUnit\Framework\TestCase;

/**
 * NOTIF-2 and NOTIF-3. Three templates, and the difference between the last two.
 *
 * The distinction under test is the one the decision was made about: telling a
 * client their work is done before it is live is the message that generates the
 * support ticket. So the Completed email has to say ready, and only the
 * Released one may say it is finished — and that has to hold in the subject as
 * well as the body, because the subject is the part most people read.
 */
final class NotificationTemplatesTest extends TestCase {

	/**
	 * The context an email is rendered from.
	 *
	 * @param array<string, mixed> $overrides Anything else.
	 * @return array<string, mixed>
	 */
	private function about( array $overrides = array() ): array {
		return array_merge(
			array(
				'title'       => 'The checkout page',
				'client_name' => 'Acme',
				'reference'   => 'wrk_abc123',
			),
			$overrides
		);
	}

	/* -------------------------------------------------- the three, and no more */

	public function test_each_of_the_three_says_something(): void {
		foreach ( Events::ALL as $kind ) {
			$email = Templates::render( $kind, $this->about() );

			$this->assertNotSame( '', $email['subject'], $kind . ' needs a subject' );
			$this->assertNotSame( '', $email['body'], $kind . ' needs a body' );
			$this->assertStringContainsString( 'The checkout page', $email['subject'] );
		}
	}

	public function test_an_event_nobody_defined_says_nothing(): void {
		// Rather than an empty email addressed to a real client.
		$email = Templates::render( 'work-nearly-done', $this->about() );

		$this->assertSame( '', $email['subject'] );
		$this->assertSame( '', $email['body'] );
	}

	/* -------------------------------------------------------- ready is not done */

	public function test_ready_does_not_say_it_is_live(): void {
		$email = Templates::render( Events::COMPLETED, $this->about() );

		$this->assertStringContainsString( 'Ready to go live', $email['subject'] );
		$this->assertStringContainsString( 'not live yet', $email['body'] );
		$this->assertStringNotContainsString( 'Now live', $email['subject'] );
	}

	public function test_live_says_it_is_live(): void {
		$email = Templates::render( Events::RELEASED, $this->about() );

		$this->assertStringContainsString( 'Now live', $email['subject'] );
		$this->assertStringContainsString( 'This is live.', $email['body'] );
	}

	public function test_the_two_are_never_the_same_email(): void {
		$ready = Templates::render( Events::COMPLETED, $this->about() );
		$live  = Templates::render( Events::RELEASED, $this->about() );

		$this->assertNotSame( $ready['subject'], $live['subject'] );
		$this->assertNotSame( $ready['body'], $live['body'] );
	}

	public function test_a_release_names_where_it_went_when_it_knows(): void {
		$email = Templates::render( Events::RELEASED, $this->about( array( 'destination' => 'acme.example' ) ) );

		$this->assertStringContainsString( 'acme.example', $email['body'] );
	}

	public function test_a_release_that_does_not_know_says_nothing_about_it(): void {
		// Rather than "Where:" followed by a blank, which reads as a fault.
		$email = Templates::render( Events::RELEASED, $this->about() );

		$this->assertStringNotContainsString( 'Where:', $email['body'] );
	}

	/* ----------------------------------------------------- the reference, always */

	public function test_every_email_carries_the_reference(): void {
		// The first thing anybody does with a confusing email is forward it and
		// ask what it is about.
		foreach ( Events::ALL as $kind ) {
			$this->assertStringContainsString(
				'Reference: wrk_abc123',
				Templates::render( $kind, $this->about() )['body'],
				$kind
			);
		}
	}

	public function test_an_email_with_no_reference_does_not_say_reference_blank(): void {
		$body = Templates::render( Events::RELEASED, $this->about( array( 'reference' => '' ) ) )['body'];

		$this->assertStringNotContainsString( 'Reference:', $body );
		$this->assertSame( rtrim( $body ), $body );
	}

	/* ------------------------------------------------------- nothing injectable */

	public function test_a_title_cannot_add_a_header(): void {
		/*
		 * A newline in a subject is how a second header is added to an email.
		 * A work item's title is typed by a person and reaches this unchanged,
		 * so this is where it stops.
		 */
		$email = Templates::render(
			Events::RELEASED,
			$this->about( array( 'title' => "Fine\r\nBcc: elsewhere@example.test" ) )
		);

		$this->assertStringNotContainsString( "\n", $email['subject'] );
		$this->assertStringNotContainsString( "\r", $email['subject'] );
		$this->assertStringContainsString( 'Bcc', $email['subject'] );
	}

	public function test_a_title_cannot_add_a_line_to_the_body(): void {
		$email = Templates::render(
			Events::RELEASED,
			$this->about( array( 'title' => "Fine\nActually, ignore the above." ) )
		);

		// Folded onto the line it belongs to, rather than becoming a sentence of
		// its own that reads as something we wrote.
		$this->assertStringContainsString( 'What went out: Fine Actually, ignore the above.', $email['body'] );

		foreach ( explode( "\n", $email['body'] ) as $line ) {
			$this->assertFalse( str_starts_with( $line, 'Actually' ), 'a title started a line of its own' );
		}
	}

	public function test_a_very_long_title_does_not_push_the_subject_out_of_view(): void {
		$email = Templates::render( Events::RELEASED, $this->about( array( 'title' => str_repeat( 'a', 400 ) ) ) );

		$this->assertLessThanOrEqual( Templates::MAX_SUBJECT, mb_strlen( $email['subject'] ) );
		$this->assertStringStartsWith( 'Now live: ', $email['subject'] );
	}
}
