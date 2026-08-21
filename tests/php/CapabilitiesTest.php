<?php
/**
 * The permission matrix, held to its own document.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\Capabilities;
use Blueworx\Forge\Tenancy\Denials;
use Blueworx\Forge\Tenancy\Roles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #91. Every row of docs/architecture/permission-matrix.md has a test, denials
 * included — which is the issue's acceptance, so the coverage checks here are
 * as much the point as the individual rules.
 */
final class CapabilitiesTest extends TestCase {

	/**
	 * A context.
	 *
	 * @param string               $role Role held.
	 * @param array<string, mixed> $over Anything else.
	 * @return array<string, mixed>
	 */
	private function who( string $role, array $over = array() ): array {
		return array_merge(
			array(
				'role'      => $role,
				'interface' => Capabilities::STUDIO,
			),
			$over
		);
	}

	/**
	 * No cell is blank. The matrix document opens with that rule and this is
	 * what holds the grid to it: every capability, both interfaces, all five
	 * columns, every cell a real answer.
	 */
	public function test_no_cell_is_blank(): void {
		foreach ( Capabilities::grid() as $capability => $interfaces ) {
			foreach ( array( Capabilities::STUDIO, Capabilities::CLIENT ) as $interface ) {
				$this->assertArrayHasKey( $interface, $interfaces, "{$capability} has no {$interface} row" );

				foreach ( Capabilities::ACTORS as $actor ) {
					$this->assertArrayHasKey( $actor, $interfaces[ $interface ], "{$capability}/{$interface} has no cell for {$actor}" );
					$this->assertNotSame( '', $interfaces[ $interface ][ $actor ] );
				}
			}
		}
	}

	/**
	 * Every capability answers for every role without throwing, and answers
	 * with a code either way. A denial with no code is the thing this class
	 * exists to stop.
	 */
	public function test_every_decision_carries_a_code(): void {
		foreach ( Capabilities::all() as $capability ) {
			foreach ( Roles::ALL as $role ) {
				foreach ( array( Capabilities::STUDIO, Capabilities::CLIENT ) as $interface ) {
					$decision = Capabilities::decide( $capability, $this->who( $role, array( 'interface' => $interface ) ) );

					$this->assertNotSame( '', $decision['code'] );

					if ( ! $decision['allowed'] ) {
						$this->assertNotSame( '', $decision['reason'], "{$capability}/{$role} refuses without saying why" );
					}
				}
			}
		}
	}

	/**
	 * An unknown capability is refused, not granted. A typo in a route must not
	 * open a door.
	 */
	public function test_an_unknown_capability_is_refused(): void {
		$decision = Capabilities::decide( 'take_over_the_world', $this->who( Roles::PRIMARY_ADMIN ) );

		$this->assertFalse( $decision['allowed'] );
		$this->assertSame( Capabilities::UNKNOWN, $decision['code'] );
	}

	/**
	 * An unknown role holds nothing.
	 */
	public function test_an_unknown_role_holds_nothing(): void {
		$decision = Capabilities::decide( Capabilities::COMMENT, $this->who( 'wizard' ) );

		$this->assertFalse( $decision['allowed'] );
		$this->assertSame( '', Capabilities::actor( array( 'role' => 'wizard' ) ) );
	}

	/**
	 * Principal is a grant on a staff account, not an account type (AUTH-3).
	 */
	public function test_principal_is_a_grant_on_a_staff_account(): void {
		$this->assertSame( Capabilities::STAFF, Capabilities::actor( array( 'role' => Roles::STAFF ) ) );
		$this->assertSame(
			Capabilities::PRINCIPAL,
			Capabilities::actor(
				array(
					'role'      => Roles::STAFF,
					'principal' => true,
				)
			)
		);
	}

	/**
	 * The client roles.
	 *
	 * @return array<string, array{string}>
	 */
	public static function client_roles(): array {
		return array(
			'client administrator' => array( Roles::CLIENT_ADMIN ),
			'client viewer'        => array( Roles::CLIENT_VIEWER ),
		);
	}

	/**
	 * #115, as a rule rather than as a route. No client role moves work by any
	 * capability, on either interface — including from the studio, where a
	 * client administrator is still a client.
	 *
	 * @param string $role A client-side role.
	 */
	#[DataProvider( 'client_roles' )]
	public function test_no_client_role_moves_work_by_any_route( string $role ): void {
		foreach ( Capabilities::workflow() as $capability ) {
			foreach ( array( Capabilities::STUDIO, Capabilities::CLIENT ) as $interface ) {
				$decision = Capabilities::decide(
					$capability,
					$this->who(
						$role,
						array(
							'interface'          => $interface,
							// Every condition satisfied, so the refusal cannot
							// be mistaken for an unmet condition.
							'own_site'           => true,
							'assigned_reviewer'  => true,
							'assigned_deliverer' => true,
							'holds_approver'     => true,
							'active_package'     => true,
						)
					)
				);

				$this->assertFalse( $decision['allowed'], "{$role} should not hold {$capability} on {$interface}" );
				$this->assertSame( Capabilities::CLIENT_LOCK, $decision['code'] );
			}
		}
	}

	/**
	 * And the lock names itself. A refusal that says only "no" leaves the
	 * caller to guess whether it was the role, the site or the gate.
	 */
	public function test_the_lock_says_which_rule_it_is(): void {
		$decision = Capabilities::decide( Capabilities::MOVE_FORWARD, $this->who( Roles::CLIENT_ADMIN ) );

		$this->assertSame( Capabilities::CLIENT_LOCK, $decision['code'] );
		$this->assertStringContainsString( 'never move work', $decision['reason'] );
	}

	/**
	 * Staff have no presence on the client interface at all — the client
	 * artifact does not contain studio code (ARCH-1) — so those cells refuse
	 * for what they are rather than for what the role holds.
	 */
	public function test_staff_do_not_exist_on_the_client_interface(): void {
		$decision = Capabilities::decide(
			Capabilities::COMMENT,
			$this->who( Roles::STAFF, array( 'interface' => Capabilities::CLIENT ) )
		);

		$this->assertFalse( $decision['allowed'] );
		$this->assertSame( Capabilities::WRONG_INTERFACE, $decision['code'] );
	}

	/**
	 * Two rows nobody holds, the Primary administrator included.
	 */
	public function test_derived_state_and_append_only_records_are_nobodys(): void {
		foreach ( array( Capabilities::EDIT_DERIVED_STATE, Capabilities::EDIT_APPEND_ONLY ) as $capability ) {
			foreach ( Roles::ALL as $role ) {
				$decision = Capabilities::decide( $capability, $this->who( $role ) );

				$this->assertFalse( $decision['allowed'], "{$role} must not hold {$capability}" );
			}

			$this->assertSame(
				Capabilities::NOBODY,
				Capabilities::decide( $capability, $this->who( Roles::PRIMARY_ADMIN ) )['code']
			);
		}
	}

	/**
	 * A staff read is scoped to the sites their membership grants (AUTH-6), and
	 * the refusal says so.
	 */
	public function test_a_staff_read_is_scoped_to_their_sites(): void {
		$theirs = Capabilities::decide( Capabilities::VIEW_WORK, $this->who( Roles::STAFF, array( 'own_site' => true ) ) );
		$others = Capabilities::decide( Capabilities::VIEW_WORK, $this->who( Roles::STAFF, array( 'own_site' => false ) ) );

		$this->assertTrue( $theirs['allowed'] );
		$this->assertFalse( $others['allowed'] );
		$this->assertSame( Capabilities::NOT_YOUR_SITE, $others['code'] );

		// The Primary administrator is the exception, by definition.
		$this->assertTrue(
			Capabilities::decide( Capabilities::VIEW_OTHER_CLIENT_WORK, $this->who( Roles::PRIMARY_ADMIN ) )['allowed']
		);
		$this->assertFalse(
			Capabilities::decide( Capabilities::VIEW_OTHER_CLIENT_WORK, $this->who( Roles::STAFF, array( 'own_site' => true ) ) )['allowed']
		);
	}

	/**
	 * An internal viewer sees internal notes; a client viewer never does
	 * (AUTH-5). The two share a column and differ here alone.
	 */
	public function test_only_an_internal_viewer_sees_internal_notes(): void {
		$this->assertTrue(
			Capabilities::decide( Capabilities::VIEW_INTERNAL_NOTES, $this->who( Roles::INTERNAL_VIEWER ) )['allowed']
		);
		$this->assertFalse(
			Capabilities::decide( Capabilities::VIEW_INTERNAL_NOTES, $this->who( Roles::CLIENT_VIEWER ) )['allowed']
		);
	}

	/**
	 * A client raises work only where there is a package (AUTH-2) — but a bug
	 * or a request always, package or not (COMM-5).
	 */
	public function test_client_creation_needs_a_package_but_a_bug_never_does(): void {
		$client = array(
			'interface'      => Capabilities::CLIENT,
			'active_package' => false,
		);

		$this->assertFalse(
			Capabilities::decide( Capabilities::CREATE_WORK_ITEM, $this->who( Roles::CLIENT_ADMIN, $client ) )['allowed']
		);
		$this->assertTrue(
			Capabilities::decide( Capabilities::SUBMIT_BUG, $this->who( Roles::CLIENT_ADMIN, $client ) )['allowed']
		);
		$this->assertTrue(
			Capabilities::decide(
				Capabilities::CREATE_WORK_ITEM,
				$this->who(
					Roles::CLIENT_ADMIN,
					array(
						'interface'      => Capabilities::CLIENT,
						'active_package' => true,
					)
				)
			)['allowed']
		);
	}

	/**
	 * Client editing of the definition ends when the item leaves Documentation
	 * Period (AUTH-2).
	 */
	public function test_client_editing_ends_when_documentation_does(): void {
		$before = $this->who(
			Roles::CLIENT_ADMIN,
			array(
				'interface'                 => Capabilities::CLIENT,
				'before_documentation_ends' => true,
			)
		);
		$after  = $this->who(
			Roles::CLIENT_ADMIN,
			array(
				'interface'                 => Capabilities::CLIENT,
				'before_documentation_ends' => false,
			)
		);

		$this->assertTrue( Capabilities::decide( Capabilities::EDIT_DEFINITION, $before )['allowed'] );
		$this->assertFalse( Capabilities::decide( Capabilities::EDIT_DEFINITION, $after )['allowed'] );
	}

	/**
	 * #112, as a rule. Approving a review and confirming a release are the
	 * assigned person's, and rank does not substitute for assignment — a
	 * Primary administrator holds them only when assigned, or through the
	 * override, which is marked on the item.
	 */
	public function test_review_and_release_belong_to_the_assigned_person(): void {
		foreach ( array( Roles::PRIMARY_ADMIN, Roles::STAFF ) as $role ) {
			$this->assertFalse(
				Capabilities::decide( Capabilities::APPROVE_REVIEW, $this->who( $role, array( 'assigned_reviewer' => false ) ) )['allowed'],
				"{$role} must not approve a review they are not assigned to"
			);
			$this->assertTrue(
				Capabilities::decide( Capabilities::APPROVE_REVIEW, $this->who( $role, array( 'assigned_reviewer' => true ) ) )['allowed']
			);
			$this->assertFalse(
				Capabilities::decide( Capabilities::CONFIRM_RELEASE, $this->who( $role, array( 'assigned_deliverer' => false ) ) )['allowed']
			);
			$this->assertTrue(
				Capabilities::decide( Capabilities::CONFIRM_RELEASE, $this->who( $role, array( 'assigned_deliverer' => true ) ) )['allowed']
			);
		}
	}

	/**
	 * The override is the Primary administrator's alone (WF-5).
	 */
	public function test_only_the_primary_administrator_overrides(): void {
		$this->assertTrue( Capabilities::decide( Capabilities::OVERRIDE, $this->who( Roles::PRIMARY_ADMIN ) )['allowed'] );

		foreach ( array( Roles::STAFF, Roles::CLIENT_ADMIN, Roles::CLIENT_VIEWER, Roles::INTERNAL_VIEWER ) as $role ) {
			$this->assertFalse( Capabilities::decide( Capabilities::OVERRIDE, $this->who( $role ) )['allowed'] );
		}

		// Not even with the Principal grant: Principal is about approving one's
		// own work, not about moving anybody's.
		$this->assertFalse(
			Capabilities::decide(
				Capabilities::OVERRIDE,
				$this->who(
					Roles::STAFF,
					array( 'principal' => true )
				)
			)['allowed']
		);
	}

	/**
	 * Self-approval is refused unless the user holds Principal (AUTH-3).
	 */
	public function test_self_approval_needs_the_principal_grant(): void {
		$approver = array( 'holds_approver' => true );

		$this->assertFalse(
			Capabilities::decide( Capabilities::APPROVE_OWN_ITEM, $this->who( Roles::STAFF, $approver ) )['allowed']
		);
		$this->assertTrue(
			Capabilities::decide(
				Capabilities::APPROVE_OWN_ITEM,
				$this->who( Roles::STAFF, array_merge( $approver, array( 'principal' => true ) ) )
			)['allowed']
		);

		// And the Principal grant is not a way round the Approver capability.
		$this->assertFalse(
			Capabilities::decide(
				Capabilities::APPROVE_OWN_ITEM,
				$this->who(
					Roles::STAFF,
					array(
						'principal'      => true,
						'holds_approver' => false,
					)
				)
			)['allowed']
		);
	}

	/**
	 * A gate approval needs the matching Approver capability, which is granted
	 * per gate type independently of the Sub-item roles (AUTH-1).
	 */
	public function test_a_gate_approval_needs_its_approver_capability(): void {
		$without = Capabilities::decide( Capabilities::APPROVE_GATE, $this->who( Roles::STAFF, array( 'holds_approver' => false ) ) );

		$this->assertFalse( $without['allowed'] );
		$this->assertSame( 'holds_approver', $without['condition'] );
		$this->assertTrue(
			Capabilities::decide( Capabilities::APPROVE_GATE, $this->who( Roles::STAFF, array( 'holds_approver' => true ) ) )['allowed']
		);
	}

	/**
	 * The denial list is all forty, each with an area and at least one route.
	 * "Refused in the UI" is not refused, so a denial with no route beyond the
	 * control is a denial nobody has really tested.
	 */
	public function test_every_denial_is_listed_with_its_routes(): void {
		$denials = Denials::all();

		$this->assertCount( 40, $denials );

		foreach ( $denials as $id => $denial ) {
			$this->assertMatchesRegularExpression( '/^D-\d+$/', $id );
			$this->assertNotSame( '', $denial['must_refuse'] );
			$this->assertNotSame( array(), $denial['routes'] );
			$this->assertNotSame(
				array( 'a' ),
				$denial['routes'],
				"{$id} is only refused in the UI, which is not refused"
			);

			foreach ( $denial['routes'] as $route ) {
				$this->assertContains( $route, array( 'a', 'b', 'c', 'd', 'e' ) );
			}
		}
	}

	/**
	 * The workflow denials are the ones Milestone 4 answers, and each of them
	 * has a capability that refuses it. A denial with nothing to enforce it is
	 * a sentence in a document.
	 */
	public function test_every_workflow_denial_has_a_capability_behind_it(): void {
		$workflow = Denials::for_area( Denials::WORKFLOW );

		$this->assertCount( 10, $workflow );

		// D-10 to D-19 against the capabilities that refuse them, in order.
		$enforced = array(
			'D-10' => Capabilities::MOVE_FORWARD,
			'D-11' => Capabilities::RETURN_ITEM,
			'D-12' => Capabilities::BLOCK_ITEM,
			'D-13' => Capabilities::RECORD_OUTCOME,
			'D-14' => Capabilities::APPROVE_REVIEW,
			'D-15' => Capabilities::CONFIRM_RELEASE,
			'D-16' => Capabilities::REOPEN,
			'D-17' => Capabilities::EDIT_WORKFLOW,
			'D-18' => Capabilities::COMPLETE_GATE,
			'D-19' => Capabilities::OVERRIDE,
		);

		foreach ( $enforced as $id => $capability ) {
			$this->assertTrue( Denials::exists( $id ) );
			$this->assertTrue( Capabilities::is_workflow( $capability ), "{$capability} should be under the client lock" );

			foreach ( array( Roles::CLIENT_ADMIN, Roles::CLIENT_VIEWER ) as $role ) {
				$this->assertFalse(
					Capabilities::decide( $capability, $this->who( $role ) )['allowed'],
					"{$id} is not refused for {$role}"
				);
			}
		}
	}
}
