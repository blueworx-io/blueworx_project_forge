<?php
/**
 * What a work item holds, and what each field will be needed for.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

/**
 * #97: the field coverage the gates and reports depend on, in one place.
 *
 * The list matters more than it looks. Every requirement in
 * docs/architecture/workflow-state-machine.md has to resolve to a field that
 * exists, or a gate arrives at #105 with nothing to check and quietly passes.
 * So the fields are declared here with the stage that first demands each one,
 * and a test walks the gate specification against this list.
 *
 * Nothing here enforces anything yet. A field being "required at Up Next" is
 * recorded so #105 can enforce it; today it only shapes validation of what may
 * be written.
 */
final class Fields {

	/**
	 * The definition group. Client-editable until the item leaves Documentation
	 * Period (AUTH-2), which is why they are grouped rather than scattered.
	 */
	public const DEFINITION = array(
		'title',
		'problem',
		'scope',
		'non_goals',
		'requirements',
		'acceptance_criteria',
		'references',
	);

	/**
	 * The planning group (WORK-3). Dates become mandatory at Up Next and not
	 * before — an idea with a due date is a guess wearing a commitment's
	 * clothes.
	 */
	public const PLANNING = array(
		'planned_start',
		'planned_due',
		'review_target',
		'release_target',
		'remaining_estimate',
		'priority',
	);

	/**
	 * The commercial group. Set at Triage; COMM-5 decides free-bug from whether
	 * we delivered the thing that broke.
	 */
	public const COMMERCIAL = array(
		'commercial_class',
		'delivered_by_forge',
	);

	/**
	 * The delivery evidence group (WF-6). Recorded at Completed and Released.
	 */
	public const DELIVERY = array(
		'release_method',
		'release_destination',
	);

	/**
	 * Which stage first requires each field, for #105 to enforce.
	 *
	 * Read as: an item may not *leave* this stage without the field. Anything
	 * absent here is optional throughout.
	 *
	 * @var array<string, string>
	 */
	public const REQUIRED_FROM = array(
		'title'               => 'future-idea',
		'problem'             => 'future-idea',
		'commercial_class'    => 'triage',
		'requirements'        => 'documentation-period',
		'acceptance_criteria' => 'documentation-period',
		'planned_start'       => 'up-next',
		'planned_due'         => 'up-next',
		'priority'            => 'up-next',
		'remaining_estimate'  => 'in-development',
		'release_method'      => 'completed',
		'release_destination' => 'completed',
	);

	/**
	 * How an item is classified commercially (COMM-5).
	 */
	public const COMMERCIAL_CLASSES = array( 'chargeable', 'free-bug', 'unclassified' );

	/**
	 * What Released means for this item (WF-6). Recorded at Completed, because
	 * how something is delivered is decided before it is delivered.
	 */
	public const RELEASE_METHODS = array( 'software', 'content', 'design', 'infrastructure', 'non-deployment' );

	/**
	 * Priority, confirmed at Up Next.
	 */
	public const PRIORITIES = array( 'low', 'normal', 'high', 'urgent' );

	/**
	 * Every field a caller may write directly.
	 *
	 * The stage is not among them, deliberately: it is written only by the
	 * transition service, so that no edit can move work sideways past a gate.
	 *
	 * @return array<int, string>
	 */
	public static function writable(): array {
		return array_merge(
			self::DEFINITION,
			self::PLANNING,
			self::COMMERCIAL,
			self::DELIVERY,
			array( 'level', 'work_type', 'parent_id' )
		);
	}
}
