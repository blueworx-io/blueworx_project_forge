<?php
/**
 * The rules a user and a membership have to satisfy.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\Roles;
use Blueworx\Forge\Tenancy\Validate;
use PHPUnit\Framework\TestCase;

/**
 * AUTH-6 makes one person one account across every client, and the thing that
 * makes that true rather than merely intended is the email rule below plus the
 * unique index behind it. The role rule matters for the same reason in reverse:
 * #91 turns a role into capabilities, and a role it has never heard of would
 * either grant nothing silently or fall through to a default.
 */
final class ValidateIdentityTest extends TestCase {

	/**
	 * An ordinary person.
	 */
	public function test_a_plain_user_is_accepted(): void {
		$checked = Validate::user(
			array(
				'email'        => 'Sam.Patel@Acme.co.uk',
				'display_name' => 'Sam Patel',
			),
			false
		);

		$this->assertSame( array(), $checked['errors'] );

		// Lower-cased, because two spellings of one address are one person and
		// the unique index only sees them as one if they are stored the same.
		$this->assertSame( 'sam.patel@acme.co.uk', $checked['values']['email'] );
	}

	/**
	 * An address is required and has to be one.
	 */
	public function test_a_user_without_a_usable_email_is_refused(): void {
		foreach ( array( '', 'not-an-address', 'sam@' ) as $email ) {
			$checked = Validate::user( array( 'email' => $email, 'display_name' => 'Sam' ), false );

			$this->assertArrayHasKey( 'email', $checked['errors'], "'{$email}' should not be usable" );
		}
	}

	/**
	 * A person needs a name to appear as anywhere.
	 */
	public function test_a_user_needs_a_name(): void {
		$checked = Validate::user( array( 'email' => 'sam@acme.co.uk' ), false );

		$this->assertArrayHasKey( 'display_name', $checked['errors'] );
	}

	/**
	 * An edit may mention only what it changes.
	 */
	public function test_an_edit_need_not_repeat_every_field(): void {
		$checked = Validate::user( array( 'display_name' => 'Sam Patel-Jones' ), true );

		$this->assertSame( array(), $checked['errors'] );
		$this->assertSame( array( 'display_name' => 'Sam Patel-Jones' ), $checked['values'] );
	}

	/**
	 * Every role in the permission matrix is one this accepts.
	 */
	public function test_every_role_in_the_matrix_is_a_role(): void {
		foreach ( Roles::ALL as $role ) {
			$checked = Validate::membership( array( 'role' => $role ), false );

			$this->assertArrayNotHasKey( 'role', $checked['errors'], "{$role} should be a role" );
		}
	}

	/**
	 * A role nobody has defined is refused rather than stored and puzzled over
	 * later.
	 */
	public function test_an_invented_role_is_refused(): void {
		$checked = Validate::membership( array( 'role' => 'superuser' ), false );

		$this->assertArrayHasKey( 'role', $checked['errors'] );
	}

	/**
	 * A membership with no site named covers every site under the client. That
	 * is a real state, not a missing field.
	 */
	public function test_a_membership_may_cover_the_whole_client(): void {
		$checked = Validate::membership( array( 'role' => 'client_admin' ), false );

		$this->assertSame( array(), $checked['errors'] );
		$this->assertSame( '', $checked['values']['client_site_id'] );
	}

	/**
	 * Studio roles and client roles are told apart, because the domain rule and
	 * everything #91 does with internal notes turns on which side somebody is.
	 */
	public function test_the_roles_know_which_side_they_are_on(): void {
		$this->assertTrue( Roles::is_client_side( 'client_admin' ) );
		$this->assertTrue( Roles::is_client_side( 'client_viewer' ) );

		$this->assertFalse( Roles::is_client_side( 'staff' ) );
		$this->assertFalse( Roles::is_client_side( 'primary_admin' ) );

		// AUTH-5 splits viewer by side deliberately: an internal viewer sees
		// internal notes and a client viewer never does.
		$this->assertFalse( Roles::is_client_side( 'internal_viewer' ) );
	}

	/**
	 * A client that lists permitted domains means it: one of its own people must
	 * have an address in one of them. #88 stored that list for exactly this.
	 */
	public function test_a_client_person_must_use_a_permitted_domain(): void {
		$this->assertNull( Validate::domain_error( 'sam@acme.co.uk', 'client_admin', array( 'acme.co.uk' ) ) );
		$this->assertNotNull( Validate::domain_error( 'sam@elsewhere.com', 'client_admin', array( 'acme.co.uk' ) ) );
	}

	/**
	 * Our own people are exempt. The client's list is about the client's people,
	 * and a staff membership on that client is one of ours.
	 */
	public function test_studio_people_are_not_held_to_the_client_domain_list(): void {
		$this->assertNull( Validate::domain_error( 'luke@blueworx.io', 'staff', array( 'acme.co.uk' ) ) );
		$this->assertNull( Validate::domain_error( 'luke@blueworx.io', 'primary_admin', array( 'acme.co.uk' ) ) );
	}

	/**
	 * A client with no list has not asked for the rule, so there is no rule.
	 */
	public function test_a_client_with_no_domain_list_accepts_any_address(): void {
		$this->assertNull( Validate::domain_error( 'sam@anywhere.test', 'client_admin', array() ) );
	}

	/**
	 * A subdomain of a permitted domain is not the permitted domain. Accepting
	 * it would let anybody who can create a hostname under a lookalike domain
	 * in, which is the kind of rule that is only worth having if it is exact.
	 */
	public function test_a_lookalike_domain_does_not_pass(): void {
		$this->assertNotNull( Validate::domain_error( 'sam@mail.acme.co.uk', 'client_admin', array( 'acme.co.uk' ) ) );
		$this->assertNotNull( Validate::domain_error( 'sam@notacme.co.uk', 'client_admin', array( 'acme.co.uk' ) ) );
	}
}
