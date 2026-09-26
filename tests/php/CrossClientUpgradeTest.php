<?php
/**
 * Tests for retiring the cross-client grant.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\CrossClientUpgrade;
use Blueworx\Forge\Tenancy\Roles;
use PHPUnit\Framework\TestCase;

/**
 * #405. Everyone holding the grant is put on every client first, so nobody
 * loses access, and then the grant goes.
 */
final class CrossClientUpgradeTest extends TestCase {

	/**
	 * A person.
	 *
	 * @param string $id     Id.
	 * @param string $grants Grants column.
	 * @param string $status Status.
	 * @return array<string, mixed>
	 */
	private function person( string $id, string $grants = 'cross_client', string $status = 'active' ): array {
		return array(
			'id'     => $id,
			'grants' => $grants,
			'status' => $status,
		);
	}

	/**
	 * A membership row.
	 *
	 * @param string $id        Id.
	 * @param string $user_id   Person.
	 * @param string $client_id Client.
	 * @param string $role      Role.
	 * @param string $status    Status.
	 * @param string $site_id   Site, or ''.
	 * @return array<string, mixed>
	 */
	private function membership( string $id, string $user_id, string $client_id, string $role = Roles::STAFF, string $status = 'active', string $site_id = '' ): array {
		return array(
			'id'             => $id,
			'user_id'        => $user_id,
			'client_id'      => $client_id,
			'client_site_id' => $site_id,
			'role'           => $role,
			'status'         => $status,
			'record_version' => 3,
		);
	}

	public function test_a_holder_is_put_on_every_client_they_are_not_already_on(): void {
		$plan = CrossClientUpgrade::plan(
			array( $this->person( 'usr_a' ) ),
			array( $this->membership( 'mem_1', 'usr_a', 'cli_1' ) ),
			array( 'cli_1', 'cli_2', 'cli_3' )
		);

		$this->assertSame(
			array(
				array(
					'user_id'   => 'usr_a',
					'client_id' => 'cli_2',
				),
				array(
					'user_id'   => 'usr_a',
					'client_id' => 'cli_3',
				),
			),
			$plan['create']
		);
		$this->assertSame( array(), $plan['reactivate'] );
		$this->assertSame( array( 'usr_a' ), $plan['clear'] );
	}

	public function test_an_ended_membership_there_is_brought_back_rather_than_duplicated(): void {
		$plan = CrossClientUpgrade::plan(
			array( $this->person( 'usr_a' ) ),
			array( $this->membership( 'mem_1', 'usr_a', 'cli_1', Roles::STAFF, 'inactive' ) ),
			array( 'cli_1' )
		);

		$this->assertSame( array(), $plan['create'] );
		$this->assertSame( array( 'mem_1' => 3 ), $plan['reactivate'] );
	}

	public function test_a_site_only_membership_still_gets_the_whole_client(): void {
		$plan = CrossClientUpgrade::plan(
			array( $this->person( 'usr_a' ) ),
			array( $this->membership( 'mem_1', 'usr_a', 'cli_1', Roles::STAFF, 'active', 'cst_1' ) ),
			array( 'cli_1' )
		);

		$this->assertCount( 1, $plan['create'] );
	}

	public function test_only_holders_are_touched(): void {
		$plan = CrossClientUpgrade::plan(
			array( $this->person( 'usr_a', 'principal' ), $this->person( 'usr_b', '' ) ),
			array(),
			array( 'cli_1' )
		);

		$this->assertSame( array(), $plan['create'] );
		$this->assertSame( array(), $plan['clear'] );
	}

	/**
	 * The grant meant nothing to a client's own person, so it gives them
	 * nothing now; and nobody offboarded is given access. Both lose the grant.
	 */
	public function test_a_clients_person_and_somebody_offboarded_only_lose_the_grant(): void {
		$plan = CrossClientUpgrade::plan(
			array( $this->person( 'usr_c' ), $this->person( 'usr_gone', 'cross_client', 'inactive' ) ),
			array( $this->membership( 'mem_1', 'usr_c', 'cli_1', Roles::CLIENT_ADMIN ) ),
			array( 'cli_1', 'cli_2' )
		);

		$this->assertSame( array(), $plan['create'] );
		$this->assertSame( array(), $plan['reactivate'] );
		$this->assertSame( array( 'usr_c', 'usr_gone' ), $plan['clear'] );
	}

	public function test_the_grant_is_taken_out_of_the_column(): void {
		$this->assertSame( '', CrossClientUpgrade::without_grant( 'cross_client' ) );
		$this->assertSame( 'principal', CrossClientUpgrade::without_grant( 'principal, cross_client' ) );
	}
}
