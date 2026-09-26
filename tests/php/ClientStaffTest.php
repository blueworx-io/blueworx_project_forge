<?php
/**
 * Tests for choosing which staff work on a client.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\ClientStaff;
use Blueworx\Forge\Tenancy\Roles;
use PHPUnit\Framework\TestCase;

/**
 * #405. The client's chosen staff are the staff memberships on it, the same
 * rows the People page edits, so the pick list writes those rows.
 */
final class ClientStaffTest extends TestCase {

	/**
	 * A membership on the client.
	 *
	 * @param string $id      Id.
	 * @param string $user_id Person.
	 * @param string $role    Role.
	 * @param string $status  Status.
	 * @param string $site_id Site, or ''.
	 * @return array<string, mixed>
	 */
	private function membership( string $id, string $user_id, string $role = Roles::STAFF, string $status = 'active', string $site_id = '' ): array {
		return array(
			'id'             => $id,
			'user_id'        => $user_id,
			'client_id'      => 'cli_1',
			'client_site_id' => $site_id,
			'role'           => $role,
			'status'         => $status,
			'record_version' => 2,
		);
	}

	public function test_the_chosen_are_the_active_studio_memberships(): void {
		$held = array(
			$this->membership( 'mem_1', 'usr_a' ),
			$this->membership( 'mem_2', 'usr_b', Roles::INTERNAL_VIEWER, 'active', 'cst_1' ),
			$this->membership( 'mem_3', 'usr_c', Roles::STAFF, 'inactive' ),
			$this->membership( 'mem_4', 'usr_d', Roles::CLIENT_ADMIN ),
		);

		$this->assertSame( array( 'usr_a', 'usr_b' ), ClientStaff::chosen( $held ) );
	}

	public function test_picking_somebody_new_adds_them_and_unpicking_ends_them(): void {
		$held = array(
			$this->membership( 'mem_1', 'usr_a' ),
			$this->membership( 'mem_2', 'usr_b', Roles::STAFF, 'active', 'cst_1' ),
			$this->membership( 'mem_4', 'usr_d', Roles::CLIENT_ADMIN ),
		);

		$changes = ClientStaff::changes( array( 'usr_a', 'usr_new' ), $held );

		$this->assertSame( array( 'usr_new' ), $changes['add'] );
		$this->assertSame( array(), $changes['reactivate'] );
		$this->assertSame( array( 'mem_2' => 2 ), $changes['end'] );
	}

	public function test_somebody_whose_access_ended_is_brought_back_on_their_old_row(): void {
		$changes = ClientStaff::changes( array( 'usr_c' ), array( $this->membership( 'mem_3', 'usr_c', Roles::STAFF, 'inactive' ) ) );

		$this->assertSame( array(), $changes['add'] );
		$this->assertSame( array( 'mem_3' => 2 ), $changes['reactivate'] );
	}

	/**
	 * A client's own person holding the whole-client row is not staff there,
	 * and cannot be made staff by this list.
	 */
	public function test_a_clients_person_on_the_client_is_refused(): void {
		$changes = ClientStaff::changes( array( 'usr_d' ), array( $this->membership( 'mem_4', 'usr_d', Roles::CLIENT_ADMIN ) ) );

		$this->assertSame( array( 'usr_d' ), $changes['refused'] );
		$this->assertSame( array(), $changes['add'] );
		$this->assertSame( array(), $changes['end'] );
	}

	public function test_nothing_changes_when_the_list_is_as_it_stands(): void {
		$changes = ClientStaff::changes( array( 'usr_a' ), array( $this->membership( 'mem_1', 'usr_a' ) ) );

		$this->assertSame(
			array(
				'add'        => array(),
				'reactivate' => array(),
				'end'        => array(),
				'refused'    => array(),
			),
			$changes
		);
	}
}
