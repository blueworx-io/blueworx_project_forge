<?php
/**
 * What the brief says has to be true, and which spec slot proves it.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Acceptance;

/**
 * The brief's §16 acceptance criteria, as data.
 *
 * **The list is the test manifest, not an illustration.** M11 is described in
 * the programme design as "the brief's acceptance criteria turned into tests",
 * and until #178 the only record of what those criteria were lived in that
 * document and in four issue descriptions. That is enough to write specs from
 * and not enough to check them against: a criterion nobody wrote a spec for and
 * a criterion nobody wrote down look the same from inside the suite.
 *
 * So each one is written here, in the words it has to be proved in, and
 * AcceptanceCoverageTest counts them.
 *
 * **Each names the programme slot that owes its spec**, for the reason Denials
 * names a milestone: without it there are two ways a criterion goes unproved
 * and they are indistinguishable — nobody wrote the spec, or the thing being
 * asserted is not built yet. The first is a hole, the second is a schedule.
 * M8's five are the commercial ones, which assert rules M8 builds and M8 runs
 * last; M11-4's six are #181's.
 *
 * The slots match the programme's numbering of M11's issues:
 *
 * - **M11-1** workflow and gates (#178)
 * - **M11-2** tenant and client lock (#179)
 * - **M11-3** capacity (#180)
 * - **M11-4** onboarding and operations (#181)
 * - **M8** commercial (#246), split out of M11-3 because M8 runs after M11
 */
final class Criteria {

	/**
	 * The workflow and its gates.
	 */
	public const WORKFLOW = 'workflow';

	/**
	 * Tenancy, and the lock that stops a client moving its own work.
	 */
	public const TENANCY = 'tenancy';

	/**
	 * Capacity across clients.
	 */
	public const CAPACITY = 'capacity';

	/**
	 * Packages, hours and meetings.
	 */
	public const COMMERCIAL = 'commercial';

	/**
	 * Onboarding, the standup, and what the studio runs on day to day.
	 */
	public const OPERATIONS = 'operations';

	/**
	 * Every criterion, in the brief's order.
	 *
	 * @return array<string, array{area: string, must_hold: string, proved_by: string}>
	 */
	public static function all(): array {
		static $criteria = null;

		if ( null !== $criteria ) {
			return $criteria;
		}

		$criteria = array(

			// ---- Workflow and gates --------------------------------------

			'AC-1'  => self::of( self::WORKFLOW, 'Work cannot advance past a stage whose gate has an unmet requirement, and the answer names every requirement that is unmet', 'M11-1' ),
			'AC-2'  => self::of( self::WORKFLOW, 'Every transition is recorded with who moved it, when, and from and to which stage', 'M11-1' ),
			'AC-3'  => self::of( self::WORKFLOW, 'Only the assigned Reviewer can approve In Review to Completed', 'M11-1' ),
			'AC-4'  => self::of( self::WORKFLOW, 'Only the assigned Deliverer can confirm Completed to Released', 'M11-1' ),
			'AC-5'  => self::of( self::WORKFLOW, 'A failed review returns the work to development carrying the feedback that failed it', 'M11-1' ),
			'AC-6'  => self::of( self::WORKFLOW, 'Work leaving Blocked returns to exactly the stage it was in when it was blocked', 'M11-1' ),

			// ---- Tenant and client lock ----------------------------------

			'AC-7'  => self::of( self::TENANCY, 'Work created on a client site appears once on the studio, and a repeated delivery of it creates nothing further', 'M11-2' ),
			'AC-8'  => self::of( self::TENANCY, 'An edit made on the studio reaches that site and no other site', 'M11-2' ),
			'AC-9'  => self::of( self::TENANCY, 'Another client cannot read the record, and cannot infer it exists from any count, filter or absent answer', 'M11-2' ),
			'AC-10' => self::of( self::TENANCY, 'A client cannot move work by a screen control, by a direct signed API call, or by replaying one', 'M11-2' ),

			// ---- Capacity ------------------------------------------------

			'AC-11' => self::of( self::CAPACITY, "A person's commitments across every client are counted once, and the total is the same figure the studio plans against", 'M11-3' ),
			'AC-12' => self::of( self::CAPACITY, 'Work that exceeds available capacity is refused, and goes ahead only where somebody recorded a reason', 'M11-3' ),

			// ---- Commercial ----------------------------------------------

			'AC-13' => self::of( self::COMMERCIAL, 'Assigning a package produces exactly the annual or pro-rata hours its terms say, across a leap-year boundary', 'M8' ),
			'AC-14' => self::of( self::COMMERCIAL, 'Hours are reserved when work is planned, spent when it starts, and released when it is cancelled, without drift', 'M8' ),
			'AC-15' => self::of( self::COMMERCIAL, 'A meeting reserves its hours, spends them only when it is held, and releases them when it is not', 'M8' ),
			'AC-16' => self::of( self::COMMERCIAL, 'A client with no package is refused chargeable work at the API, and can still report a bug and reach Sales', 'M8' ),
			'AC-17' => self::of( self::COMMERCIAL, 'The balance the client is shown and the balance the studio is shown are the same figure, and both reconcile to the ledger', 'M8' ),

			// ---- Onboarding and operations -------------------------------

			'AC-18' => self::of( self::OPERATIONS, "Work joins and leaves the day's list by the rules, and nothing that needs attention is left off it", 'M11-4' ),
			'AC-19' => self::of( self::OPERATIONS, 'A client submits an onboarding step and the studio review comes back to them, with its decision and its reason', 'M11-4' ),
			'AC-20' => self::of( self::OPERATIONS, 'A site cannot be released while a launch-critical onboarding step is outstanding', 'M11-4' ),
			'AC-21' => self::of( self::OPERATIONS, 'A notification is delivered exactly once, and a repeated event does not send it again', 'M11-4' ),
			'AC-22' => self::of( self::OPERATIONS, 'The same work reconciles across the board, the timeline and the reports', 'M11-4' ),
			'AC-23' => self::of( self::OPERATIONS, 'An event that failed or was delayed is visible as such, and can be retried to completion', 'M11-4' ),
		);

		return $criteria;
	}

	/**
	 * The criteria one programme slot owes a spec for.
	 *
	 * @param string $slot e.g. 'M11-1'.
	 * @return array<string, array{area: string, must_hold: string, proved_by: string}>
	 */
	public static function proved_by( string $slot ): array {
		return array_filter(
			self::all(),
			static function ( array $criterion ) use ( $slot ): bool {
				return $criterion['proved_by'] === $slot;
			}
		);
	}

	/**
	 * The criteria in one area.
	 *
	 * @param string $area One of the area constants.
	 * @return array<string, array{area: string, must_hold: string, proved_by: string}>
	 */
	public static function for_area( string $area ): array {
		return array_filter(
			self::all(),
			static function ( array $criterion ) use ( $area ): bool {
				return $criterion['area'] === $area;
			}
		);
	}

	/**
	 * Whether a criterion id is one of the brief's.
	 *
	 * @param string $id Criterion id.
	 * @return bool
	 */
	public static function exists( string $id ): bool {
		return array_key_exists( $id, self::all() );
	}

	/**
	 * One criterion.
	 *
	 * @param string $area      Which block it belongs to.
	 * @param string $must_hold What has to be true.
	 * @param string $proved_by The programme slot whose specs prove it.
	 * @return array{area: string, must_hold: string, proved_by: string}
	 */
	private static function of( string $area, string $must_hold, string $proved_by ): array {
		return array(
			'area'      => $area,
			'must_hold' => $must_hold,
			'proved_by' => $proved_by,
		);
	}
}
