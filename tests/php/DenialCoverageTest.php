<?php
/**
 * Whether the denial manifest is actually covered by tests.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\Denials;
use PHPUnit\Framework\TestCase;

/**
 * #135. The denial list is the test manifest, and this is what holds it to that.
 *
 * Tenancy\Denials has said since #91 that "an action missing from here is an
 * untested hole". That was true and unenforced: the list could name forty
 * things and the suite could cover nine, and nothing would say so. A manifest
 * nobody counts is a document.
 *
 * So this counts. It reads the two-instance specs — the ones that run against
 * the real client artifact on its own WordPress, which is what Milestone 6 owes
 * — and fails if a denial this milestone is responsible for is not named in any
 * of them.
 *
 * **It checks that a denial is claimed, not that the claim is honest.** No test
 * can do the second. What it buys is that adding a denial to the manifest
 * without writing a test breaks the build, and that deleting a test quietly
 * does too — which is the failure mode that actually happens, because the test
 * that goes missing is never the one somebody is thinking about.
 *
 * The milestones a denial is not owed by are the ones whose subject is not
 * built: packages and balances are M8's, the onboarding checklist is M9's.
 * Naming those on the denial itself is what stops "not yet buildable" and
 * "nobody wrote it" from looking the same from here.
 */
final class DenialCoverageTest extends TestCase {

	/**
	 * The milestone this suite is answering for.
	 */
	private const MILESTONE = 'M6';

	/**
	 * Where the specs that run against a real client site live.
	 */
	private const SUITE = __DIR__ . '/../pair';

	/**
	 * Every denial id named anywhere in the two-instance suite.
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

			if ( preg_match_all( '/\bD-\d+\b/', $source, $found ) ) {
				$named = array_merge( $named, $found[0] );
			}
		}

		return array_values( array_unique( $named ) );
	}

	// ---- The count this milestone owes ----------------------------------

	/**
	 * The test this class exists for.
	 *
	 * Every denial Milestone 6 is responsible for is named by a spec that runs
	 * against a real client site. A gap is reported by name, because "26 of 26"
	 * going to "25 of 26" tells nobody which one went.
	 */
	public function test_every_denial_this_milestone_owes_is_covered(): void {
		$owed    = array_keys( Denials::proved_by( self::MILESTONE ) );
		$claimed = $this->claimed();
		$missing = array_values( array_diff( $owed, $claimed ) );

		$this->assertSame(
			array(),
			$missing,
			sprintf(
				'%d of %d denials have no test against the client artifact: %s',
				count( $missing ),
				count( $owed ),
				implode( ', ', $missing )
			)
		);
	}

	/**
	 * And there is something to cover in the first place.
	 *
	 * A guard against the way this test could pass for the wrong reason: a
	 * milestone label typo makes `$owed` empty, and an empty list is trivially
	 * covered. The number is asserted loosely — moving a denial between
	 * milestones is a decision, not a build break — but it cannot fall to
	 * nothing.
	 */
	public function test_this_milestone_owes_a_meaningful_share_of_the_list(): void {
		$owed = Denials::proved_by( self::MILESTONE );

		$this->assertGreaterThan( 20, count( $owed ) );
		$this->assertLessThanOrEqual( 40, count( $owed ) );
	}

	/**
	 * Nothing is named that is not a denial.
	 *
	 * A spec claiming D-41 is a spec proving something nobody wrote down, and
	 * it would go on passing forever. The check runs the other way from the one
	 * above, and catches the typo that would otherwise leave a real denial
	 * uncovered while the count looked right.
	 */
	public function test_no_spec_claims_a_denial_that_does_not_exist(): void {
		$invented = array_values(
			array_filter(
				$this->claimed(),
				static fn( string $id ): bool => ! Denials::exists( $id )
			)
		);

		$this->assertSame( array(), $invented, 'specs name denials nobody has written down: ' . implode( ', ', $invented ) );
	}

	// ---- The manifest itself --------------------------------------------

	/**
	 * Every denial says which milestone proves it. One that does not is a
	 * denial that can never be counted as missing.
	 */
	public function test_every_denial_names_the_milestone_that_proves_it(): void {
		foreach ( Denials::all() as $id => $denial ) {
			$this->assertMatchesRegularExpression(
				'/^M\d+$/',
				(string) ( $denial['proved_by'] ?? '' ),
				"{$id} does not say which milestone proves it"
			);
		}
	}

	/**
	 * Every denial waiting on a later milestone is waiting on one that exists.
	 *
	 * The escape hatch has to stay narrow. "Proved by M99" would be a denial
	 * parked forever behind a milestone nobody is ever going to reach.
	 */
	public function test_a_deferred_denial_names_a_milestone_that_is_planned(): void {
		$planned = array( 'M2', 'M4', 'M6', 'M7', 'M8', 'M9' );

		foreach ( Denials::all() as $id => $denial ) {
			$this->assertContains(
				(string) $denial['proved_by'],
				$planned,
				"{$id} is parked behind a milestone that is not in the programme"
			);
		}
	}

	/**
	 * The suite this reads actually exists.
	 *
	 * Without this, a renamed directory turns the whole class into a test that
	 * finds nothing to check and says so cheerfully.
	 */
	public function test_the_client_suite_is_where_this_expects_it(): void {
		$specs = glob( self::SUITE . '/*.spec.js' ) ?: array();

		$this->assertNotSame( array(), $specs, 'no two-instance specs found' );
		$this->assertNotSame( array(), $this->claimed(), 'no spec names a denial at all' );
	}
}
