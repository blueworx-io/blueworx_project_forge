<?php
/**
 * Whether the acceptance manifest is actually covered by tests.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Acceptance\Criteria;
use PHPUnit\Framework\TestCase;

/**
 * #178, #179, #180. The brief's §16 acceptance criteria are the manifest, and
 * this is what holds the suite to them.
 *
 * The same argument DenialCoverageTest makes, about a different list. M11 is
 * "the brief's acceptance criteria turned into tests", and until now the only
 * record of which criteria those were lived in the programme design and in four
 * issue descriptions. A criterion nobody wrote a spec for and a criterion
 * nobody wrote down look identical from here.
 *
 * So the criteria are data, each naming the programme slot that owes its spec,
 * and this reads the two-instance suite and fails if a criterion whose slot has
 * landed is not named in any of it.
 *
 * **It checks that a criterion is claimed, not that the claim is honest.** No
 * test can do the second. What it buys is that deleting an acceptance spec
 * breaks the build by name, which is the failure that actually happens.
 */
final class AcceptanceCoverageTest extends TestCase {

	/**
	 * The programme slots whose specs have been written.
	 *
	 * Widening this is the last step of the issue that writes the specs, and it
	 * is deliberately a decision rather than a count: M11-4 is #181's onboarding
	 * and operations criteria, and M8 is #246's commercial ones, which test
	 * rules M8 has not built yet.
	 *
	 * @var array<int, string>
	 */
	private const LANDED = array( 'M11-1', 'M11-2', 'M11-3' );

	/**
	 * Where the specs that run against a real client site live.
	 */
	private const SUITE = __DIR__ . '/../pair';

	/**
	 * Every criterion id named anywhere in the two-instance suite.
	 *
	 * Read out of the files rather than declared, so the manifest and the specs
	 * cannot be kept in step by a third list that also has to be maintained.
	 *
	 * @return array<int, string>
	 */
	private function claimed(): array {
		$named = array();

		foreach ( glob( self::SUITE . '/*.spec.js' ) ?: array() as $spec ) {
			$source = (string) file_get_contents( $spec );

			if ( preg_match_all( '/\bAC-\d+\b/', $source, $found ) ) {
				$named = array_merge( $named, $found[0] );
			}
		}

		return array_values( array_unique( $named ) );
	}

	/**
	 * The criteria whose specs are owed now.
	 *
	 * @return array<int, string>
	 */
	private function owed(): array {
		$owed = array();

		foreach ( self::LANDED as $slot ) {
			$owed = array_merge( $owed, array_keys( Criteria::proved_by( $slot ) ) );
		}

		return $owed;
	}

	// ---- The count the landed slots owe ---------------------------------

	/**
	 * The test this class exists for.
	 *
	 * A gap is reported by name, because "10 of 12" tells nobody which two
	 * went.
	 */
	public function test_every_criterion_whose_slot_has_landed_is_covered(): void {
		$owed    = $this->owed();
		$missing = array_values( array_diff( $owed, $this->claimed() ) );

		$this->assertSame(
			array(),
			$missing,
			sprintf(
				'%d of %d acceptance criteria have no spec against the client artifact: %s',
				count( $missing ),
				count( $owed ),
				implode( ', ', $missing )
			)
		);
	}

	/**
	 * And there is something to cover in the first place.
	 *
	 * The way this test could pass for the wrong reason: a slot label typo
	 * makes the owed list empty, and an empty list is trivially covered.
	 */
	public function test_the_landed_slots_owe_a_meaningful_share_of_the_list(): void {
		$owed = $this->owed();

		$this->assertGreaterThan( 8, count( $owed ) );
		$this->assertLessThanOrEqual( count( Criteria::all() ), count( $owed ) );
	}

	/**
	 * Nothing is named that is not a criterion.
	 *
	 * A spec claiming AC-99 proves something nobody wrote down, and it would go
	 * on passing forever. This runs the other way from the check above, and
	 * catches the typo that would otherwise leave a real criterion uncovered
	 * while the count looked right.
	 */
	public function test_no_spec_claims_a_criterion_that_does_not_exist(): void {
		$invented = array_values(
			array_filter(
				$this->claimed(),
				static fn( string $id ): bool => ! Criteria::exists( $id )
			)
		);

		$this->assertSame(
			array(),
			$invented,
			'specs name acceptance criteria nobody has written down: ' . implode( ', ', $invented )
		);
	}

	// ---- The manifest itself --------------------------------------------

	/**
	 * Every criterion says which slot owes its spec. One that does not can
	 * never be counted as missing.
	 */
	public function test_every_criterion_names_the_slot_that_proves_it(): void {
		foreach ( Criteria::all() as $id => $criterion ) {
			$this->assertMatchesRegularExpression(
				'/^(M8|M11-[1-4])$/',
				(string) ( $criterion['proved_by'] ?? '' ),
				"{$id} does not say which slot proves it"
			);
		}
	}

	/**
	 * Every criterion says what it is, in words somebody could check a spec
	 * against.
	 */
	public function test_every_criterion_states_what_must_hold(): void {
		foreach ( Criteria::all() as $id => $criterion ) {
			$this->assertGreaterThan(
				20,
				strlen( (string) ( $criterion['must_hold'] ?? '' ) ),
				"{$id} does not say what must hold"
			);
		}
	}

	/**
	 * Every slot that has landed is one the manifest actually uses.
	 *
	 * The guard on the escape hatch above: a slot name that matches nothing
	 * would let LANDED grow while the owed list stayed still.
	 */
	public function test_each_landed_slot_owes_something(): void {
		foreach ( self::LANDED as $slot ) {
			$this->assertNotSame(
				array(),
				Criteria::proved_by( $slot ),
				"{$slot} is named as landed but no criterion is proved by it"
			);
		}
	}
}
