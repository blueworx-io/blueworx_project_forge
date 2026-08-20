<?php
/**
 * Tests for the grants a person can hold on top of a role.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\Grants;
use PHPUnit\Framework\TestCase;

/**
 * A grant is an extra capability held by somebody who already has a role. The
 * list is closed for the same reason the role list is: an unrecognised string
 * in the column must grant nothing at all rather than be stored, ignored, and
 * one day interpreted.
 */
final class TenancyGrantsTest extends TestCase {

	/**
	 * The stored form is a comma list, and reading it back gives the grants.
	 */
	public function test_a_stored_list_reads_back_as_its_grants(): void {
		$this->assertSame(
			array( Grants::PRINCIPAL, Grants::APPROVER ),
			Grants::parse( 'principal,approver' )
		);
	}

	/**
	 * Whitespace is somebody typing, not a different grant.
	 */
	public function test_spacing_around_a_grant_does_not_change_it(): void {
		$this->assertSame( array( Grants::PRINCIPAL ), Grants::parse( '  principal , ' ) );
	}

	/**
	 * The closed list. A string nobody defined is not a grant, and reading it
	 * back must not produce one — otherwise a hand-edited row invents authority.
	 */
	public function test_an_unrecognised_grant_is_not_a_grant(): void {
		$this->assertSame( array(), Grants::parse( 'superuser' ) );
	}

	/**
	 * Held is asked one grant at a time, because that is how every caller uses
	 * it: this person, this authority, right now.
	 */
	public function test_holding_is_asked_one_grant_at_a_time(): void {
		$this->assertTrue( Grants::held( 'principal,approver', Grants::APPROVER ) );
		$this->assertFalse( Grants::held( 'principal', Grants::APPROVER ) );
	}

	/**
	 * Nothing held is the default, and it is the safe direction: the grants are
	 * all permissions to do more, never permissions to do less.
	 */
	public function test_an_empty_column_holds_nothing(): void {
		$this->assertSame( array(), Grants::parse( '' ) );
		$this->assertFalse( Grants::held( '', Grants::PRINCIPAL ) );
	}

	/**
	 * Writing a list back drops what is not a grant, so an unrecognised value
	 * cannot be stored and then found later by something that trusts the column.
	 */
	public function test_storing_a_list_keeps_only_real_grants(): void {
		$this->assertSame( 'principal', Grants::format( array( 'principal', 'superuser' ) ) );
	}

	/**
	 * One grant, once. A duplicated value is the same authority written twice.
	 */
	public function test_a_grant_is_stored_once(): void {
		$this->assertSame( 'approver', Grants::format( array( 'approver', 'approver' ) ) );
	}

	/**
	 * Every grant reads as something, so a screen never shows a raw key.
	 */
	public function test_every_grant_has_a_label(): void {
		foreach ( Grants::ALL as $grant ) {
			$this->assertNotSame( '', Grants::label( $grant ) );
		}
	}

	/**
	 * The three grants, named. Cross-client is here rather than being a role
	 * because it does not change what somebody may do — only how far it reaches.
	 */
	public function test_the_grants_are_the_three_the_matrix_names(): void {
		$this->assertSame(
			array( 'principal', 'approver', 'cross_client' ),
			Grants::ALL
		);
	}
}
