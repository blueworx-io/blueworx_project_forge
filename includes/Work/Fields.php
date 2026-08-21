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
	 * The accountability group: who does the work, who checks it, who ships it.
	 *
	 * These are the seats, and they are fields rather than a separate table
	 * because an item has exactly one of each. #112 turns them into authority —
	 * only the named Reviewer approves a review, only the named Deliverer
	 * confirms a release — so a seat left empty is not a tidiness problem, it is
	 * a transition nobody can make.
	 *
	 * The substitutes are the AUTH-4 route, assigned by a Primary administrator
	 * and no one else, and every use of one is marked on the changelog entry so
	 * "who actually approved this" survives the person being away.
	 */
	public const ACCOUNTABILITY = array(
		'primary_user_id',
		'reviewer_id',
		'deliverer_id',
		'hours_primary',
		'hours_review',
		'hours_delivery',
	);

	/**
	 * The planned hours, one per seat (#98).
	 *
	 * With the seats rather than with the plan, because "who is reviewing this"
	 * and "how long we said the review would take" are one conversation and are
	 * set by the same people. M7 plans capacity from these; without them it has
	 * a list of names and no idea what any of them costs.
	 *
	 * A named subset of ACCOUNTABILITY rather than a group of its own, so the
	 * permission question stays "may this person set the accountability fields"
	 * and does not become two questions that can drift apart.
	 */
	public const HOURS = array(
		'hours_primary',
		'hours_review',
		'hours_delivery',
	);

	/**
	 * The substitute seats. Separated from the rest because who may set them is
	 * different: a Primary administrator only.
	 */
	public const SUBSTITUTES = array(
		'reviewer_substitute_id',
		'deliverer_substitute_id',
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
		'primary_user_id'     => 'up-next',
		'reviewer_id'         => 'up-next',
		'deliverer_id'        => 'up-next',
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
			self::ACCOUNTABILITY,
			self::SUBSTITUTES,
			self::PLANNING,
			self::COMMERCIAL,
			self::DELIVERY,
			array( 'level', 'work_type', 'parent_id' )
		);
	}
}
