<?php
/**
 * What needs attention today.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Standup;

/**
 * #169. The twelve inclusion rules, worked out from what is true now.
 *
 * **Nothing is stored on the item, and that is the whole issue.** The obvious
 * implementation puts a flag on a record when it starts needing attention and
 * clears it when somebody deals with it — and then the flag and the reason for
 * it drift apart. A card stays on the board after the blocker was resolved
 * elsewhere; a card vanishes because somebody cleared a flag rather than doing
 * the work. Both are the same bug, and both are silent.
 *
 * So the list is computed every time it is drawn, from the same records the
 * rest of the product reads. An item is on Standup because a condition is true
 * about it right now, and it leaves the moment that stops being true. There is
 * no state to get out of step, and no sweep to run overnight — the same
 * argument Onboarding\Statuses makes about a step being late, applied to a
 * whole board.
 *
 * **A rule reports; it never decides what to do.** Each returns cards saying
 * what is true and about which record. Which section a card lands in, how it is
 * worded and what can be done from it are the board's business (#170), and
 * keeping them out of here is what lets the rules be stated as rules and tested
 * without a screen.
 *
 * This file is pure. Everything it needs is handed to it, because the alternative
 * — twelve rules each reaching for its own data — is twelve rules nobody can
 * evaluate in a test, and a board that costs a hundred queries to draw.
 */
final class Rules {

	/* --------------------------------------------------------------- the work */

	/**
	 * Past its date and not finished.
	 */
	public const OVERDUE = 'overdue';

	/**
	 * Due today. Separate from overdue because it is not late yet, and the two
	 * want different things done about them.
	 */
	public const DUE_TODAY = 'due-today';

	/**
	 * Stopped on something outside the owner's control.
	 */
	public const BLOCKED = 'blocked';

	/**
	 * Cannot move on until somebody satisfies a requirement.
	 */
	public const GATE_UNMET = 'gate-unmet';

	/* ------------------------------------------------------- waiting on a person */

	/**
	 * Handed over for review and not yet reviewed.
	 */
	public const AWAITING_REVIEW = 'awaiting-review';

	/**
	 * Approved, ready, and not released.
	 */
	public const AWAITING_RELEASE = 'awaiting-release';

	/**
	 * Reviewed and sent back with something to change.
	 */
	public const RETURNED = 'returned';

	/* ---------------------------------------------------------- waiting on us */

	/**
	 * A client has asked for something and nobody has answered.
	 */
	public const REQUEST_WAITING = 'request-waiting';

	/**
	 * A client has handed over an onboarding step for us to check.
	 */
	public const ONBOARDING_WAITING = 'onboarding-waiting';

	/**
	 * An onboarding step past the date it was wanted by.
	 */
	public const ONBOARDING_OVERDUE = 'onboarding-overdue';

	/* --------------------------------------------------------------- the studio */

	/**
	 * Somebody has more committed than they have time for.
	 */
	public const OVER_COMMITTED = 'over-committed';

	/**
	 * Something the product tried to do and could not, that a person has to
	 * pick up: an email that failed, or a client site that has stopped talking
	 * to us.
	 */
	public const NEEDS_INTERVENTION = 'needs-intervention';

	/**
	 * The twelve, in the order a person reads them.
	 *
	 * Work first, because most days that is the whole answer. Then the two
	 * queues that are somebody's turn, then what a client is waiting on us for,
	 * then the studio's own problems — which are the rarest and the ones most
	 * easily missed at the bottom of a long list, hence their own section
	 * rather than the end of one.
	 *
	 * @var array<int, string>
	 */
	public const ALL = array(
		self::OVERDUE,
		self::DUE_TODAY,
		self::BLOCKED,
		self::GATE_UNMET,
		self::AWAITING_REVIEW,
		self::AWAITING_RELEASE,
		self::RETURNED,
		self::REQUEST_WAITING,
		self::ONBOARDING_WAITING,
		self::ONBOARDING_OVERDUE,
		self::OVER_COMMITTED,
		self::NEEDS_INTERVENTION,
	);

	/**
	 * Which rules are about a piece of work, so the board can group by item.
	 *
	 * @var array<int, string>
	 */
	public const ABOUT_WORK = array(
		self::OVERDUE,
		self::DUE_TODAY,
		self::BLOCKED,
		self::GATE_UNMET,
		self::AWAITING_REVIEW,
		self::AWAITING_RELEASE,
		self::RETURNED,
	);

	/**
	 * Whether this is one of the twelve.
	 *
	 * @param string $rule Rule name.
	 * @return bool
	 */
	public static function exists( string $rule ): bool {
		return in_array( $rule, self::ALL, true );
	}

	/**
	 * The whole list, from a snapshot of what is true.
	 *
	 * @param array<string, mixed> $state    items, submissions, onboarding_steps,
	 *                                       capacity, interventions.
	 * @param string               $today    YYYY-MM-DD.
	 * @return array<int, array<string, mixed>> Cards, in rule order.
	 */
	public static function evaluate( array $state, string $today ): array {
		$cards = array();

		foreach ( (array) ( $state['items'] ?? array() ) as $item ) {
			foreach ( self::for_item( (array) $item, $today ) as $card ) {
				$cards[] = $card;
			}
		}

		foreach ( (array) ( $state['submissions'] ?? array() ) as $submission ) {
			$card = self::for_submission( (array) $submission );

			if ( array() !== $card ) {
				$cards[] = $card;
			}
		}

		foreach ( (array) ( $state['onboarding_steps'] ?? array() ) as $step ) {
			foreach ( self::for_step( (array) $step, $today ) as $card ) {
				$cards[] = $card;
			}
		}

		foreach ( (array) ( $state['capacity'] ?? array() ) as $position ) {
			$card = self::for_capacity( (array) $position );

			if ( array() !== $card ) {
				$cards[] = $card;
			}
		}

		foreach ( (array) ( $state['interventions'] ?? array() ) as $problem ) {
			$cards[] = self::card(
				self::NEEDS_INTERVENTION,
				(string) ( $problem['subject_type'] ?? 'system' ),
				(string) ( $problem['id'] ?? '' ),
				$problem
			);
		}

		return self::in_rule_order( $cards );
	}

	/**
	 * Every rule one piece of work matches.
	 *
	 * More than one can be true at once and all of them are reported. An item
	 * that is both blocked and overdue is two different conversations, and
	 * picking one would hide the other — the board decides how to present that,
	 * not this.
	 *
	 * @param array<string, mixed> $item  The work item.
	 * @param string               $today YYYY-MM-DD.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_item( array $item, string $today ): array {
		$stage    = (string) ( $item['stage'] ?? '' );
		$finished = in_array( $stage, self::FINISHED, true );
		$due      = (string) ( $item['planned_due'] ?? '' );

		$cards = array();

		/*
		 * Finished work is never late, however long it took, and never due
		 * today. It is done, and a board still shouting about it is a board
		 * people stop reading — the same rule Onboarding\Statuses applies to a
		 * checklist step.
		 */
		if ( ! $finished && '' !== $due ) {
			if ( $due < $today ) {
				$cards[] = self::work_card( self::OVERDUE, $item, array( 'due' => $due ) );
			} elseif ( $due === $today ) {
				$cards[] = self::work_card( self::DUE_TODAY, $item, array( 'due' => $due ) );
			}
		}

		if ( 'blocked' === $stage ) {
			$cards[] = self::work_card( self::BLOCKED, $item, array( 'since' => (int) ( $item['blocked_at'] ?? 0 ) ) );
		}

		if ( 'in-review' === $stage ) {
			$cards[] = self::work_card( self::AWAITING_REVIEW, $item, array( 'waiting_on' => (string) ( $item['reviewer_id'] ?? '' ) ) );
		}

		if ( 'completed' === $stage ) {
			$cards[] = self::work_card( self::AWAITING_RELEASE, $item, array( 'waiting_on' => (string) ( $item['deliverer_id'] ?? '' ) ) );
		}

		// A returned item is one somebody has to pick back up, and it stays on
		// the list until it moves — not until somebody has read the feedback.
		if ( ! empty( $item['was_returned'] ) && ! $finished ) {
			$cards[] = self::work_card( self::RETURNED, $item, array( 'reason' => (string) ( $item['return_reason'] ?? '' ) ) );
		}

		/*
		 * A gate nobody can get past yet. Handed in rather than worked out here
		 * because deciding it means running the workflow engine, and a second
		 * implementation of the gates inside the standup rules is exactly the
		 * disagreement this product keeps refusing to create.
		 */
		if ( ! $finished && array() !== (array) ( $item['unmet'] ?? array() ) ) {
			$cards[] = self::work_card( self::GATE_UNMET, $item, array( 'unmet' => (array) $item['unmet'] ) );
		}

		return $cards;
	}

	/**
	 * Whether a client's request is still waiting on us.
	 *
	 * @param array<string, mixed> $submission The request.
	 * @return array<string, mixed>
	 */
	public static function for_submission( array $submission ): array {
		$state = (string) ( $submission['intake_state'] ?? '' );

		// Received and being looked at are both still ours. Accepted, declined
		// and converted are all answers, and an answered request is not work.
		if ( ! in_array( $state, array( 'received', 'in-review' ), true ) ) {
			return array();
		}

		return self::card(
			self::REQUEST_WAITING,
			'submission',
			(string) ( $submission['id'] ?? '' ),
			array(
				'title'     => (string) ( $submission['title'] ?? '' ),
				'client_id' => (string) ( $submission['client_id'] ?? '' ),
				'since'     => (int) ( $submission['created_at'] ?? 0 ),
				'state'     => $state,
			)
		);
	}

	/**
	 * Every rule one onboarding step matches.
	 *
	 * @param array<string, mixed> $step  The step.
	 * @param string               $today YYYY-MM-DD.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_step( array $step, string $today ): array {
		$status  = (string) ( $step['status'] ?? '' );
		$settled = in_array( $status, array( 'approved', 'not-applicable' ), true );
		$due     = (string) ( $step['due_on'] ?? '' );

		$cards = array();

		if ( 'submitted' === $status ) {
			$cards[] = self::card(
				self::ONBOARDING_WAITING,
				'onboarding_step',
				(string) ( $step['id'] ?? '' ),
				array(
					'title'          => (string) ( $step['title'] ?? '' ),
					'client_site_id' => (string) ( $step['client_site_id'] ?? '' ),
					'critical'       => ! empty( $step['launch_critical'] ),
				)
			);
		}

		if ( ! $settled && '' !== $due && $due < $today ) {
			$cards[] = self::card(
				self::ONBOARDING_OVERDUE,
				'onboarding_step',
				(string) ( $step['id'] ?? '' ),
				array(
					'title'          => (string) ( $step['title'] ?? '' ),
					'client_site_id' => (string) ( $step['client_site_id'] ?? '' ),
					'due'            => $due,
					'critical'       => ! empty( $step['launch_critical'] ),
				)
			);
		}

		return $cards;
	}

	/**
	 * Whether somebody is committed past what they have.
	 *
	 * Only genuinely over. Tight is a planning signal and belongs on the
	 * capacity screen; a daily list that included everybody who is busy would
	 * be a daily list of the whole studio.
	 *
	 * @param array<string, mixed> $position One person's week.
	 * @return array<string, mixed>
	 */
	public static function for_capacity( array $position ): array {
		if ( 'over' !== (string) ( $position['band'] ?? '' ) ) {
			return array();
		}

		return self::card(
			self::OVER_COMMITTED,
			'person',
			(string) ( $position['user_id'] ?? '' ),
			array(
				'display_name' => (string) ( $position['display_name'] ?? '' ),
				'committed'    => (float) ( $position['committed'] ?? 0 ),
				'available'    => (float) ( $position['available'] ?? 0 ),
				'from'         => (string) ( $position['from'] ?? '' ),
			)
		);
	}

	/* ---------------------------------------------------------------- internals */

	/**
	 * The stages at which work stops appearing on a daily list.
	 *
	 * @var array<int, string>
	 */
	private const FINISHED = array( 'released' );

	/**
	 * A card about a piece of work.
	 *
	 * @param string               $rule   Which rule.
	 * @param array<string, mixed> $item   The item.
	 * @param array<string, mixed> $detail Whatever that rule needs said.
	 * @return array<string, mixed>
	 */
	private static function work_card( string $rule, array $item, array $detail ): array {
		return self::card(
			$rule,
			'work_item',
			(string) ( $item['id'] ?? '' ),
			array_merge(
				array(
					'title'          => (string) ( $item['title'] ?? '' ),
					'stage'          => (string) ( $item['stage'] ?? '' ),
					'client_id'      => (string) ( $item['client_id'] ?? '' ),
					'client_site_id' => (string) ( $item['client_site_id'] ?? '' ),
				),
				$detail
			)
		);
	}

	/**
	 * One card.
	 *
	 * @param string               $rule    Which rule put it here.
	 * @param string               $type    What kind of record it is about.
	 * @param string               $id      Which record.
	 * @param array<string, mixed> $detail  What the rule wants said.
	 * @return array<string, mixed>
	 */
	private static function card( string $rule, string $type, string $id, array $detail ): array {
		return array(
			'rule'         => $rule,
			'subject_type' => $type,
			'subject_id'   => $id,
			'detail'       => $detail,
		);
	}

	/**
	 * The cards in the order the rules are declared in.
	 *
	 * Sorted rather than built in order, because one record can match several
	 * rules and the loops above walk records rather than rules. A stable order
	 * matters: a list that reshuffles between two page loads is one nobody can
	 * work down.
	 *
	 * @param array<int, array<string, mixed>> $cards The cards.
	 * @return array<int, array<string, mixed>>
	 */
	private static function in_rule_order( array $cards ): array {
		$rank = array_flip( self::ALL );

		usort(
			$cards,
			static function ( array $a, array $b ) use ( $rank ): int {
				$by_rule = ( $rank[ $a['rule'] ] ?? 99 ) <=> ( $rank[ $b['rule'] ] ?? 99 );

				// Then by subject, so the same two cards never swap places.
				return 0 !== $by_rule ? $by_rule : strcmp( (string) $a['subject_id'], (string) $b['subject_id'] );
			}
		);

		return $cards;
	}
}
