<?php
/**
 * The client site's read-through view of what it has asked for.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

use Blueworx\Forge\Client\Cache;
use Blueworx\Forge\Client\Connection;
use Blueworx\Forge\Client\Submission;
use Blueworx\Forge\Client\Submissions;
use Blueworx\Forge\Client\Sync;
use PHPUnit\Framework\TestCase;

/**
 * #130. What a client asked for, and what became of it.
 *
 * The same read-through rules as the board (ARCH-2, ARCH-4), so most of what
 * matters here is that this record does not quietly get its own weaker version
 * of them. An unreachable studio must say it cannot see, never that nothing was
 * ever asked — telling somebody their request has vanished is worse than
 * telling them the connection is down.
 *
 * The one thing this record has that no other does is a write on the same
 * route. Sending one has to show up on this screen straight away, because the
 * person who just pressed send is about to look for it.
 */
final class ClientSubmissionStatusTest extends TestCase {

	/**
	 * Clears every store and connects the site.
	 */
	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_options']       = array();
		$GLOBALS['bwx_forge_test_http']          = array();
		$GLOBALS['bwx_forge_test_http_requests'] = array();
		$GLOBALS['bwx_forge_client_test_now']    = 2000000;

		Connection::store( 'https://studio.example', 'site_abc', 'key-1' );
	}

	/**
	 * Queues an answer from the studio.
	 *
	 * @param array<int, array<string, mixed>> $submissions What comes back.
	 */
	private function studio_answers( array $submissions = array() ): void {
		$GLOBALS['bwx_forge_test_http'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode(
				array(
					'ok'          => true,
					'generated'   => 5000,
					'states'      => array(
						array( 'slug' => 'received', 'label' => 'Received' ),
						array( 'slug' => 'converted', 'label' => 'Became work' ),
					),
					'submissions' => array() === $submissions ? array( $this->submission() ) : $submissions,
					'contact'     => array( 'display_name' => 'Ana Fielding' ),
				)
			),
		);
	}

	/**
	 * One submission, in the shape the studio's projection sends.
	 *
	 * @param array<string, mixed> $also Keys to add or override.
	 * @return array<string, mixed>
	 */
	private function submission( array $also = array() ): array {
		return array_merge(
			array(
				'id'              => 'sub_1',
				'type'            => 'request',
				'title'           => 'A booking form that takes deposits',
				'description'     => 'People ring up to pay.',
				'desired_outcome' => 'They pay on the site.',
				'evidence'        => '',
				'submitted_by'    => 'Priya Shah',
				'intake_state'    => 'received',
				'intake_label'    => 'Received',
				'response'        => '',
				'converted'       => array(),
				'created_at'      => 1999000,
				'updated_at'      => 1999000,
			),
			$also
		);
	}

	/**
	 * Queues the studio being unreachable.
	 */
	private function studio_is_down(): void {
		$GLOBALS['bwx_forge_test_http'][] = new WP_Error( 'http_request_failed', 'Connection refused.' );
	}

	/**
	 * Moves the client site's clock on.
	 *
	 * @param int $seconds Seconds to advance.
	 */
	private function wait( int $seconds ): void {
		$GLOBALS['bwx_forge_client_test_now'] += $seconds;
	}

	// -----------------------------------------------------------------------
	// The ordinary cases.
	// -----------------------------------------------------------------------

	/**
	 * The submissions come from the studio, and the site shows them.
	 */
	public function test_submissions_are_read_through_and_shown(): void {
		$this->studio_answers();

		$view = Submissions::view();

		$this->assertTrue( $view['ok'] );
		$this->assertSame( Sync::STATE_LIVE, $view['sync']['state'] );
		$this->assertSame( 'A booking form that takes deposits', $view['submissions'][0]['title'] );
	}

	/**
	 * The status words arrive with the record rather than being invented here.
	 */
	public function test_the_state_names_come_from_the_studio(): void {
		$this->studio_answers();

		$view = Submissions::view();

		$this->assertSame( 'Received', $view['submissions'][0]['intake_label'] );
		$this->assertSame( array( 'received', 'converted' ), array_column( $view['states'], 'slug' ) );
	}

	/**
	 * The contact travels with it, so the screen can name somebody to chase.
	 */
	public function test_the_point_of_contact_comes_with_the_answer(): void {
		$this->studio_answers();

		$this->assertSame( 'Ana Fielding', Submissions::view()['contact']['display_name'] );
	}

	/**
	 * A client who has asked for nothing gets an empty list from a studio that
	 * answered — which is a different thing from a studio that did not.
	 */
	public function test_having_asked_for_nothing_is_an_answer_not_a_failure(): void {
		$GLOBALS['bwx_forge_test_http'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode(
				array(
					'ok'          => true,
					'generated'   => 5000,
					'states'      => array(),
					'submissions' => array(),
					'contact'     => array(),
				)
			),
		);

		$view = Submissions::view();

		$this->assertTrue( $view['ok'] );
		$this->assertSame( array(), $view['submissions'] );
	}

	// -----------------------------------------------------------------------
	// When the studio cannot be reached.
	// -----------------------------------------------------------------------

	/**
	 * The one that matters. Nothing to show is not the same as nothing asked
	 * for, and this record must not collapse the two.
	 */
	public function test_an_unreachable_studio_is_not_an_empty_list(): void {
		$this->studio_is_down();

		$view = Submissions::view();

		$this->assertFalse( $view['ok'] );
		$this->assertSame( Sync::STATE_UNREACHABLE, $view['sync']['state'] );
	}

	/**
	 * An older copy is better than nothing, as long as it is shown as old.
	 */
	public function test_an_older_copy_is_served_and_said_to_be_old(): void {
		$this->studio_answers();
		Submissions::view();

		$this->wait( Cache::MAX_AGE + 1 );
		$this->studio_is_down();

		$view = Submissions::view();

		$this->assertTrue( $view['ok'] );
		$this->assertSame( Sync::STATE_STALE, $view['sync']['state'] );
		$this->assertSame( 'A booking form that takes deposits', $view['submissions'][0]['title'] );
	}

	/**
	 * A site nobody has connected says so, rather than reporting a network
	 * problem it never had.
	 */
	public function test_an_unconnected_site_says_it_is_not_connected(): void {
		$GLOBALS['bwx_forge_test_options'] = array();

		$view = Submissions::view();

		$this->assertFalse( $view['ok'] );
		$this->assertSame( Sync::STATE_NOT_CONFIGURED, $view['sync']['state'] );
	}

	// -----------------------------------------------------------------------
	// The receipt.
	// -----------------------------------------------------------------------

	/**
	 * Sending one throws away the cached list, so the person who just pressed
	 * send sees their request rather than the list as it was a minute ago.
	 */
	public function test_sending_one_makes_the_list_ask_the_studio_again(): void {
		$this->studio_answers( array( $this->submission() ) );
		Submissions::view();

		// The send itself, answered.
		$GLOBALS['bwx_forge_test_http'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode(
				array(
					'ok'         => true,
					'submission' => array( 'id' => 'sub_2', 'title' => 'A second thought' ),
				)
			),
		);

		$sent = Submission::send(
			array(
				'type'        => 'request',
				'title'       => 'A second thought',
				'description' => 'Something else.',
			)
		);

		$this->assertTrue( $sent['ok'] );

		// Still inside the cache window: without the send having cleared it,
		// this would answer from the copy holding one submission.
		$this->studio_answers(
			array( $this->submission(), $this->submission( array( 'id' => 'sub_2', 'title' => 'A second thought' ) ) )
		);

		$view = Submissions::view();

		$this->assertSame( array( 'sub_1', 'sub_2' ), array_column( $view['submissions'], 'id' ) );
	}

	/**
	 * A send that failed leaves the cached list alone. Throwing it away would
	 * cost a network round trip to be told exactly what the site already knew.
	 */
	public function test_a_failed_send_leaves_the_list_alone(): void {
		$this->studio_answers();
		Submissions::view();

		$this->studio_is_down();
		$sent = Submission::send( array( 'type' => 'request', 'title' => 'Never arrives' ) );

		$this->assertFalse( $sent['ok'] );
		$this->assertNotNull( Cache::get( Submissions::ROUTE ) );
	}
}
