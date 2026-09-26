<?php
/**
 * Tests for whether one named person reaches a site.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\PersonReach;
use Blueworx\Forge\Tenancy\Roles;
use PHPUnit\Framework\TestCase;

/**
 * #393. The reminders asked this of each person they named; the seats on a
 * task now ask it too, and only our side of a membership counts for a seat.
 */
final class PersonReachTest extends TestCase {

	/**
	 * A membership row.
	 *
	 * @param string $client_id Client.
	 * @param string $role      Role held.
	 * @param string $site_id   Site, or '' for every site.
	 * @return array<string, mixed>
	 */
	private function membership( string $client_id, string $role = Roles::STAFF, string $site_id = '' ): array {
		return array(
			'client_id'      => $client_id,
			'client_site_id' => $site_id,
			'role'           => $role,
			'status'         => 'active',
		);
	}

	/**
	 * A person.
	 *
	 * @param string $status Status.
	 * @param string $grants Grants.
	 * @return array<string, mixed>
	 */
	private function person( string $status = 'active', string $grants = '' ): array {
		return array(
			'id'           => 'usr_a',
			'display_name' => 'Ann',
			'status'       => $status,
			'grants'       => $grants,
		);
	}

	public function test_a_staff_membership_on_the_client_reaches_its_sites(): void {
		$this->assertTrue( PersonReach::reaches( $this->person(), array( $this->membership( 'cli_a' ) ), false, 'cli_a', 'cst_1', true ) );
	}

	public function test_a_membership_on_another_client_does_not(): void {
		$this->assertFalse( PersonReach::reaches( $this->person(), array( $this->membership( 'cli_b' ) ), false, 'cli_a', 'cst_1', true ) );
	}

	public function test_a_membership_on_another_site_of_the_client_does_not(): void {
		$this->assertFalse( PersonReach::reaches( $this->person(), array( $this->membership( 'cli_a', Roles::STAFF, 'cst_2' ) ), false, 'cli_a', 'cst_1', true ) );
	}

	public function test_a_client_side_membership_reaches_but_not_as_staff(): void {
		$held = array( $this->membership( 'cli_a', Roles::CLIENT_ADMIN ) );

		$this->assertTrue( PersonReach::reaches( $this->person(), $held, false, 'cli_a', 'cst_1' ) );
		$this->assertFalse( PersonReach::reaches( $this->person(), $held, false, 'cli_a', 'cst_1', true ) );
	}

	public function test_the_administrator_reaches_everything(): void {
		$this->assertTrue( PersonReach::reaches( $this->person(), array(), true, 'cli_a', 'cst_1', true ) );
	}

	public function test_studio_staff_reach_an_all_staff_client_without_a_membership(): void {
		$held = array( $this->membership( 'cli_b' ) );

		$this->assertTrue( PersonReach::reaches( $this->person(), $held, false, 'cli_a', 'cst_1', true, array( 'cli_a' ) ) );
		$this->assertTrue( PersonReach::reaches( $this->person(), array(), false, 'cli_a', 'cst_1', true, array( 'cli_a' ) ) );
		$this->assertFalse( PersonReach::reaches( $this->person(), $held, false, 'cli_a', 'cst_1', true ) );
	}

	public function test_a_clients_person_is_never_staff_on_an_all_staff_client(): void {
		$held  = array( $this->membership( 'cli_a', Roles::CLIENT_ADMIN ) );
		$ended = array( array_merge( $this->membership( 'cli_b', Roles::CLIENT_VIEWER ), array( 'status' => 'inactive' ) ) );

		$this->assertFalse( PersonReach::reaches( $this->person(), $held, false, 'cli_a', 'cst_1', true, array( 'cli_a' ) ) );
		$this->assertFalse( PersonReach::reaches( $this->person(), $ended, false, 'cli_a', 'cst_1', true, array( 'cli_a' ) ) );
	}

	public function test_the_retired_cross_client_grant_reaches_nothing(): void {
		$held = array( $this->membership( 'cli_b' ) );

		$this->assertFalse( PersonReach::reaches( $this->person( 'active', 'cross_client' ), $held, false, 'cli_a', 'cst_1', true ) );
	}

	public function test_somebody_inactive_reaches_nothing(): void {
		$this->assertFalse( PersonReach::reaches( $this->person( 'inactive' ), array( $this->membership( 'cli_a' ) ), true, 'cli_a', 'cst_1', true ) );
	}

	public function test_the_refusal_names_the_person(): void {
		$this->assertSame( "Ann doesn't have access to this client.", PersonReach::message( $this->person() ) );
		$this->assertSame( "That person doesn't have access to this client.", PersonReach::message( null ) );
	}

	/**
	 * #409. The Designer is asked about like every other seat: somebody who
	 * cannot reach the site is not left in it.
	 */
	public function test_a_designer_without_access_is_asked_about(): void {
		$this->assertContains( 'designer_id', PersonReach::SEATS );
		$this->assertSame( array( 'designer_id' => 'usr_d' ), PersonReach::changed_seats( array( 'designer_id' => 'usr_d' ), array( 'designer_id' => '' ) ) );

		$kept = PersonReach::drop_unreached(
			array(
				'primary_user_id' => 'usr_in',
				'designer_id'     => 'usr_out',
			),
			'cli_a',
			'cst_1',
			static fn( string $id ): bool => 'usr_in' === $id
		);

		$this->assertSame( 'usr_in', $kept['primary_user_id'] );
		$this->assertSame( '', $kept['designer_id'] );
	}

	public function test_only_seats_the_edit_changes_are_asked_about(): void {
		$item = array(
			'primary_user_id'        => 'usr_old',
			'reviewer_id'            => 'usr_r',
			'deliverer_id'           => '',
			'reviewer_substitute_id' => 'usr_s',
		);

		$changed = PersonReach::changed_seats(
			array(
				'title'                  => 'Renamed',
				'primary_user_id'        => 'usr_old',
				'reviewer_id'            => 'usr_new',
				'deliverer_id'           => '',
				'reviewer_substitute_id' => 'usr_s2',
			),
			$item
		);

		$this->assertSame(
			array(
				'reviewer_id'            => 'usr_new',
				'reviewer_substitute_id' => 'usr_s2',
			),
			$changed
		);
	}
}
