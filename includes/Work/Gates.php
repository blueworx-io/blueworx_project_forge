<?php
/**
 * What has to be true before work leaves a stage.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

/**
 * #105. Every exit gate in docs/architecture/workflow-state-machine.md, as
 * structured requirements rather than as prose somebody has to remember.
 *
 * **A requirement is never free text.** Each one names how it is satisfied —
 * a field on the item, a completion record, or a check the system performs —
 * and each completion carries who did it and when (Work\GateRecords refuses one
 * that does not). That is the whole reason the gates are data: a gate written
 * as a paragraph in a controller can be satisfied by anything anybody decides
 * looks close enough, and there is nothing afterwards to show what was checked.
 *
 * The evaluation returns **every** unmet requirement, never the first (#107).
 * A person told about one missing thing at a time fixes it, resubmits, and is
 * refused again for the next.
 */
final class Gates {

	/**
	 * Satisfied by a field on the item being filled in.
	 */
	public const BY_FIELD = 'field';

	/**
	 * Satisfied by somebody recording a completion against it.
	 */
	public const BY_RECORD = 'record';

	/**
	 * Satisfied — or not — by a check the system runs for itself.
	 */
	public const BY_SYSTEM = 'system';

	/**
	 * Who may mark a requirement complete. The legend from the specification.
	 */
	public const ANY    = 'ANY';
	public const PU     = 'PU';
	public const REV    = 'REV';
	public const DEL    = 'DEL';
	public const APR    = 'APR';
	public const SYSTEM = 'System';

	/**
	 * The Design Process escape hatch: an approved Not Applicable decision for
	 * non-UI work satisfies G-DESIGN whole, and is recorded as a requirement in
	 * its own right rather than as a stage somebody skipped.
	 */
	public const DESIGN_NOT_APPLICABLE = 'G-DESIGN-NA';

	/**
	 * Every gate, keyed by name, each an ordered list of requirements.
	 *
	 * The shape of a requirement:
	 *
	 * - `id`           what the failure response names it by, e.g. G-UP-NEXT-4.
	 * - `label`        how it reads on screen.
	 * - `type`         text, enum, reference, checklist, approval, numeric,
	 *                  boolean, attachment, date, timestamp, action or check.
	 * - `evidence`     whether a link or attachment is required with it.
	 * - `who`          which capability may complete it.
	 * - `satisfied_by` the sentence the refusal shows: what to actually do.
	 * - `by`           field, record or system.
	 * - `fields`       for `by: field`, the item fields that must all be filled.
	 * - `check`        for `by: system`, which check answers it.
	 * - `deferred`     a system check whose subject is not built yet, reported
	 *                  as a result but never used to refuse a move.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function all(): array {
		/*
		 * Built once. Evaluating one move asks for this several times over —
		 * exists(), requirements(), gate_of() for each record — and it is a
		 * hundred-odd nested arrays with no state in it. Nothing here is
		 * translated, so there is no locale for a cached copy to be wrong for.
		 */
		static $gates = null;

		if ( null !== $gates ) {
			return $gates;
		}

		$gates = array(
			'G-CREATE'          => array(
				self::field( 'G-CREATE-1', 'Title', 'text', array( 'title' ), 'Give the work a title.' ),
				self::field( 'G-CREATE-2', 'Site scope', 'reference', array( 'client_site_id' ), 'Choose the client site this work belongs to.' ),
				self::field( 'G-CREATE-3', 'Work type', 'enum', array( 'work_type' ), 'Choose Feature, Bug, Feedback or Task.' ),
				self::system( 'G-CREATE-4', 'Creator and source', 'creator', 'Recorded when the item is created.' ),
			),
			'G-FUTURE-IDEA'     => array(
				self::field( 'G-FUTURE-IDEA-1', 'Problem or opportunity', 'text', array( 'problem' ), 'Describe the problem or opportunity this addresses.' ),
				self::record( 'G-FUTURE-IDEA-2', 'Site or portfolio scope confirmed', 'reference', 'Confirm which site or portfolio this is scoped to.' ),
				self::record( 'G-FUTURE-IDEA-3', 'Source recorded', 'enum', 'Record where this came from: client request, internal, bug report or meeting.' ),
				self::record( 'G-FUTURE-IDEA-4', 'Submitted for triage', 'action', 'Submit the item for triage.' ),
			),
			'G-TRIAGE'          => array(
				self::field( 'G-TRIAGE-1', 'Work type confirmed', 'enum', array( 'work_type' ), 'Confirm the work type.', self::APR ),
				self::record( 'G-TRIAGE-2', 'Site confirmed', 'reference', 'Confirm the site this work is scoped to.', self::APR ),
				self::record( 'G-TRIAGE-3', 'Parent chosen or created', 'reference', 'Choose a parent item, create one, or record that this sits at the top.' ),
				self::field( 'G-TRIAGE-4', 'Priority', 'enum', array( 'priority' ), 'Set a priority.' ),
				self::field( 'G-TRIAGE-5', 'Scope summary', 'text', array( 'scope' ), 'Summarise the scope.' ),
				self::record( 'G-TRIAGE-6', 'Duplicate check completed', 'checklist', 'Check for duplicates, and link the surviving item if there is one.' ),
				self::record( 'G-TRIAGE-7', 'Triage outcome recorded', 'enum', 'Record the triage outcome: proceed, rejected, duplicate or deferred.', self::APR ),
				self::classification( 'G-TRIAGE-8', 'Commercial classification', 'Classify the work as chargeable or a free bug.', self::APR ),
			),
			'G-BUG-TRACKING'    => array(
				self::record( 'G-BUG-TRACKING-1', 'Bug classification confirmed', 'enum', 'Confirm how the bug is classified.' ),
				self::record( 'G-BUG-TRACKING-2', 'Expected versus actual result', 'text', 'Record what was expected and what actually happened.' ),
				self::record( 'G-BUG-TRACKING-3', 'Reproduction steps', 'text', 'Record the steps that reproduce it.' ),
				self::record( 'G-BUG-TRACKING-4', 'Environment and version', 'text', 'Record the environment and version it was seen on.' ),
				self::evidence( 'G-BUG-TRACKING-5', 'Evidence attached', 'attachment', 'Attach evidence of the bug, or link to it.' ),
				self::record( 'G-BUG-TRACKING-6', 'Impact and severity', 'enum', 'Record the impact and severity.' ),
				self::record( 'G-BUG-TRACKING-7', 'Initial diagnosis', 'text', 'Record an initial diagnosis.' ),
				self::record( 'G-BUG-TRACKING-8', 'Delivered-by-Forge determination', 'boolean', 'Determine whether Forge delivered the thing that broke.', self::APR ),
			),
			'G-DOCUMENTATION'   => array(
				self::field( 'G-DOCUMENTATION-1', 'Problem statement', 'text', array( 'problem' ), 'Write the problem statement.' ),
				self::field( 'G-DOCUMENTATION-2', 'Scope', 'text', array( 'scope' ), 'Write the scope.' ),
				self::field( 'G-DOCUMENTATION-3', 'Non-goals', 'text', array( 'non_goals' ), 'Write what this deliberately does not cover.' ),
				self::field( 'G-DOCUMENTATION-4', 'Requirements', 'checklist', array( 'requirements' ), 'List the requirements.' ),
				self::field( 'G-DOCUMENTATION-5', 'Acceptance criteria', 'checklist', array( 'acceptance_criteria' ), 'Write at least one acceptance criterion.' ),
				self::record( 'G-DOCUMENTATION-6', 'Dependencies', 'reference', 'Record the dependencies, or confirm there are none.' ),
				self::record( 'G-DOCUMENTATION-7', 'Affected sites and data', 'reference', 'Record which sites and data this affects.' ),
				self::field( 'G-DOCUMENTATION-8', 'Reference material', 'reference', array( 'references' ), 'Link the reference material.' ),
				self::record( 'G-DOCUMENTATION-9', 'Documentation approval', 'approval', 'Have the documentation approved by somebody other than the item\'s own Primary User.', self::APR ),
			),
			'G-TECHNICAL-AUDIT' => array(
				self::record( 'G-TECHNICAL-AUDIT-1', 'Architecture and implementation assessment', 'text', 'Assess the architecture and how it would be implemented.' ),
				self::record( 'G-TECHNICAL-AUDIT-2', 'Dependencies confirmed', 'reference', 'Confirm the dependencies.' ),
				self::record( 'G-TECHNICAL-AUDIT-3', 'Data and sync impact', 'text', 'Record the data and sync impact.' ),
				self::record( 'G-TECHNICAL-AUDIT-4', 'Security and privacy impact', 'text', 'Record the security and privacy impact.' ),
				self::record( 'G-TECHNICAL-AUDIT-5', 'Test approach', 'text', 'Record how this will be tested.' ),
				self::record( 'G-TECHNICAL-AUDIT-6', 'Estimate range', 'numeric', 'Record a low and high estimate in hours.' ),
				self::record( 'G-TECHNICAL-AUDIT-7', 'Risks', 'checklist', 'List the technical risks.' ),
				self::record( 'G-TECHNICAL-AUDIT-8', 'Technical approval', 'approval', 'Have the audit approved by the technical approver.', self::APR ),
			),
			'G-DESIGN'          => array(
				self::evidence( 'G-DESIGN-1', 'Approved design artifact', 'attachment', 'Attach or link the approved design.' ),
				self::record( 'G-DESIGN-2', 'Responsive states', 'checklist', 'Record the responsive states.' ),
				self::record( 'G-DESIGN-3', 'Empty, loading, error and permission-denied states', 'checklist', 'Record all four states: empty, loading, error and permission-denied.' ),
				self::record( 'G-DESIGN-4', 'Accessibility considerations', 'checklist', 'Record the accessibility considerations.' ),
				self::record( 'G-DESIGN-5', 'Design approval', 'approval', 'Have the design approved by the design approver.', self::APR ),
			),
			'G-UP-NEXT'         => array(

				/*
				 * The three seats are fields rather than records, because #112
				 * has to read them back: only the named Reviewer approves a
				 * review, and a completion record saying "reviewer assigned"
				 * does not say who.
				 */
				self::field( 'G-UP-NEXT-1', 'Primary User assigned', 'reference', array( 'primary_user_id' ), 'Assign the Primary User.' ),
				self::field( 'G-UP-NEXT-2', 'Reviewer assigned', 'reference', array( 'reviewer_id' ), 'Assign a Reviewer, who must be somebody other than the Primary User unless they hold Principal.' ),
				self::field( 'G-UP-NEXT-3', 'Deliverer assigned', 'reference', array( 'deliverer_id' ), 'Assign the Deliverer.' ),
				self::record( 'G-UP-NEXT-4', 'Planned hours per role', 'numeric', 'Enter planned hours for Primary User, Reviewer and Deliverer.' ),
				self::field( 'G-UP-NEXT-5', 'Planned start and due date', 'date', array( 'planned_start', 'planned_due' ), 'Set a planned start and a planned due date.' ),
				self::field( 'G-UP-NEXT-6', 'Priority confirmed', 'enum', array( 'priority' ), 'Confirm the priority.' ),
				self::record( 'G-UP-NEXT-7', 'Dependencies confirmed', 'reference', 'Confirm the dependencies.' ),
				self::deferred( 'G-UP-NEXT-8', 'Capacity check', 'capacity', 'The capacity model arrives with CAP-4; until it does this reports as passed.' ),
				self::deferred( 'G-UP-NEXT-9', 'Support-hours check', 'support_hours', 'The support-hours ledger arrives with COMM-3; until it does this reports as passed.' ),
			),
			'G-IN-DEVELOPMENT'  => array(
				self::record( 'G-IN-DEVELOPMENT-1', 'Requirements confirmed implemented', 'checklist', 'Confirm each documented requirement is implemented.', self::PU ),
				self::evidence( 'G-IN-DEVELOPMENT-2', 'Work evidence', 'attachment', 'Attach or link evidence of the work.', self::PU ),
				self::evidence( 'G-IN-DEVELOPMENT-3', 'Test evidence', 'attachment', 'Attach or link evidence that it was tested.', self::PU ),
				self::field( 'G-IN-DEVELOPMENT-4', 'Remaining estimate', 'numeric', array( 'remaining_estimate' ), 'Enter the remaining estimate in hours.', self::PU ),
				self::record( 'G-IN-DEVELOPMENT-5', 'Completion checklist', 'checklist', 'Complete the completion checklist.', self::PU ),
				self::record( 'G-IN-DEVELOPMENT-6', 'Submitted to Reviewer', 'action', 'Submit the work to its Reviewer.', self::PU ),
			),
			'G-IN-REVIEW'       => array(
				self::record( 'G-IN-REVIEW-1', 'Review checklist completed', 'checklist', 'Complete the review checklist.', self::REV ),
				self::record( 'G-IN-REVIEW-2', 'Every acceptance criterion confirmed', 'checklist', 'Confirm every acceptance criterion.', self::REV ),
				self::record( 'G-IN-REVIEW-3', 'All feedback resolved or returned', 'checklist', 'Resolve every open piece of feedback, or return the item.', self::REV ),
				self::record( 'G-IN-REVIEW-4', 'Review approval', 'approval', 'Approve the review as the assigned Reviewer or an authorised substitute.', self::REV ),
				self::record( 'G-IN-REVIEW-5', 'Post-review hours adjustment', 'numeric', 'Record any post-review hours adjustment, with its reason.', self::REV ),
			),
			'G-COMPLETED'       => array(
				self::system( 'G-COMPLETED-1', 'Review approval preserved', 'review_approval', 'The review approval from this cycle has to still be on the item.' ),
				self::field( 'G-COMPLETED-2', 'Release method', 'enum', array( 'release_method' ), 'Choose how this is released: software, content, design, infrastructure or non-deployment.', self::DEL ),
				self::field( 'G-COMPLETED-3', 'Target environment, version or destination', 'text', array( 'release_destination' ), 'Record where this is going.', self::DEL ),
				self::record( 'G-COMPLETED-4', 'Release window', 'date', 'Set the release window.', self::DEL ),
				self::record( 'G-COMPLETED-5', 'Delivery checklist', 'checklist', 'Complete the delivery checklist.', self::DEL ),
				self::record( 'G-COMPLETED-6', 'Dependencies confirmed ready', 'reference', 'Confirm the dependencies are ready.', self::DEL ),
				self::record( 'G-COMPLETED-7', 'Release notes', 'text', 'Write the release notes.', self::DEL ),
				self::system( 'G-COMPLETED-8', 'Every child item Completed', 'children_completed', 'Every item beneath this one has to reach Completed first.' ),
			),
			'G-RELEASED'        => array(
				self::record( 'G-RELEASED-1', 'Release date and time', 'timestamp', 'Record when it was released.', self::DEL ),
				self::record( 'G-RELEASED-2', 'Environment and version, or handover destination', 'text', 'Record where it went.', self::DEL ),
				self::evidence( 'G-RELEASED-3', 'Release evidence', 'attachment', 'Attach or link evidence of the release.', self::DEL ),
				self::deferred( 'G-RELEASED-4', 'Client communication status', 'client_communication', 'The NOTIF-2 confirmation arrives with the notification work; until it does this reports as passed.' ),
				self::record( 'G-RELEASED-5', 'Post-release check result', 'checklist', 'Record the post-release check.', self::DEL ),
			),
			'G-BLOCKED-ENTRY'   => array(
				self::record( 'G-BLOCKED-ENTRY-1', 'Blocker reason', 'text', 'Say what is blocking this.' ),
				self::record( 'G-BLOCKED-ENTRY-2', 'Blocker owner', 'reference', 'Name who owns the blocker.' ),
				self::record( 'G-BLOCKED-ENTRY-3', 'Dependency', 'reference', 'Name what this is waiting on.' ),
				self::record( 'G-BLOCKED-ENTRY-4', 'Target resolution date', 'date', 'Set a target date for the blocker clearing.' ),
				self::record( 'G-BLOCKED-ENTRY-5', 'Next action', 'text', 'Say what the next action is.' ),
				self::system( 'G-BLOCKED-ENTRY-6', 'Prior stage stored', 'prior_stage', 'Recorded by the move itself.' ),
			),
			'G-BLOCKED-EXIT'    => array(
				self::record( 'G-BLOCKED-EXIT-1', 'Resolution note', 'text', 'Say how the blocker was resolved.' ),
				self::system( 'G-BLOCKED-EXIT-2', 'Return to the stored prior stage', 'prior_stage', 'Enforced by the move: there is no target to choose.' ),
				self::system( 'G-BLOCKED-EXIT-3', 'Elapsed blocked time retained', 'blocked_elapsed', 'Recorded by the move itself.' ),
			),
		);

		return $gates;
	}

	/**
	 * Whether a gate exists.
	 *
	 * @param string $gate Gate name.
	 * @return bool
	 */
	public static function exists( string $gate ): bool {
		return array_key_exists( $gate, self::all() );
	}

	/**
	 * One gate's requirements.
	 *
	 * @param string $gate Gate name.
	 * @return array<int, array<string, mixed>>
	 */
	public static function requirements( string $gate ): array {
		return self::all()[ $gate ] ?? array();
	}

	/**
	 * One requirement, wherever it lives.
	 *
	 * @param string $requirement_id Requirement id.
	 * @return array<string, mixed>|null
	 */
	public static function requirement( string $requirement_id ): ?array {
		if ( self::DESIGN_NOT_APPLICABLE === $requirement_id ) {
			return self::record(
				self::DESIGN_NOT_APPLICABLE,
				'Design Not Applicable',
				'approval',
				'Approve a Not Applicable decision, with its reason, for non-UI work.',
				self::APR
			);
		}

		foreach ( self::all() as $requirements ) {
			foreach ( $requirements as $requirement ) {
				if ( $requirement['id'] === $requirement_id ) {
					return $requirement;
				}
			}
		}

		return null;
	}

	/**
	 * Which gate a requirement belongs to.
	 *
	 * @param string $requirement_id Requirement id.
	 * @return string '' when there is no such requirement.
	 */
	public static function gate_of( string $requirement_id ): string {
		if ( self::DESIGN_NOT_APPLICABLE === $requirement_id ) {
			return 'G-DESIGN';
		}

		foreach ( self::all() as $gate => $requirements ) {
			foreach ( $requirements as $requirement ) {
				if ( $requirement['id'] === $requirement_id ) {
					return $gate;
				}
			}
		}

		return '';
	}

	/**
	 * Evaluates a gate against an item.
	 *
	 * Returns everything, not the first failure: `unmet` is every requirement
	 * still outstanding, and `checks` is every system result — which are always
	 * both reported even when one of them passed, because G-UP-NEXT #8 and #9
	 * are evaluated independently and a person needs to see both.
	 *
	 * @param string                             $gate    Gate name.
	 * @param array<string, mixed>               $item    The item, as read.
	 * @param array<string, array<string,mixed>> $records Completion records for
	 *                                                    this cycle and attempt,
	 *                                                    keyed by requirement id.
	 * @param array<string, mixed>               $context Anything a system check
	 *                                                     needs: children.
	 * @return array{unmet: array<int, array<string, mixed>>, checks: array<int, array<string, mixed>>}
	 */
	public static function evaluate( string $gate, array $item, array $records, array $context = array() ): array {
		$unmet  = array();
		$checks = array();

		/*
		 * G-DESIGN is the one gate with an alternative rather than a list. An
		 * approved Not Applicable decision for non-UI work satisfies it whole,
		 * and is a recorded approval with a reason — not a stage anybody got to
		 * skip quietly.
		 */
		if ( 'G-DESIGN' === $gate && isset( $records[ self::DESIGN_NOT_APPLICABLE ] ) ) {
			return array(
				'unmet'  => array(),
				'checks' => array(),
			);
		}

		foreach ( self::requirements( $gate ) as $requirement ) {
			if ( self::BY_SYSTEM === $requirement['by'] ) {
				$passed   = self::check( (string) $requirement['check'], $item, $records, $context );
				$checks[] = array(
					'id'     => $requirement['id'],
					'label'  => $requirement['label'],
					'result' => $passed ? 'pass' : 'fail',
					'note'   => empty( $requirement['deferred'] ) ? '' : (string) $requirement['satisfied_by'],
				);

				// A deferred check reports its result and never refuses a move:
				// the thing it would check does not exist yet, and a gate that
				// fails on a subject nobody can satisfy is a stuck board.
				if ( $passed || ! empty( $requirement['deferred'] ) ) {
					continue;
				}

				$unmet[] = self::as_unmet( $requirement );
				continue;
			}

			if ( self::satisfied( $requirement, $item, $records ) ) {
				continue;
			}

			$unmet[] = self::as_unmet( $requirement );
		}

		return array(
			'unmet'  => $unmet,
			'checks' => $checks,
		);
	}

	/**
	 * Whether one requirement is met.
	 *
	 * @param array<string, mixed>               $requirement Requirement.
	 * @param array<string, mixed>               $item        The item.
	 * @param array<string, array<string,mixed>> $records     Completion records.
	 * @return bool
	 */
	public static function satisfied( array $requirement, array $item, array $records ): bool {
		if ( self::BY_RECORD === $requirement['by'] ) {
			return isset( $records[ $requirement['id'] ] );
		}

		if ( self::BY_FIELD !== $requirement['by'] ) {
			return true;
		}

		foreach ( (array) $requirement['fields'] as $field ) {
			if ( ! self::filled( $field, $item ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a field holds an answer rather than its empty default.
	 *
	 * @param string               $field Field name.
	 * @param array<string, mixed> $item  The item.
	 * @return bool
	 */
	private static function filled( string $field, array $item ): bool {
		$value = $item[ $field ] ?? '';

		if ( 'commercial_class' === $field ) {
			// 'unclassified' is the column's default, so it means nobody has
			// classified it — the opposite of an answer.
			return '' !== (string) $value && 'unclassified' !== (string) $value;
		}

		if ( 'remaining_estimate' === $field ) {
			return (float) $value > 0.0;
		}

		return '' !== trim( (string) $value );
	}

	/**
	 * Runs one system check.
	 *
	 * @param string                             $check   Which check.
	 * @param array<string, mixed>               $item    The item.
	 * @param array<string, array<string,mixed>> $records Completion records.
	 * @param array<string, mixed>               $context children, and whatever
	 *                                                    later checks need.
	 * @return bool
	 */
	private static function check( string $check, array $item, array $records, array $context ): bool {
		switch ( $check ) {
			case 'creator':
				return 0 < (int) ( $item['created_by'] ?? 0 );

			case 'review_approval':
				return isset( $records['G-IN-REVIEW-4'] );

			case 'children_completed':
				foreach ( (array) ( $context['children'] ?? array() ) as $child ) {
					if ( ! in_array( (string) $child['stage'], array( 'completed', 'released' ), true ) ) {
						return false;
					}
				}

				return true;

			case 'prior_stage':
			case 'blocked_elapsed':
				// Both are written by the move itself, inside the same
				// transaction. There is no state in which the move happened and
				// these did not.
				return true;

			default:
				// A deferred check. It reports a result and refuses nothing.
				return true;
		}
	}

	/**
	 * A requirement in the shape the failure response and the UI render.
	 *
	 * @param array<string, mixed> $requirement Requirement.
	 * @return array<string, mixed>
	 */
	private static function as_unmet( array $requirement ): array {
		return array(
			'id'           => $requirement['id'],
			'label'        => $requirement['label'],
			'satisfied_by' => $requirement['satisfied_by'],
			'type'         => $requirement['type'],
			'evidence'     => $requirement['evidence'],
			'who'          => $requirement['who'],
			// How it is satisfied travels with it, so a screen knows whether to
			// offer a control or point at a field further up the form. Without
			// it every renderer guesses from the type, and they guess
			// differently.
			'by'           => $requirement['by'],
			'fields'       => $requirement['fields'],
		);
	}

	/**
	 * A requirement satisfied by a field on the item.
	 *
	 * @param string            $id           Requirement id.
	 * @param string            $label        How it reads.
	 * @param string            $type         Requirement type.
	 * @param array<int,string> $fields       Fields that must all be filled.
	 * @param string            $satisfied_by What to do about it.
	 * @param string            $who          Who may complete it.
	 * @return array<string, mixed>
	 */
	private static function field( string $id, string $label, string $type, array $fields, string $satisfied_by, string $who = self::ANY ): array {
		return array(
			'id'           => $id,
			'label'        => $label,
			'type'         => $type,
			'evidence'     => false,
			'who'          => $who,
			'satisfied_by' => $satisfied_by,
			'by'           => self::BY_FIELD,
			'fields'       => $fields,
			'check'        => '',
		);
	}

	/**
	 * A requirement satisfied by somebody recording its completion.
	 *
	 * @param string $id           Requirement id.
	 * @param string $label        How it reads.
	 * @param string $type         Requirement type.
	 * @param string $satisfied_by What to do about it.
	 * @param string $who          Who may complete it.
	 * @return array<string, mixed>
	 */
	private static function record( string $id, string $label, string $type, string $satisfied_by, string $who = self::ANY ): array {
		return array(
			'id'           => $id,
			'label'        => $label,
			'type'         => $type,
			'evidence'     => false,
			'who'          => $who,
			'satisfied_by' => $satisfied_by,
			'by'           => self::BY_RECORD,
			'fields'       => array(),
			'check'        => '',
		);
	}

	/**
	 * A recorded requirement that will not complete without a link or file.
	 *
	 * @param string $id           Requirement id.
	 * @param string $label        How it reads.
	 * @param string $type         Requirement type.
	 * @param string $satisfied_by What to do about it.
	 * @param string $who          Who may complete it.
	 * @return array<string, mixed>
	 */
	private static function evidence( string $id, string $label, string $type, string $satisfied_by, string $who = self::ANY ): array {
		$requirement             = self::record( $id, $label, $type, $satisfied_by, $who );
		$requirement['evidence'] = true;

		return $requirement;
	}

	/**
	 * The commercial classification, which is a field but whose default means
	 * "nobody has said".
	 *
	 * @param string $id           Requirement id.
	 * @param string $label        How it reads.
	 * @param string $satisfied_by What to do about it.
	 * @param string $who          Who may complete it.
	 * @return array<string, mixed>
	 */
	private static function classification( string $id, string $label, string $satisfied_by, string $who ): array {
		return self::field( $id, $label, 'enum', array( 'commercial_class' ), $satisfied_by, $who );
	}

	/**
	 * A requirement the system answers for itself.
	 *
	 * @param string $id           Requirement id.
	 * @param string $label        How it reads.
	 * @param string $check        Which check answers it.
	 * @param string $satisfied_by What it means.
	 * @return array<string, mixed>
	 */
	private static function system( string $id, string $label, string $check, string $satisfied_by ): array {
		return array(
			'id'           => $id,
			'label'        => $label,
			'type'         => 'check',
			'evidence'     => false,
			'who'          => self::SYSTEM,
			'satisfied_by' => $satisfied_by,
			'by'           => self::BY_SYSTEM,
			'fields'       => array(),
			'check'        => $check,
		);
	}

	/**
	 * A system check whose subject is not built yet.
	 *
	 * It is here rather than absent on purpose. The specification says both the
	 * capacity and support-hours results are always reported, so they exist in
	 * the response from the first day and gain teeth when CAP-4 and COMM-3
	 * arrive — rather than being remembered and added later, which is how a
	 * requirement goes missing.
	 *
	 * @param string $id    Requirement id.
	 * @param string $label How it reads.
	 * @param string $check Which check will answer it.
	 * @param string $note  Why it does not refuse anything yet.
	 * @return array<string, mixed>
	 */
	private static function deferred( string $id, string $label, string $check, string $note ): array {
		$requirement             = self::system( $id, $label, $check, $note );
		$requirement['deferred'] = true;

		return $requirement;
	}
}
