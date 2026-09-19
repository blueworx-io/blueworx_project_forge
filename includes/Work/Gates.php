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
	 * Worked out from what the task already holds (2026-09-18). Nothing is
	 * recorded: the row shows a tick when the task meets it and what would
	 * meet it when not. The resolver runs against the item and a context the
	 * transition service supplies, so this class stays free of the database.
	 */
	public const BY_AUTO = 'auto';

	/**
	 * Where an item came from (G-FUTURE-IDEA-3).
	 */
	public const SOURCES = array(
		'client-request' => 'Client request',
		'internal'       => 'Internal',
		'bug-report'     => 'Bug report',
		'meeting'        => 'Meeting',
	);

	/**
	 * How triage ended (G-TRIAGE-7). Everything but Proceed hands over to
	 * End it, which records the outcome itself.
	 */
	public const TRIAGE_OUTCOMES = array(
		'proceed'   => 'Proceed',
		'rejected'  => 'Rejected',
		'duplicate' => 'Duplicate',
		'deferred'  => 'Deferred',
	);

	/**
	 * What kind of bug it is (G-BUG-TRACKING-1).
	 */
	public const BUG_CLASSES = array(
		'broken-feature' => 'Broken feature',
		'regression'     => 'Regression',
		'content-data'   => 'Content or data',
		'performance'    => 'Performance',
		'security'       => 'Security',
	);

	/**
	 * How bad it is (G-BUG-TRACKING-6).
	 */
	public const SEVERITIES = array(
		'low'      => 'Low',
		'medium'   => 'Medium',
		'high'     => 'High',
		'critical' => 'Critical',
	);

	/**
	 * How far it reaches (G-DOCUMENTATION-7).
	 */
	public const AFFECTED = array(
		'this-site'     => 'This site only',
		'several-sites' => 'More than one site',
	);

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
	 * - `control`      for `by: record`, how a screen asks: a `pick` (a
	 *                  dropdown on the row) or a `box` (a box on the task).
	 * - `options`      for a pick, the fixed choices, value and label.
	 * - `source`       for a pick, what a screen appends after the fixed
	 *                  choices: the site's other `items`, or `people`.
	 * - `input`        for a box, what kind: text, number, date, datetime
	 *                  or range.
	 * - `auto`         for `by: record`, a resolver that also satisfies it
	 *                  from the task (a parent set, a checklist all ticked)
	 *                  without a record; for `by: auto`, the resolver itself
	 *                  is in `check`.
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
				self::field( 'G-FUTURE-IDEA-1', 'Item description', 'text', array( 'problem' ), 'Describe the problem or opportunity this addresses.' ),
				self::field( 'G-FUTURE-IDEA-2', 'Site confirmed', 'reference', array( 'client_site_id' ), 'The task has a site.' ),
				self::pick( 'G-FUTURE-IDEA-3', 'Source', self::SOURCES, 'Choose where this came from.' ),
				self::system( 'G-FUTURE-IDEA-4', 'Submitted for triage', 'submission', 'Recorded by the move itself.' ),
				// The two people are named before triage (2026-09-19), so the
				// reviewer's approvals down the line have somebody to belong to.
				self::field( 'G-FUTURE-IDEA-5', 'Doing the work assigned', 'reference', array( 'primary_user_id' ), 'Say who is doing the work.' ),
				self::field( 'G-FUTURE-IDEA-6', 'Reviewing it assigned', 'reference', array( 'reviewer_id' ), 'Say who is reviewing it, somebody other than the person doing the work.' ),
			),
			'G-TRIAGE'          => array(
				self::field( 'G-TRIAGE-1', 'Work type confirmed', 'enum', array( 'work_type' ), 'Confirm the work type.', self::APR ),
				self::field( 'G-TRIAGE-2', 'Site confirmed', 'reference', array( 'client_site_id' ), 'The task has a site.', self::APR ),
				self::field( 'G-TRIAGE-4', 'Priority', 'enum', array( 'priority' ), 'Set a priority.' ),
				self::pick( 'G-TRIAGE-6', 'Duplicate check', array( 'none' => 'No duplicate found' ), 'Say whether this duplicates another item.', self::ANY, 'items' ),
				self::pick( 'G-TRIAGE-7', 'Triage outcome', self::TRIAGE_OUTCOMES, 'Choose the triage outcome.', self::APR ),
				self::classification( 'G-TRIAGE-8', 'Who pays', 'Choose who pays for this.', self::APR ),
			),
			'G-BUG-TRACKING'    => array(
				self::pick( 'G-BUG-TRACKING-1', 'Bug classification', self::BUG_CLASSES, 'Choose how the bug is classified.' ),
				self::box( 'G-BUG-TRACKING-2', 'Expected versus actual result', 'text', 'Fill in what was expected and what actually happened.' ),
				self::box( 'G-BUG-TRACKING-3', 'Reproduction steps', 'text', 'Fill in the steps that reproduce it.' ),
				self::box( 'G-BUG-TRACKING-4', 'Environment and version', 'text', 'Fill in the environment and version it was seen on.' ),
				self::evidence( 'G-BUG-TRACKING-5', 'Evidence attached', 'Add a comment with a link to the evidence.' ),
				self::pick( 'G-BUG-TRACKING-6', 'Impact and severity', self::SEVERITIES, 'Choose the impact and severity.' ),
				self::box( 'G-BUG-TRACKING-7', 'Initial diagnosis', 'text', 'Fill in an initial diagnosis.' ),
				self::classification( 'G-BUG-TRACKING-8', 'Delivered by Forge', 'Choose who pays: a site bug is one Forge delivered.', self::APR ),
			),
			'G-DOCUMENTATION'   => array(
				self::field( 'G-DOCUMENTATION-1', 'Item description', 'text', array( 'problem' ), 'Write the item description.' ),
				self::field( 'G-DOCUMENTATION-3', 'Not covered', 'text', array( 'non_goals' ), 'Write what this deliberately does not cover.' ),
				self::field( 'G-DOCUMENTATION-5', 'Completed when', 'checklist', array( 'acceptance_criteria' ), 'Write when this counts as completed.' ),
				self::pick( 'G-DOCUMENTATION-7', 'Affected sites and data', self::AFFECTED, 'Choose which sites this affects.' ),
				self::approval( 'G-DOCUMENTATION-9', 'Documentation approval', 'Approve the documentation, as the reviewer.', self::REV ),
			),

			/*
			 * Technical Audit is the reviewer signing off the documentation
			 * (2026-09-19). The write-in assessments it used to ask for went;
			 * the risks and the approval stay. Dependencies are connected from
			 * the task itself, so no stage asks about them until Completed
			 * needs them done.
			 */
			'G-TECHNICAL-AUDIT' => array(
				self::done( 'G-TECHNICAL-AUDIT-7', 'Risks', 'Mark the technical risks as listed.' ),
				self::approval( 'G-TECHNICAL-AUDIT-8', 'Technical approval', 'Approve the audit, as the reviewer.', self::REV ),
			),
			'G-DESIGN'          => array(
				self::field( 'G-DESIGN-1', 'Design link', 'reference', array( 'design_url' ), 'Add the link to the approved design.' ),
				self::done( 'G-DESIGN-2', 'Responsive states', 'Mark the responsive states as done.' ),
				self::done( 'G-DESIGN-3', 'Empty, loading, error and permission-denied states', 'Mark all four states as done.' ),
				self::approval( 'G-DESIGN-5', 'Design approval', 'Approve the design, as the reviewer.', self::REV ),
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
				self::auto( 'G-UP-NEXT-4', 'Planned hours per role', 'numeric', 'hours', 'Enter planned hours for Primary User, Reviewer and Deliverer.' ),
				self::field( 'G-UP-NEXT-5', 'Planned start and due date', 'date', array( 'planned_start', 'planned_due' ), 'Set a planned start and a planned due date.' ),
				self::field( 'G-UP-NEXT-6', 'Priority confirmed', 'enum', array( 'priority' ), 'Confirm the priority.' ),
				self::system( 'G-UP-NEXT-8', 'Capacity check', 'capacity', 'Nobody in a seat may be over-booked in any week of the planned dates, unless the over-allocation is given a reason.' ),
				self::system( 'G-UP-NEXT-9', 'Support-hours check', 'support_hours', 'The site has to be on a package it can spend from, with enough hours left for the work as planned.' ),
			),
			'G-IN-DEVELOPMENT'  => array(
				// The checklist is the completion checklist (2026-09-19): every
				// line ticked, or no list at all, and nobody marks it by hand.
				self::auto( 'G-IN-DEVELOPMENT-1', 'Checklist complete', 'checklist', 'checklist', 'Tick every line of the checklist.', self::PU ),
				self::evidence( 'G-IN-DEVELOPMENT-2', 'Work evidence', 'Add a comment with a link to the work.', self::PU ),
				self::field( 'G-IN-DEVELOPMENT-3', 'How to test', 'text', array( 'test_description' ), 'Write how the reviewer tests it, under Review and testing.', self::PU ),
				self::system( 'G-IN-DEVELOPMENT-6', 'Submitted to Reviewer', 'submission', 'Recorded by the move itself.' ),
			),
			'G-IN-REVIEW'       => array(
				self::done( 'G-IN-REVIEW-1', 'Review checklist', 'Mark the review checklist as done.', self::REV ),
				self::done( 'G-IN-REVIEW-2', 'Every acceptance criterion confirmed', 'Tick every line of the checklist, or mark this Done.', self::REV, 'checklist' ),
				self::auto( 'G-IN-REVIEW-3', 'All feedback resolved', 'checklist', 'feedback', 'Answer every open question from the client, or return the item.', self::REV ),
				self::approval( 'G-IN-REVIEW-4', 'Review approval', 'Approve the review as the assigned Reviewer or an authorised substitute.', self::REV ),
				self::box( 'G-IN-REVIEW-5', 'Post-review hours adjustment', 'number', 'Fill in the post-review hours adjustment (0 for none).', self::REV ),
			),
			'G-COMPLETED'       => array(
				self::system( 'G-COMPLETED-1', 'Review approval preserved', 'review_approval', 'The review approval from this cycle has to still be on the item.' ),
				self::field( 'G-COMPLETED-2', 'Release method', 'enum', array( 'release_method' ), 'Choose how this is released: software, content, design, infrastructure or non-deployment.', self::DEL ),
				self::field( 'G-COMPLETED-3', 'Target environment, version or destination', 'text', array( 'release_destination' ), 'Fill in where this is going.', self::DEL ),
				self::box( 'G-COMPLETED-4', 'Release window', 'date', 'Fill in the release window.', self::DEL ),
				self::done( 'G-COMPLETED-5', 'Delivery checklist', 'Mark the delivery checklist as done.', self::DEL ),
				self::auto( 'G-COMPLETED-6', 'Dependencies ready', 'reference', 'dependencies_ready', 'Everything this waits on has to reach Completed first.', self::DEL ),
				self::box( 'G-COMPLETED-7', 'Release notes', 'text', 'Fill in the release notes.', self::DEL ),
				self::system( 'G-COMPLETED-8', 'Every child item Completed', 'children_completed', 'Every item beneath this one has to reach Completed first.' ),
			),
			'G-RELEASED'        => array(
				self::box( 'G-RELEASED-1', 'Release date and time', 'datetime', 'Fill in when it was released.', self::DEL ),
				self::box( 'G-RELEASED-2', 'Environment and version, or handover destination', 'text', 'Fill in where it went.', self::DEL ),
				self::evidence( 'G-RELEASED-3', 'Release evidence', 'Add a comment with a link to the release.', self::DEL ),
				self::deferred( 'G-RELEASED-4', 'Client communication status', 'client_communication', 'The NOTIF-2 confirmation arrives with the notification work; until it does this reports as passed.' ),
				self::done( 'G-RELEASED-5', 'Post-release check', 'Mark the post-release check as done.', self::DEL ),
			),
			'G-BLOCKED-ENTRY'   => array(
				self::pick( 'G-BLOCKED-ENTRY-1', 'What is blocking it', array( 'other' => 'Something else' ), 'Choose the item blocking this, or say what else is.', self::ANY, 'items' ),
				self::pick( 'G-BLOCKED-ENTRY-2', 'Who owns the blocker', array( 'client' => 'The client' ), 'Choose who owns the blocker.', self::ANY, 'people' ),
				self::pick( 'G-BLOCKED-ENTRY-3', 'What it is waiting on', array( 'other' => 'Something else' ), 'Choose the item this waits on, or say what else it is.', self::ANY, 'items' ),
				self::box( 'G-BLOCKED-ENTRY-4', 'Target resolution date', 'date', 'Set a target date for the blocker clearing.' ),
				self::box( 'G-BLOCKED-ENTRY-5', 'Next action', 'text', 'Say what the next action is.' ),
				self::system( 'G-BLOCKED-ENTRY-6', 'Prior stage stored', 'prior_stage', 'Recorded by the move itself.' ),
			),
			'G-BLOCKED-EXIT'    => array(
				self::box( 'G-BLOCKED-EXIT-1', 'Resolution note', 'text', 'Say how the blocker was resolved.' ),
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
	 * @return array{unmet: array<int, array<string, mixed>>, checks: array<int, array<string, mixed>>, all: array<int, array<string, mixed>>}
	 */
	public static function evaluate( string $gate, array $item, array $records, array $context = array() ): array {
		$unmet  = array();
		$checks = array();
		$all    = array();

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
				'all'    => array(),
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

				$failure = self::as_unmet( $requirement );

				if ( 'capacity' === (string) $requirement['check'] ) {
					// Who and in which week, so a screen can name them rather
					// than saying "no room" and leaving somebody to go looking.
					$capacity        = (array) ( $context['capacity'] ?? array() );
					$failure['over'] = array_values( (array) ( $capacity['over'] ?? array() ) );
				}

				if ( 'support_hours' === (string) $requirement['check'] ) {
					/*
					 * The figures, for the same reason: "not enough hours" is
					 * not something anybody can act on. Whether it is four
					 * hours short or a lapsed package decides whether this is a
					 * top-up to sell or a renewal to chase.
					 */
					$failure['hours'] = (array) ( $context['support_hours'] ?? array() );
				}

				$unmet[] = $failure;
				continue;
			}

			$met   = self::satisfied( $requirement, $item, $records, $context );
			$all[] = self::as_unmet( $requirement, $met );

			if ( ! $met ) {
				$unmet[] = self::as_unmet( $requirement );
			}
		}

		return array(
			'unmet'  => $unmet,
			'checks' => $checks,
			// Every row, met or not, so a screen draws the whole gate rather
			// than only what is left of it.
			'all'    => $all,
		);
	}

	/**
	 * Whether one requirement is met.
	 *
	 * @param array<string, mixed>               $requirement Requirement.
	 * @param array<string, mixed>               $item        The item.
	 * @param array<string, array<string,mixed>> $records     Completion records.
	 * @param array<string, mixed>               $context     What the auto resolvers read.
	 * @return bool
	 */
	public static function satisfied( array $requirement, array $item, array $records, array $context = array() ): bool {
		if ( self::BY_RECORD === $requirement['by'] ) {
			if ( isset( $records[ $requirement['id'] ] ) ) {
				return true;
			}

			$also = (string) ( $requirement['auto'] ?? '' );

			return '' !== $also && self::resolve( $also, $item, $context );
		}

		if ( self::BY_AUTO === $requirement['by'] ) {
			return self::resolve( (string) $requirement['check'], $item, $context );
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

		if ( in_array( $field, Fields::RICH, true ) ) {
			// Formatting with nothing in it is nothing.
			return '' !== Fields::plain( (string) $value );
		}

		if ( 'commercial_class' === $field ) {
			// 'unclassified' is the column's default, so it means nobody has
			// classified it — the opposite of an answer.
			return '' !== (string) $value && 'unclassified' !== (string) $value;
		}

		// How to test is rich text: an empty paragraph is an empty field.
		if ( 'test_description' === $field ) {
			return '' !== Fields::plain( (string) $value );
		}

		return '' !== trim( (string) $value );
	}

	/**
	 * Answers an auto requirement from the task and its context.
	 *
	 * @param string               $resolver Which resolver.
	 * @param array<string, mixed> $item     The item.
	 * @param array<string, mixed> $context  evidence_since_entry,
	 *                                       outstanding_questions, dependencies.
	 * @return bool
	 */
	private static function resolve( string $resolver, array $item, array $context ): bool {
		switch ( $resolver ) {
			case 'evidence':
				// A comment with a link, added since the item entered the stage
				// it is at. Worked out by the transition service from the
				// item's own entries.
				return ! empty( $context['evidence_since_entry'] );

			case 'feedback':
				return 0 === (int) ( $context['outstanding_questions'] ?? 0 );

			case 'hours':
				foreach ( Fields::HOURS as $field ) {
					if ( (float) ( $item[ $field ] ?? 0 ) <= 0.0 ) {
						return false;
					}
				}

				return true;

			case 'checklist':
				// No checklist is a complete one (2026-09-19): the list is
				// optional, and an item without one has nothing left to tick.
				$rows = (array) ( $item['checklist'] ?? array() );

				foreach ( $rows as $row ) {
					if ( empty( $row['done'] ) ) {
						return false;
					}
				}

				return true;

			case 'dependencies_ready':
				$completed = (int) array_search( Stages::COMPLETED, Stages::ALL, true );

				foreach ( (array) ( $context['dependencies'] ?? array() ) as $stage ) {
					$at = array_search( (string) $stage, Stages::ALL, true );

					if ( false === $at || (int) $at < $completed ) {
						return false;
					}
				}

				return true;

			default:
				return false;
		}
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
				/*
				 * Asked of Work\Derived rather than answered here, so the gate
				 * and what the parent reads as on a screen cannot disagree
				 * (#101). The rule they share is that work which ended without
				 * being done — cancelled, rejected, a duplicate — is not work
				 * anybody is still waiting for; counting it would leave a parent
				 * held open forever by a child somebody deliberately stopped.
				 */
				return Derived::may_complete( (array) ( $context['children'] ?? array() ) );

			case 'capacity':
				/*
				 * CAP-4: over-allocation does not block, it costs a reason. The
				 * figures are worked out by Capacity\Impact and handed in
				 * through the context, the same way children are — this class
				 * stays free of the database, which is what lets every rule in
				 * it be stated in a test.
				 *
				 * The reason is read from the context and never from the item.
				 * A reason given when the plan was made was given about a
				 * picture that has since changed, and #142 is the requirement
				 * that it be asked again.
				 */
				$capacity = (array) ( $context['capacity'] ?? array() );

				if ( array() === (array) ( $capacity['over'] ?? array() ) ) {
					return true;
				}

				return '' !== trim( (string) ( $capacity['reason'] ?? '' ) );

			case 'support_hours':
				/*
				 * COMM-3 (#150). Worked out by Commerce\HoursGate and handed in
				 * through the context, exactly as capacity is, so this class
				 * stays free of the database.
				 *
				 * Deliberately *not* reading the capacity reason. The two
				 * checks are separate questions with separate answers, and a
				 * reason given about our own people's week is not a decision
				 * anybody made about the client's money.
				 *
				 * Absent context passes. A gate evaluated without it is being
				 * asked about something else — the caller that means this
				 * question always supplies the answer.
				 */
				$hours = (array) ( $context['support_hours'] ?? array() );

				return ! array_key_exists( 'sufficient', $hours ) || (bool) $hours['sufficient'];

			case 'prior_stage':
			case 'blocked_elapsed':
			case 'submission':
				// All written by the move itself, inside the same transaction.
				// There is no state in which the move happened and these did
				// not.
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
	 * @param bool                 $met         Whether the item meets it.
	 * @return array<string, mixed>
	 */
	private static function as_unmet( array $requirement, bool $met = false ): array {
		return array(
			'id'           => $requirement['id'],
			'met'          => $met,
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
			'check'        => $requirement['check'],
			'control'      => $requirement['control'],
			'options'      => $requirement['options'],
			'source'       => $requirement['source'],
			'input'        => $requirement['input'],
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
			'control'      => '',
			'options'      => array(),
			'source'       => '',
			'input'        => '',
			'auto'         => '',
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
			'control'      => '',
			'options'      => array(),
			'source'       => '',
			'input'        => '',
			'auto'         => '',
		);
	}

	/**
	 * A requirement answered from a dropdown on its row, saved as a record.
	 *
	 * @param string                $id           Requirement id.
	 * @param string                $label        How it reads.
	 * @param array<string, string> $options      The fixed choices, value to label.
	 * @param string                $satisfied_by What to do about it.
	 * @param string                $who          Who may complete it.
	 * @param string                $source       What a screen appends: 'items' or 'people'.
	 * @param string                $auto         A resolver that also satisfies it from the task.
	 * @return array<string, mixed>
	 */
	private static function pick( string $id, string $label, array $options, string $satisfied_by, string $who = self::ANY, string $source = '', string $auto = '' ): array {
		$requirement = self::record( $id, $label, 'enum', $satisfied_by, $who );

		$requirement['control'] = 'pick';
		$requirement['source']  = $source;
		$requirement['auto']    = $auto;

		foreach ( $options as $value => $name ) {
			$requirement['options'][] = array(
				'value' => (string) $value,
				'label' => (string) $name,
			);
		}

		return $requirement;
	}

	/**
	 * A requirement answered in a box on the task, saved as a record.
	 *
	 * @param string $id           Requirement id.
	 * @param string $label        How it reads.
	 * @param string $input        text, number, date, datetime or range.
	 * @param string $satisfied_by What to do about it.
	 * @param string $who          Who may complete it.
	 * @return array<string, mixed>
	 */
	private static function box( string $id, string $label, string $input, string $satisfied_by, string $who = self::ANY ): array {
		$requirement = self::record( $id, $label, 'range' === $input ? 'numeric' : $input, $satisfied_by, $who );

		$requirement['control'] = 'box';
		$requirement['input']   = $input;

		return $requirement;
	}

	/**
	 * A yes-or-no pick: Not yet, or Done.
	 *
	 * @param string $id           Requirement id.
	 * @param string $label        How it reads.
	 * @param string $satisfied_by What to do about it.
	 * @param string $who          Who may complete it.
	 * @param string $auto         A resolver that also satisfies it from the task.
	 * @return array<string, mixed>
	 */
	private static function done( string $id, string $label, string $satisfied_by, string $who = self::ANY, string $auto = '' ): array {
		$requirement         = self::pick( $id, $label, array( 'done' => 'Done' ), $satisfied_by, $who, '', $auto );
		$requirement['type'] = 'checklist';

		return $requirement;
	}

	/**
	 * An approval: Not yet, or Approved, offered to the person the gate allows.
	 *
	 * @param string $id           Requirement id.
	 * @param string $label        How it reads.
	 * @param string $satisfied_by What to do about it.
	 * @param string $who          Who may complete it.
	 * @return array<string, mixed>
	 */
	private static function approval( string $id, string $label, string $satisfied_by, string $who ): array {
		$requirement         = self::pick( $id, $label, array( 'approved' => 'Approved' ), $satisfied_by, $who );
		$requirement['type'] = 'approval';

		return $requirement;
	}

	/**
	 * A requirement the task answers for itself.
	 *
	 * @param string $id           Requirement id.
	 * @param string $label        How it reads.
	 * @param string $type         Requirement type.
	 * @param string $resolver     Which resolver answers it.
	 * @param string $satisfied_by What would meet it.
	 * @param string $who          Whose it is.
	 * @return array<string, mixed>
	 */
	private static function auto( string $id, string $label, string $type, string $resolver, string $satisfied_by, string $who = self::ANY ): array {
		$requirement = self::record( $id, $label, $type, $satisfied_by, $who );

		$requirement['by']    = self::BY_AUTO;
		$requirement['check'] = $resolver;

		return $requirement;
	}

	/**
	 * Evidence: a comment with a link, added since the stage was entered.
	 * Worked out from the item's entries rather than recorded by hand, and
	 * still marked as needing evidence so the specification's list holds.
	 *
	 * @param string $id           Requirement id.
	 * @param string $label        How it reads.
	 * @param string $satisfied_by What to do about it.
	 * @param string $who          Whose it is.
	 * @return array<string, mixed>
	 */
	private static function evidence( string $id, string $label, string $satisfied_by, string $who = self::ANY ): array {
		$requirement             = self::auto( $id, $label, 'attachment', 'evidence', $satisfied_by, $who );
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
			'control'      => '',
			'options'      => array(),
			'source'       => '',
			'input'        => '',
			'auto'         => '',
		);
	}

	/**
	 * A system check whose subject is not built yet.
	 *
	 * It is here rather than absent on purpose. A requirement whose subject is
	 * not built yet is in the response from the first day and gains teeth when
	 * its subject lands, rather than being remembered and added later, which is
	 * how a requirement goes missing. Both of the checks at Up Next were
	 * declared this way and both have since become real — capacity with CAP-4
	 * (#141) and support hours with COMM-3 (#150).
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
