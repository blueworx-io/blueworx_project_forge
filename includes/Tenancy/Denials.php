<?php
/**
 * What must be refused, and by which route.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

/**
 * #91's second half: the explicit denial list from
 * docs/architecture/permission-matrix.md, as data.
 *
 * **The list is the test manifest, not an illustration.** The matrix document
 * says so in as many words: an action missing from here is an untested hole.
 * Keeping it as a list rather than as forty test names means the suites of
 * Milestones 2, 4 and 6 can each walk it and prove they cover their own part —
 * a denial nobody wrote a test for shows up as a gap in a count rather than as
 * nothing at all.
 *
 * Each entry names the routes it could be attempted by, because "refused in the
 * UI" is not refused. The five are the ones the document lists:
 *
 * - **a** a UI control
 * - **b** a direct REST call
 * - **c** a filter or id parameter
 * - **d** a replayed signed request
 * - **e** a sync or webhook event
 *
 * **And each names the milestone whose suite has to prove it** (#135). That
 * field is what turns "an action missing from here is an untested hole" from a
 * sentence in a document into something a test can count. Without it there are
 * two ways a denial goes unproved and they look identical from outside: nobody
 * wrote the test, or the thing being denied is not built yet. The first is a
 * hole; the second is a schedule. Saying which, per denial, means
 * DenialCoverageTest can fail on the first without complaining about the
 * second — and means that when M8 builds packages, the denials waiting on it
 * are already listed rather than remembered.
 */
final class Denials {

	/**
	 * Tenant and site boundary.
	 */
	public const BOUNDARY = 'boundary';

	/**
	 * The workflow, including the client transition lock.
	 */
	public const WORKFLOW = 'workflow';

	/**
	 * Records that must not be rewritten.
	 */
	public const INTEGRITY = 'integrity';

	/**
	 * Money and meetings.
	 */
	public const COMMERCIAL = 'commercial';

	/**
	 * Onboarding and client requests.
	 */
	public const ONBOARDING = 'onboarding';

	/**
	 * Every denial, in the document's order.
	 *
	 * @return array<string, array{area: string, must_refuse: string, routes: array<int, string>, proved_by: string}>
	 */
	public static function all(): array {
		static $denials = null;

		if ( null !== $denials ) {
			return $denials;
		}

		$denials = array(

			// ---- Tenant and site boundary --------------------------------

			'D-1'  => self::of( self::BOUNDARY, 'Read a record belonging to another site', 'b,c,d', 'M6' ),
			'D-2'  => self::of( self::BOUNDARY, 'Read a record belonging to another client', 'b,c,d', 'M6' ),
			'D-3'  => self::of( self::BOUNDARY, 'Enumerate records by sequential or guessed ID', 'b,c', 'M6' ),
			'D-4'  => self::of( self::BOUNDARY, 'Widen a filter beyond the current site', 'a,b,c', 'M6' ),
			'D-5'  => self::of( self::BOUNDARY, "Retrieve another site's record through search", 'a,b,c', 'M6' ),
			'D-6'  => self::of( self::BOUNDARY, "Infer another client's data from counts, totals or availability", 'a,b', 'M6' ),
			'D-7'  => self::of( self::BOUNDARY, 'Act after deactivation, while past attribution stays intact', 'b,d', 'M6' ),
			'D-8'  => self::of( self::BOUNDARY, 'Reach any record using a revoked or rotated site key', 'b,d', 'M6' ),
			'D-9'  => self::of( self::BOUNDARY, 'Replay a captured signed request', 'd', 'M6' ),

			// ---- Workflow ------------------------------------------------

			'D-10' => self::of( self::WORKFLOW, 'Move an item forward a stage', 'a,b,d,e', 'M6' ),
			'D-11' => self::of( self::WORKFLOW, 'Return an item to an earlier stage', 'a,b,d,e', 'M6' ),
			'D-12' => self::of( self::WORKFLOW, 'Enter or exit Blocked', 'a,b,d', 'M6' ),
			'D-13' => self::of( self::WORKFLOW, 'Record any terminal outcome', 'a,b,d', 'M6' ),
			'D-14' => self::of( self::WORKFLOW, 'Approve In Review to Completed', 'a,b,d', 'M6' ),
			'D-15' => self::of( self::WORKFLOW, 'Confirm Completed to Released', 'a,b,d', 'M6' ),
			'D-16' => self::of( self::WORKFLOW, 'Reopen completed or released work', 'a,b,d', 'M6' ),
			'D-17' => self::of( self::WORKFLOW, 'Write a workflow-stage field directly, bypassing the transition service', 'b,c,e', 'M6' ),
			'D-18' => self::of( self::WORKFLOW, 'Complete or mark a gate requirement', 'a,b,d', 'M6' ),
			'D-19' => self::of( self::WORKFLOW, 'Invoke the WF-5 override', 'a,b,d', 'M6' ),

			// ---- Data integrity ------------------------------------------

			'D-20' => self::of( self::INTEGRITY, 'Edit accountability, planning or commercial fields', 'a,b,e', 'M6' ),
			'D-21' => self::of( self::INTEGRITY, 'Edit definition fields after the item leaves Documentation Period', 'a,b,e', 'M6' ),
			'D-22' => self::of( self::INTEGRITY, 'Edit or delete a changelog entry', 'b,e', 'M6' ),
			'D-23' => self::of( self::INTEGRITY, 'Edit or delete an hour ledger entry', 'b,e', 'M8' ),
			'D-24' => self::of( self::INTEGRITY, "Edit a parent's derived status or progress", 'b,e', 'M6' ),
			'D-25' => self::of( self::INTEGRITY, 'Write a stale record version over a newer one', 'b,d,e', 'M6' ),
			'D-26' => self::of( self::INTEGRITY, 'Create a duplicate through a replayed write', 'b,d', 'M6' ),

			// ---- Commercial and meetings ---------------------------------

			'D-27' => self::of( self::COMMERCIAL, 'Assign, upgrade or retire a package', 'a,b', 'M8' ),
			'D-28' => self::of( self::COMMERCIAL, 'Add a top-up or adjust a balance', 'a,b', 'M8' ),
			'D-29' => self::of( self::COMMERCIAL, 'Mark a meeting held, or otherwise trigger meeting hour usage', 'a,b,d', 'M8' ),
			'D-30' => self::of( self::COMMERCIAL, 'Edit a meeting series', 'a,b', 'M8' ),
			'D-31' => self::of( self::COMMERCIAL, 'Confirm an occurrence with insufficient balance', 'a,b', 'M8' ),
			'D-32' => self::of( self::COMMERCIAL, 'Take a balance negative', 'b', 'M8' ),

			// ---- Onboarding and requests ---------------------------------

			'D-33' => self::of( self::ONBOARDING, 'Create, delete or reorder a checklist step', 'a,b', 'M9' ),
			'D-34' => self::of( self::ONBOARDING, 'Approve any step, including a client-owned one', 'a,b,d', 'M9' ),
			'D-35' => self::of( self::ONBOARDING, 'Record a Not Applicable decision', 'a,b', 'M9' ),
			'D-36' => self::of( self::ONBOARDING, 'Edit derived onboarding completion or launch readiness', 'b', 'M9' ),
			'D-37' => self::of( self::ONBOARDING, 'Mark a site Released with a launch-critical step outstanding', 'a,b', 'M9' ),
			'D-38' => self::of( self::ONBOARDING, 'Store a credential in an onboarding step field', 'a,b', 'M9' ),
			'D-39' => self::of( self::ONBOARDING, 'Edit a submitted request after submission', 'a,b', 'M6' ),
			'D-40' => self::of( self::ONBOARDING, 'Convert a request into another site pipeline', 'a,b', 'M6' ),
		);

		return $denials;
	}

	/**
	 * The denials one milestone's suite has to prove.
	 *
	 * @param string $milestone e.g. 'M6'.
	 * @return array<string, array{area: string, must_refuse: string, routes: array<int, string>, proved_by: string}>
	 */
	public static function proved_by( string $milestone ): array {
		return array_filter(
			self::all(),
			static function ( array $denial ) use ( $milestone ): bool {
				return $denial['proved_by'] === $milestone;
			}
		);
	}

	/**
	 * The denials in one area.
	 *
	 * @param string $area One of the area constants.
	 * @return array<string, array{area: string, must_refuse: string, routes: array<int, string>, proved_by: string}>
	 */
	public static function for_area( string $area ): array {
		return array_filter(
			self::all(),
			static function ( array $denial ) use ( $area ): bool {
				return $denial['area'] === $area;
			}
		);
	}

	/**
	 * Whether a denial id is one of the forty.
	 *
	 * @param string $id Denial id.
	 * @return bool
	 */
	public static function exists( string $id ): bool {
		return array_key_exists( $id, self::all() );
	}

	/**
	 * One denial.
	 *
	 * @param string $area        Which block it belongs to.
	 * @param string $must_refuse What must be refused.
	 * @param string $routes      Comma-separated route letters.
	 * @param string $proved_by   The milestone whose suite must prove it.
	 * @return array{area: string, must_refuse: string, routes: array<int, string>, proved_by: string}
	 */
	private static function of( string $area, string $must_refuse, string $routes, string $proved_by ): array {
		return array(
			'area'        => $area,
			'must_refuse' => $must_refuse,
			'routes'      => explode( ',', $routes ),
			'proved_by'   => $proved_by,
		);
	}
}
