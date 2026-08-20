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
	 * Name length is measured in characters, not bytes — a 191-character name in
	 * a multi-byte script must be accepted, not refused for a byte count that
	 * has nothing to do with what varchar(191) actually stores.
	 */
	public function test_a_multibyte_name_is_measured_in_characters_not_bytes(): void {
		// 191 characters, each three bytes in UTF-8: 573 bytes, well past
		// strlen()'s idea of the limit, but exactly at mb_strlen()'s.
		$name = str_repeat( '本', 191 );

		$result = Validate::client( array( 'display_name' => $name ), false );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( $name, $result['values']['display_name'] );
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

	// -----------------------------------------------------------------------
	// #93. The grants handed out on the people screen.
	// -----------------------------------------------------------------------

	/**
	 * A membership can carry the two grants that are held with one client.
	 */
	public function test_a_membership_may_hold_the_grants_that_belong_to_a_client(): void {
		$checked = Validate::membership(
			array(
				'role'   => 'staff',
				'grants' => array( 'principal', 'approver' ),
			),
			false
		);

		$this->assertSame( array(), $checked['errors'] );
		$this->assertSame( array( 'principal', 'approver' ), $checked['values']['grants'] );
	}

	/**
	 * And not the one that is deliberately not. Cross-client means "not held
	 * with one client", so holding it on a membership would be a contradiction
	 * stored as data — and one that reads as authority to whatever finds it.
	 */
	public function test_a_membership_may_not_hold_the_cross_client_grant(): void {
		$checked = Validate::membership(
			array(
				'role'   => 'staff',
				'grants' => array( 'cross_client' ),
			),
			false
		);

		$this->assertArrayHasKey( 'grants', $checked['errors'] );
	}

	/**
	 * The grants are studio authority. A client administrator holding Approver
	 * would approve their own estimates, which is the whole thing AUTH-1 exists
	 * to prevent.
	 */
	public function test_a_client_role_may_not_hold_a_studio_grant(): void {
		$checked = Validate::membership(
			array(
				'role'   => 'client_admin',
				'grants' => array( 'approver' ),
			),
			false
		);

		$this->assertArrayHasKey( 'grants', $checked['errors'] );
	}

	/**
	 * Holding none is the default and always allowed, including as the way to
	 * take one away.
	 */
	public function test_a_membership_may_hold_no_grant_at_all(): void {
		$checked = Validate::membership(
			array(
				'role'   => 'staff',
				'grants' => array(),
			),
			false
		);

		$this->assertSame( array(), $checked['errors'] );
		$this->assertSame( array(), $checked['values']['grants'] );
	}

	/**
	 * A person can hold the cross-client grant, which is the one that is not
	 * about any single client.
	 */
	public function test_a_person_may_hold_the_cross_client_grant(): void {
		$checked = Validate::user( array( 'grants' => array( 'cross_client' ) ), true );

		$this->assertSame( array(), $checked['errors'] );
		$this->assertSame( array( 'cross_client' ), $checked['values']['grants'] );
	}

	/**
	 * And not the two that are. Principal is held with a client because it
	 * waives a rule about one client's work; held globally it would waive it
	 * everywhere at once.
	 */
	public function test_a_person_may_not_hold_a_grant_that_belongs_to_a_membership(): void {
		$checked = Validate::user( array( 'grants' => array( 'principal' ) ), true );

		$this->assertArrayHasKey( 'grants', $checked['errors'] );
	}

	/**
	 * A grant nobody defined is refused rather than dropped quietly, so somebody
	 * who thought they were granting something is told they were not.
	 */
	public function test_a_grant_nobody_defined_is_refused(): void {
		$checked = Validate::user( array( 'grants' => array( 'superuser' ) ), true );

		$this->assertArrayHasKey( 'grants', $checked['errors'] );
	}
}
