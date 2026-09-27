<?php
/**
 * Tests for how far one person's memberships reach.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\Reach;
use Blueworx\Forge\Tenancy\Roles;
use PHPUnit\Framework\TestCase;

/**
 * Reach is the answer to "which records exist, as far as this person is
 * concerned" — the question #92 makes one enforcement point ask on every read
 * and every write.
 *
 * It is deliberately separate from who is asking. Given a set of memberships
 * and the grants on the user, the answer is a pure function, so the tenancy rule
 * can be read as a rule rather than run to find out what it does.
 */
final class TenantReachTest extends TestCase {

	/**
	 * A membership row, in the shape Memberships::for_user() returns.
	 *
	 * @param string $client_id Client.
	 * @param string $site_id   Site, or '' for every site under the client.
	 * @param string $role      Role held.
	 * @param string $status    Membership status.
	 * @return array<string, mixed>
	 */
	private function membership( string $client_id, string $site_id = '', string $role = Roles::STAFF, string $status = 'active' ): array {
		return array(
			'client_id'      => $client_id,
			'client_site_id' => $site_id,
			'role'           => $role,
			'status'         => $status,
		);
	}

	// -----------------------------------------------------------------------
	// What a membership reaches.
	// -----------------------------------------------------------------------

	/**
	 * Nobody with nothing reaches nothing. This is the default, and everything
	 * else is an addition to it.
	 */
	public function test_no_membership_reaches_nothing(): void {
		$reach = Reach::for_memberships( array() );

		$this->assertFalse( Reach::reaches_client( $reach, 'cli_a' ) );
		$this->assertFalse( Reach::reaches_site( $reach, 'cli_a', 'csite_1' ) );
	}

	/**
	 * A membership naming no site is the whole client (AUTH-6), including sites
	 * added to that client after the membership was written.
	 */
	public function test_a_client_wide_membership_reaches_every_site_under_it(): void {
		$reach = Reach::for_memberships( array( $this->membership( 'cli_a' ) ) );

		$this->assertTrue( Reach::reaches_client( $reach, 'cli_a' ) );
		$this->assertTrue( Reach::reaches_site( $reach, 'cli_a', 'csite_1' ) );
		$this->assertTrue( Reach::reaches_site( $reach, 'cli_a', 'csite_added_later' ) );
	}

	/**
	 * The isolation #92 exists for, stated at its smallest: a membership on one
	 * of a client's two sites does not reach the other one.
	 */
	public function test_a_site_membership_does_not_reach_the_clients_other_site(): void {
		$reach = Reach::for_memberships( array( $this->membership( 'cli_a', 'csite_1' ) ) );

		$this->assertTrue( Reach::reaches_site( $reach, 'cli_a', 'csite_1' ) );
		$this->assertFalse( Reach::reaches_site( $reach, 'cli_a', 'csite_2' ) );
	}

	/**
	 * That person still reaches the client itself, or the site they do hold
	 * could not be listed under anything.
	 */
	public function test_a_site_membership_reaches_the_client_it_sits_under(): void {
		$reach = Reach::for_memberships( array( $this->membership( 'cli_a', 'csite_1' ) ) );

		$this->assertTrue( Reach::reaches_client( $reach, 'cli_a' ) );
	}

	/**
	 * Another client is another tenant. Nothing about holding one says anything
	 * about the next.
	 */
	public function test_a_membership_on_one_client_reaches_no_other_client(): void {
		$reach = Reach::for_memberships( array( $this->membership( 'cli_a' ) ) );

		$this->assertFalse( Reach::reaches_client( $reach, 'cli_b' ) );
		$this->assertFalse( Reach::reaches_site( $reach, 'cli_b', 'csite_9' ) );
	}

	/**
	 * A site id is never enough on its own. Asking with the wrong client must
	 * fail even when the site id is one the person holds, or a caller who has
	 * muddled the two would be answered anyway.
	 */
	public function test_a_site_is_only_reached_under_its_own_client(): void {
		$reach = Reach::for_memberships( array( $this->membership( 'cli_a', 'csite_1' ) ) );

		$this->assertFalse( Reach::reaches_site( $reach, 'cli_b', 'csite_1' ) );
	}

	// -----------------------------------------------------------------------
	// Memberships that grant nothing.
	// -----------------------------------------------------------------------

	/**
	 * Access ends by deactivation and the row stays (NOTIF-5). A row that stayed
	 * must not still reach anything.
	 */
	public function test_an_ended_membership_reaches_nothing(): void {
		$reach = Reach::for_memberships( array( $this->membership( 'cli_a', '', Roles::STAFF, 'inactive' ) ) );

		$this->assertFalse( Reach::reaches_client( $reach, 'cli_a' ) );
	}

	/**
	 * An unrecognised role is a row somebody wrote by hand. It is not a role, so
	 * it grants nothing — including the read.
	 */
	public function test_an_unrecognised_role_reaches_nothing(): void {
		$reach = Reach::for_memberships( array( $this->membership( 'cli_a', '', 'auditor' ) ) );

		$this->assertFalse( Reach::reaches_client( $reach, 'cli_a' ) );
	}

	// -----------------------------------------------------------------------
	// #405. A client set to All staff.
	// -----------------------------------------------------------------------

	/**
	 * Every studio person reaches an All staff client, with or without a
	 * membership on it.
	 */
	public function test_studio_staff_reach_an_all_staff_client(): void {
		$reach = Reach::for_memberships( array( $this->membership( 'cli_a' ) ), array( 'cli_all' ) );

		$this->assertTrue( Reach::reaches_client( $reach, 'cli_all' ) );
		$this->assertTrue( Reach::reaches_site( $reach, 'cli_all', 'csite_new' ) );
		$this->assertFalse( Reach::reaches_client( $reach, 'cli_b' ) );
	}

	/**
	 * Only an active studio-side membership makes somebody staff. Nobody with
	 * no access yet, nobody whose staff access ended, and no row with a role
	 * nobody defined.
	 */
	public function test_only_an_active_studio_membership_makes_somebody_staff(): void {
		$this->assertFalse( Reach::reaches_client( Reach::for_memberships( array(), array( 'cli_all' ) ), 'cli_all' ) );
		$this->assertFalse( Reach::is_studio_staff( array( $this->membership( 'cli_a', '', Roles::STAFF, 'inactive' ) ) ) );
		$this->assertFalse( Reach::is_studio_staff( array( $this->membership( 'cli_a', '', 'auditor' ) ) ) );
		$this->assertTrue( Reach::is_studio_staff( array( $this->membership( 'cli_studio', '', Roles::INTERNAL_VIEWER ) ) ) );
	}

	/**
	 * A client's own people never do.
	 */
	public function test_a_clients_person_does_not_reach_an_all_staff_client(): void {
		$reach = Reach::for_memberships( array( $this->membership( 'cli_a', '', Roles::CLIENT_ADMIN ) ), array( 'cli_all' ) );

		$this->assertFalse( Reach::reaches_client( $reach, 'cli_all' ) );
		$this->assertTrue( Reach::reaches_client( $reach, 'cli_a' ) );
	}

	/**
	 * Ending a client person's access does not turn them into staff.
	 */
	public function test_a_clients_person_whose_access_ended_does_not_reach_it_either(): void {
		$reach = Reach::for_memberships(
			array( $this->membership( 'cli_a', '', Roles::CLIENT_VIEWER, 'inactive' ) ),
			array( 'cli_all' )
		);

		$this->assertTrue( Reach::is_nothing( $reach ) );
	}

	/**
	 * Somebody on both sides is ours, as Users::ours() says.
	 */
	public function test_somebody_on_both_sides_is_studio_staff(): void {
		$held = array(
			$this->membership( 'cli_a', '', Roles::CLIENT_ADMIN ),
			$this->membership( 'cli_b', '', Roles::STAFF ),
		);

		$this->assertTrue( Reach::is_studio_staff( $held ) );
		$this->assertTrue( Reach::reaches_client( Reach::for_memberships( $held, array( 'cli_all' ) ), 'cli_all' ) );
	}

	/**
	 * Without an All staff client nothing widens: a studio user is scoped
	 * exactly like a client user.
	 */
	public function test_without_all_staff_a_studio_user_is_scoped_like_a_client_user(): void {
		$staff  = Reach::for_memberships( array( $this->membership( 'cli_a', 'csite_1', Roles::STAFF ) ) );
		$client = Reach::for_memberships( array( $this->membership( 'cli_a', 'csite_1', Roles::CLIENT_ADMIN ) ) );

		$this->assertSame( $staff, $client );
	}

	/**
	 * The role somebody works with on an All staff client they hold no
	 * membership on: Staff, or Internal viewer for somebody only ever that.
	 */
	public function test_the_role_held_through_all_staff(): void {
		$this->assertSame( Roles::STAFF, Reach::all_staff_role( array() ) );
		$this->assertSame( Roles::STAFF, Reach::all_staff_role( array( $this->membership( 'cli_a', '', Roles::PRIMARY_ADMIN ) ) ) );
		$this->assertSame( Roles::INTERNAL_VIEWER, Reach::all_staff_role( array( $this->membership( 'cli_a', '', Roles::INTERNAL_VIEWER ) ) ) );
		$this->assertSame(
			Roles::STAFF,
			Reach::all_staff_role(
				array(
					$this->membership( 'cli_a', '', Roles::INTERNAL_VIEWER ),
					$this->membership( 'cli_b', '', Roles::STAFF ),
				)
			)
		);
	}

	/**
	 * Nothing else widens reach.
	 */
	public function test_nothing_else_widens_reach(): void {
		$this->assertFalse( Reach::reaches_client( Reach::for_memberships( array( $this->membership( 'cli_a' ) ) ), 'cli_b' ) );
	}

	// -----------------------------------------------------------------------
	// The studio's own administrator.
	// -----------------------------------------------------------------------

	/**
	 * Forge runs on our site, and somebody who can install plugins on it can
	 * already reach everything the boundary could hide.
	 */
	public function test_everything_reaches_everything(): void {
		$reach = Reach::everything();

		$this->assertTrue( Reach::reaches_client( $reach, 'cli_anything' ) );
		$this->assertTrue( Reach::reaches_site( $reach, 'cli_anything', 'csite_anything' ) );
	}

	/**
	 * Nothing is the shape the boundary starts from, and it is empty rather than
	 * absent — a caller that forgets to build one is refused, not crashed.
	 */
	public function test_nothing_reaches_nothing(): void {
		$reach = Reach::nothing();

		$this->assertFalse( Reach::reaches_client( $reach, 'cli_anything' ) );
		$this->assertFalse( Reach::reaches_site( $reach, 'cli_anything', 'csite_anything' ) );
	}

	/**
	 * "Reaches nothing" is a question the listing routes have to ask, because
	 * an empty list is the wrong answer to it.
	 *
	 * A person who holds nothing and a person whose clients happen to have no
	 * sites yet both get an empty array, and those mean completely different
	 * things — one is "not yours to see" and the other is "nothing here yet"
	 * (#125). A screen cannot tell them apart from the list alone.
	 */
	public function test_holding_nothing_is_a_question_that_can_be_asked(): void {
		$this->assertTrue( Reach::is_nothing( Reach::nothing() ) );
		$this->assertFalse( Reach::is_nothing( Reach::everything() ) );
		$this->assertFalse(
			Reach::is_nothing( Reach::for_memberships( array( $this->membership( 'cli_a' ) ) ) )
		);
	}

	/**
	 * An administrator with no clients configured at all still holds
	 * everything. Their empty list means "none created yet", and answering
	 * "not yours" on a fresh installation would be absurd.
	 */
	public function test_an_empty_studio_is_not_the_same_as_holding_nothing(): void {
		$this->assertFalse( Reach::is_nothing( Reach::everything() ) );
	}

	/**
	 * And somebody whose only membership has ended holds nothing, rather than
	 * holding a client with no sites.
	 */
	public function test_an_ended_membership_leaves_somebody_holding_nothing(): void {
		$reach = Reach::for_memberships(
			array( $this->membership( 'cli_a', '', Roles::STAFF, 'inactive' ) )
		);

		$this->assertTrue( Reach::is_nothing( $reach ) );
	}

	// -----------------------------------------------------------------------
	// Filtering a set of records.
	// -----------------------------------------------------------------------

	/**
	 * The list routes filter rather than refuse: a person asking for the work
	 * they can see gets it, not a refusal because some of it is somebody else's.
	 */
	public function test_a_list_is_filtered_to_what_is_reached(): void {
		$reach = Reach::for_memberships( array( $this->membership( 'cli_a', 'csite_1' ) ) );

		$rows = array(
			array(
				'client_id'      => 'cli_a',
				'client_site_id' => 'csite_1',
			),
			array(
				'client_id'      => 'cli_a',
				'client_site_id' => 'csite_2',
			),
			array(
				'client_id'      => 'cli_b',
				'client_site_id' => 'csite_9',
			),
		);

		$kept = Reach::keep_sites( $reach, $rows );

		$this->assertCount( 1, $kept );
		$this->assertSame( 'csite_1', $kept[0]['client_site_id'] );
	}

	/**
	 * Filtering renumbers, so the result is a list rather than an array with
	 * holes in it — which is the difference between a JSON array and a JSON
	 * object once it reaches the wire.
	 */
	public function test_a_filtered_list_is_still_a_list(): void {
		$reach = Reach::for_memberships( array( $this->membership( 'cli_a', 'csite_2' ) ) );

		$kept = Reach::keep_sites(
			$reach,
			array(
				array(
					'client_id'      => 'cli_a',
					'client_site_id' => 'csite_1',
				),
				array(
					'client_id'      => 'cli_a',
					'client_site_id' => 'csite_2',
				),
			)
		);

		$this->assertSame( array( 0 ), array_keys( $kept ) );
	}

	/**
	 * A site record carries its own id rather than a client_site_id, so the
	 * filter is told which key holds it. Guessing between the two is how a list
	 * quietly stops filtering: every row misses, or every row matches.
	 */
	public function test_a_list_of_sites_is_filtered_on_the_key_it_is_told(): void {
		$reach = Reach::for_memberships( array( $this->membership( 'cli_a', 'csite_1' ) ) );

		$kept = Reach::keep_sites(
			$reach,
			array(
				array(
					'id'        => 'csite_1',
					'client_id' => 'cli_a',
				),
				array(
					'id'        => 'csite_2',
					'client_id' => 'cli_a',
				),
			),
			'id'
		);

		$this->assertCount( 1, $kept );
		$this->assertSame( 'csite_1', $kept[0]['id'] );
	}

	/**
	 * Clients are filtered by the same rule, so a client reached only through
	 * one of its sites still appears — with that site alone beneath it.
	 */
	public function test_clients_are_filtered_to_those_reached(): void {
		$reach = Reach::for_memberships( array( $this->membership( 'cli_a', 'csite_1' ) ) );

		$kept = Reach::keep_clients(
			$reach,
			array(
				array( 'id' => 'cli_a' ),
				array( 'id' => 'cli_b' ),
			)
		);

		$this->assertCount( 1, $kept );
		$this->assertSame( 'cli_a', $kept[0]['id'] );
	}
}
