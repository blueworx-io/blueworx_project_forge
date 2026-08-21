<?php
/**
 * The whole state machine, every square of it.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\Capabilities;
use Blueworx\Forge\Tenancy\Denials;
use Blueworx\Forge\Tenancy\Roles;
use Blueworx\Forge\Work\Gates;
use Blueworx\Forge\Work\Outcomes;
use Blueworx\Forge\Work\Override;
use Blueworx\Forge\Work\Reopen;
use Blueworx\Forge\Work\Returns;
use Blueworx\Forge\Work\Stages;
use Blueworx\Forge\Work\Transitions;
use Blueworx\Forge\Work\Types;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #116. The other workflow tests each prove one rule; this one proves there is
 * nothing between them.
 *
 * It walks the whole grid — every stage against every other stage, for every
 * work type — and holds it to the table in
 * docs/architecture/workflow-state-machine.md. **The moves that must fail are
 * the point**: a state machine tested only on its happy path is a state machine
 * that permits everything nobody thought to try.
 */
final class WorkflowEngineTest extends TestCase {

	/**
	 * The forward path, exactly as the specification's table gives it. Every
	 * pair not in here must be refused as a forward move.
	 *
	 * @return array<int, array{string, string, string}> from, to, required type.
	 */
	private static function table(): array {
		return array(
			array( 'future-idea', 'triage', 'any' ),
			array( 'triage', 'bug-tracking', Types::BUG ),
			array( 'triage', 'documentation-period', 'not-bug' ),
			array( 'bug-tracking', 'documentation-period', 'any' ),
			array( 'documentation-period', 'technical-audit', 'any' ),
			array( 'technical-audit', 'design-process', 'any' ),
			array( 'design-process', 'up-next', 'any' ),
			array( 'up-next', 'in-development', 'any' ),
			array( 'in-development', 'in-review', 'any' ),
			array( 'in-review', 'completed', 'any' ),
			array( 'completed', 'released', 'any' ),
		);
	}

	/**
	 * Whether the table permits this move for this type.
	 *
	 * @param string $from      Stage moving from.
	 * @param string $to        Stage moving to.
	 * @param string $work_type The item's work type.
	 * @return bool
	 */
	private static function permitted( string $from, string $to, string $work_type ): bool {
		foreach ( self::table() as $row ) {
			if ( $row[0] !== $from || $row[1] !== $to ) {
				continue;
			}

			if ( 'any' === $row[2] ) {
				return true;
			}

			if ( 'not-bug' === $row[2] ) {
				return Types::BUG !== $work_type;
			}

			return $row[2] === $work_type;
		}

		return false;
	}

	/**
	 * Every square of the grid: twelve stages by twelve, by four work types.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function every_pair(): array {
		$cases = array();

		foreach ( Stages::ALL as $from ) {
			foreach ( Stages::ALL as $to ) {
				foreach ( Types::ALL as $type ) {
					$cases[ "{$from} to {$to} as {$type}" ] = array( $from, $to, $type );
				}
			}
		}

		return $cases;
	}

	/**
	 * The whole grid against the table. 576 squares, of which eleven rows are
	 * open and the rest are shut.
	 *
	 * @param string $from Stage moving from.
	 * @param string $to   Stage moving to.
	 * @param string $type Work type.
	 */
	#[DataProvider( 'every_pair' )]
	public function test_every_square_of_the_grid_matches_the_table( string $from, string $to, string $type ): void {
		$this->assertSame(
			self::permitted( $from, $to, $type ),
			Transitions::allowed( $from, $to, $type ),
			"{$from} → {$to} as {$type}"
		);
	}

	/**
	 * A stage is never a forward move to itself, whatever the type.
	 */
	public function test_nothing_moves_to_where_it_already_is(): void {
		foreach ( Stages::ALL as $stage ) {
			foreach ( Types::ALL as $type ) {
				$this->assertFalse( Transitions::allowed( $stage, $stage, $type ) );
			}
		}
	}

	/**
	 * Blocked is on no forward path in either direction. It is entered and left
	 * by its own moves, from wherever the item was (#109).
	 */
	public function test_blocked_is_on_no_forward_path(): void {
		foreach ( Stages::ALL as $stage ) {
			foreach ( Types::ALL as $type ) {
				$this->assertFalse( Transitions::allowed( $stage, Stages::BLOCKED, $type ) );
				$this->assertFalse( Transitions::allowed( Stages::BLOCKED, $stage, $type ) );
			}
		}
	}

	/**
	 * Released is the end of the forward path for everything.
	 */
	public function test_released_is_the_end_of_the_road(): void {
		foreach ( Types::ALL as $type ) {
			$this->assertSame( array(), Transitions::next_from( 'released', $type ) );
		}
	}

	/**
	 * Every open square names a gate, and every gate it names is defined. This
	 * is what stops a transition being added with a gate nobody wrote — the
	 * failure mode that makes a gate optional by accident.
	 */
	public function test_every_permitted_move_has_a_defined_gate(): void {
		foreach ( self::table() as $row ) {
			$gate = Transitions::gate_for( $row[0], $row[1] );

			$this->assertNotSame( '', $gate, "{$row[0]} → {$row[1]} names no gate" );
			$this->assertTrue( Gates::exists( $gate ) );
			$this->assertNotSame( array(), Gates::requirements( $gate ) );
		}

		// And a square that is shut names no gate at all.
		$this->assertSame( '', Transitions::gate_for( 'triage', 'released' ) );
	}

	/**
	 * Entering Released has a gate of its own, recorded on the way in. It is
	 * separate from the exit gate before it on purpose: G-COMPLETED asks
	 * whether the work is ready to go, and G-RELEASED asks what happened when
	 * it went.
	 */
	public function test_released_has_an_entry_gate_and_nothing_else_does(): void {
		$this->assertSame( 'G-RELEASED', Transitions::entry_gate_for( 'released' ) );

		foreach ( Stages::ALL as $stage ) {
			if ( 'released' === $stage ) {
				continue;
			}

			$this->assertSame( '', Transitions::entry_gate_for( $stage ) );
		}
	}

	/**
	 * Work starts in one stage and no other.
	 */
	public function test_work_starts_in_one_stage_only(): void {
		foreach ( Stages::ALL as $stage ) {
			$this->assertSame( Stages::FIRST === $stage, Transitions::may_start( $stage ) );
		}
	}

	/**
	 * Each exception path answers for every stage rather than only for the ones
	 * somebody remembered. A path that returns nothing for a stage is a path
	 * that quietly does not exist there.
	 */
	public function test_every_stage_has_an_answer_from_every_exception_path(): void {
		foreach ( Stages::ALL as $stage ) {
			$item = array(
				'id'               => 'wrk_1',
				'stage'            => $stage,
				'work_type'        => Types::FEATURE,
				'cycle'            => 1,
				'archived'         => false,
				'terminal_outcome' => '',
			);

			// Each of these is a list, possibly empty, and never an error.
			$this->assertIsArray( Returns::targets( $item, array() ) );
			$this->assertIsArray( Outcomes::available_for( $item ) );
			$this->assertIsArray( Reopen::targets( $item ) );
			$this->assertIsBool( Override::allowed( $item, 'triage' ) );
		}
	}

	/**
	 * Blocked and the terminal outcomes agree about what "active" means. If
	 * they did not, an item could be cancellable but not blockable, or the
	 * reverse, for no reason anybody wrote down.
	 */
	public function test_blocking_and_cancelling_agree_on_what_is_active(): void {
		foreach ( Stages::ALL as $stage ) {
			$this->assertSame(
				Stages::is_active( $stage ),
				Outcomes::reachable_from( Outcomes::CANCELLED, $stage ),
				"{$stage} disagrees about being active"
			);
		}
	}

	/**
	 * Every stage a bug may occupy, and every stage nothing else may. The
	 * conditional stage is one stage — not a mechanism somebody can extend by
	 * adding a row.
	 */
	public function test_the_conditional_stage_is_one_stage(): void {
		foreach ( Stages::ALL as $stage ) {
			foreach ( Types::ALL as $type ) {
				$expected = Stages::BUG_TRACKING !== $stage || Types::BUG === $type;

				$this->assertSame( $expected, Stages::may_hold( $stage, $type ), "{$stage} for {$type}" );
			}
		}
	}

	/**
	 * The denial paths. Every workflow denial in the permission matrix is
	 * refused for both client roles, and the refusal names the lock rather than
	 * merely saying no.
	 */
	public function test_every_workflow_denial_is_refused_for_every_client_role(): void {
		$this->assertCount( 10, Denials::for_area( Denials::WORKFLOW ) );

		foreach ( Capabilities::workflow() as $capability ) {
			foreach ( array( Roles::CLIENT_ADMIN, Roles::CLIENT_VIEWER ) as $role ) {
				$decision = Capabilities::decide(
					$capability,
					array(
						'role'      => $role,
						'interface' => Capabilities::STUDIO,
						'own_site'  => true,
					)
				);

				$this->assertFalse( $decision['allowed'], "{$role} must not hold {$capability}" );
				$this->assertSame( Capabilities::CLIENT_LOCK, $decision['code'] );
			}
		}
	}

	/**
	 * And every gate in the registry belongs to something. A gate nothing names
	 * is a set of requirements nobody will ever be asked for.
	 */
	public function test_every_defined_gate_is_reachable(): void {
		$named = array( Transitions::CREATE_GATE, 'G-RELEASED', 'G-BLOCKED-ENTRY', 'G-BLOCKED-EXIT' );

		foreach ( self::table() as $row ) {
			$named[] = Transitions::gate_for( $row[0], $row[1] );
		}

		foreach ( array_keys( Gates::all() ) as $gate ) {
			$this->assertContains( $gate, $named, "{$gate} is defined but nothing reaches it" );
		}
	}
}
