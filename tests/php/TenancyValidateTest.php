<?php
/**
 * Client and client site input rules.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\Validate;
use PHPUnit\Framework\TestCase;

/**
 * Validation is separated from storage so the rules can be proven without a
 * database — and so both doors into the data, REST and the admin screen, get
 * the same answer to the same input.
 */
final class TenancyValidateTest extends TestCase {

	/**
	 * The happy path, with the values cleaned.
	 */
	public function test_a_complete_client_is_accepted_and_trimmed(): void {
		$result = Validate::client(
			array(
				'display_name'  => '  Acme  ',
				'legal_name'    => 'Acme Limited',
				'timezone'      => 'Europe/London',
				'email_domains' => array( 'ACME.co.uk', 'acme.co.uk' ),
			),
			false
		);

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 'Acme', $result['values']['display_name'] );
		$this->assertSame( 'Europe/London', $result['values']['timezone'] );
		// Lower-cased and de-duplicated: two spellings of one domain are one rule.
		$this->assertSame( array( 'acme.co.uk' ), $result['values']['email_domains'] );
		$this->assertSame( 'active', $result['values']['status'] );
	}

	/**
	 * A client with no name is refused: it is the only thing the studio has to
	 * call them by.
	 */
	public function test_a_client_without_a_display_name_is_refused(): void {
		$result = Validate::client( array( 'display_name' => '   ' ), false );

		$this->assertArrayHasKey( 'display_name', $result['errors'] );
	}

	/**
	 * An invented timezone is refused rather than stored and puzzled over later.
	 */
	public function test_an_unknown_timezone_is_refused(): void {
		$result = Validate::client(
			array(
				'display_name' => 'Acme',
				'timezone'     => 'Middle/Earth',
			),
			false
		);

		$this->assertArrayHasKey( 'timezone', $result['errors'] );
	}

	/**
	 * Permitted domains are domains. An email address in the field is the
	 * mistake this catches.
	 */
	public function test_a_malformed_email_domain_is_refused(): void {
		$result = Validate::client(
			array(
				'display_name'  => 'Acme',
				'email_domains' => array( 'someone@acme.co.uk' ),
			),
			false
		);

		$this->assertArrayHasKey( 'email_domains', $result['errors'] );
	}

	/**
	 * Status is a closed list. Deletion is not one of its values (NOTIF-5).
	 */
	public function test_an_unknown_status_is_refused(): void {
		$result = Validate::client(
			array(
				'display_name' => 'Acme',
				'status'       => 'deleted',
			),
			false
		);

		$this->assertArrayHasKey( 'status', $result['errors'] );
	}

	/**
	 * A partial edit says nothing about the fields it does not mention, so a
	 * required field missing from a PATCH is not an error.
	 */
	public function test_a_partial_client_edit_may_omit_everything(): void {
		$result = Validate::client( array( 'legal_name' => 'Acme Limited' ), true );

		$this->assertSame( array(), $result['errors'] );
		$this->assertArrayNotHasKey( 'display_name', $result['values'] );
	}

	/**
	 * A site needs a name and, if given, a real URL.
	 */
	public function test_a_site_needs_a_name_and_a_real_url(): void {
		$named = Validate::site( array( 'name' => 'Acme Main' ), false );
		$this->assertSame( array(), $named['errors'] );

		$unnamed = Validate::site( array( 'name' => '' ), false );
		$this->assertArrayHasKey( 'name', $unnamed['errors'] );

		$bad_url = Validate::site(
			array(
				'name' => 'Acme Main',
				'url'  => 'not a url',
			),
			false
		);
		$this->assertArrayHasKey( 'url', $bad_url['errors'] );
	}
}
