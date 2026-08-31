<?php
/**
 * The one service that moves work.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

use Blueworx\Forge\Capacity\Impact;

/*
 * Aliased because this file already has an Events — the changelog's — and two
 * classes of that name in one file is how the wrong one gets called.
 */
use Blueworx\Forge\Notifications\Events as Notifications;
use Blueworx\Forge\Notifications\Register;
use Blueworx\Forge\Onboarding\LaunchGate;
use Blueworx\Forge\Onboarding\Progress;
use Blueworx\Forge\Onboarding\Steps;
use WP_Error;

/**
 * #106. Every stage change in the product goes through this class, and nothing
 * else writes the `stage` column — Work\Items::update() refuses to, and
 * Work\Validate refuses an edit that names it.
 *
 * That single door is the point. A stage change is never only a stage change:
 * it has a gate to satisfy (#105), a changelog entry to append, and later an
 * hour reservation to move (#149) and a notification to send (#172). Anything
 * that sets the column directly gets the stage right and everything else wrong.
 *
 * There are six ways through the door and no seventh:
 *
 * - {@see move()}      forward, one step, through the exit gate (#106, #105).
 * - {@see send_back()} backwards, to a stage the item has occupied, with a
 *                      mandatory reason (#108).
 * - {@see block()}     out of the path entirely, storing where it came from,
 *                      and {@see unblock()} back to exactly that stage (#109).
 * - {@see end()}       to a terminal outcome, and {@see archive()} out of the
 *                      default views but never out of the reports (#111).
 *
 * **Atomic, and proved by the failure case rather than the happy one.** Each
 * move and its record go in one transaction, so a failure part-way leaves the
 * item exactly as it was and leaves no half-written history.
 *
 * A refused move is refused whole. Nothing here writes anything before it has
 * decided, so "the gate said no" and "the item is untouched" are the same
 * sentence rather than two things that have to agree.
 */
final class Transition {

	/**
	 * The error code a gate failure travels under. The REST layer recognises it
	 * and answers in the documented gate-failure body rather than as an error,
	 * because a gate failure is not a malformed request — the item simply is not
	 * ready.
	 */
	public const GATE_FAILURE = 'bwx_forge_gate_not_met';

	/**
	 * Moves an item one step forward, if its exit gate is satisfied.
	 *
	 * @param array<string, mixed> $item         The item, as read.
	 * @param string               $to           The stage to move to.
	 * @param int                  $sent_version Version the move was made against.
	 * @param int                  $actor        WordPress user id requesting it.
	 * @param string               $via          How the actor was entitled to make
	 *                                           it, where it was not their own
	 *                                           authority: Events::VIA_SUBSTITUTE.
	 * @param string               $reason       Why an over-allocation is going
	 *                                           ahead anyway (CAP-4). Offered with
	 *                                           the move rather than remembered
	 *                                           from the item, because it answers
	 *                                           the picture as it is now.
	 * @return array<string, mixed>|WP_Error The item as it now stands.
	 */
	public static function move( array $item, string $to, int $sent_version, int $actor, string $via = '', string $reason = '' ) {
		$from = (string) $item['stage'];

		$refusal = self::refuse_bad_target( $item, $to );

		if ( null !== $refusal ) {
			return $refusal;
		}

		if ( ! Transitions::allowed( $from, $to, (string) $item['work_type'] ) ) {
			/*
			 * One refusal for every reason a forward move is not available:
			 * skipping stages, moving backwards, entering Bug Tracking with
			 * something that is not a bug. The response says what is available
			 * instead, which is what a board needs to draw the next step.
			 */
			return new WP_Error(
				'bwx_forge_transition_not_allowed',
				__( 'Work cannot move there from where it is.', 'blueworx-forge' ),
				array(
					'status'    => 409,
					'from'      => $from,
					'attempted' => $to,
					'available' => Transitions::next_from( $from, (string) $item['work_type'] ),
				)
			);
		}

		$gate    = Transitions::gate_for( $from, $to );
		$blocked = self::gate_refusal( $item, $to, array( $gate, Transitions::entry_gate_for( $to ) ), $reason );

		if ( null !== $blocked ) {
			return $blocked;
		}

		$onboarding = self::onboarding_refusal( $item, $to );

		if ( null !== $onboarding ) {
			return $onboarding;
		}

		return self::commit(
			$item,
			$to,
			self::over_allocation( $item, $to, $reason, $actor ),
			array(
				'action' => Events::MOVED,
				'gate'   => $gate,
				// AUTH-4: a review approved by a stand-in is still approved, and
				// the changelog says which it was.
				'via'    => $via,
			),
			$sent_version,
			$actor
		);
	}

	/**
	 * Sends an item back to a stage it has occupied (#108).
	 *
	 * The reason is not optional and there is no code path that makes it so.
	 * The failed-review return additionally records the reviewer's feedback and
	 * starts a new review attempt, which is what preserves the previous one: the
	 * earlier attempt's gate records stay on the item under their own attempt
	 * number, and stop counting towards the next review.
	 *
	 * @param array<string, mixed>             $item         The item, as read.
	 * @param string                           $to           Target stage.
	 * @param string                           $reason       Why it is going back.
	 * @param string                           $feedback     Review feedback, for
	 *                                                       the review return.
	 * @param array<int, array<string, mixed>> $history      The item's changelog.
	 * @param int                              $sent_version Version moved against.
	 * @param int                              $actor        Who is doing it.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function send_back( array $item, string $to, string $reason, string $feedback, array $history, int $sent_version, int $actor ) {
		$from   = (string) $item['stage'];
		$reason = trim( $reason );

		$refusal = self::refuse_bad_target( $item, $to );

		if ( null !== $refusal ) {
			return $refusal;
		}

		if ( '' === $reason ) {
			return new WP_Error(
				'bwx_forge_reason_required',
				__( 'Work only goes backwards with a reason.', 'blueworx-forge' ),
				array( 'status' => 400 )
			);
		}

		if ( ! Returns::allowed( $item, $to, $history ) ) {
			/*
			 * Refused rather than quietly upgraded to an override. A return to
			 * a stage the item has never been in is a correction, and WF-5 makes
			 * that the primary administrator's decision, permanently marked on
			 * the item — not something a return route does on its behalf.
			 */
			return new WP_Error(
				'bwx_forge_return_not_allowed',
				__( 'Work can only go back to a stage it has actually been in.', 'blueworx-forge' ),
				array(
					'status'    => 409,
					'from'      => $from,
					'attempted' => $to,
					'available' => Returns::targets( $item, $history ),
				)
			);
		}

		$also     = array();
		$feedback = trim( $feedback );

		if ( Returns::is_review_return( $from, $to ) ) {
			if ( '' === $feedback ) {
				return new WP_Error(
					'bwx_forge_feedback_required',
					__( 'A review that sends work back has to say what was wrong with it.', 'blueworx-forge' ),
					array( 'status' => 400 )
				);
			}

			$also['review_attempt'] = (int) $item['review_attempt'] + 1;
		}

		return self::commit(
			$item,
			$to,
			$also,
			array(
				'action'  => Events::RETURNED,
				'gate'    => '',
				'reason'  => $reason,
				'detail'  => $feedback,
				'attempt' => (int) $item['review_attempt'],
			),
			$sent_version,
			$actor
		);
	}

	/**
	 * Pauses work without losing its place (#109).
	 *
	 * The stage it came from is stored on the item by this move, so resolution
	 * has somewhere definite to return to rather than a guess from the history.
	 *
	 * @param array<string, mixed> $item         The item, as read.
	 * @param array<string, mixed> $details      The G-BLOCKED-ENTRY answers.
	 * @param int                  $sent_version Version moved against.
	 * @param int                  $actor        Who is doing it.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function block( array $item, array $details, int $sent_version, int $actor ) {
		$from = (string) $item['stage'];

		if ( Outcomes::is_closed( $item ) ) {
			return self::closed_error();
		}

		if ( ! Stages::is_active( $from ) ) {
			return new WP_Error(
				'bwx_forge_not_active',
				__( 'Only work that is actually in progress can be blocked.', 'blueworx-forge' ),
				array(
					'status' => 409,
					'from'   => $from,
				)
			);
		}

		$unmet = self::missing_blocker_details( $details );

		if ( array() !== $unmet ) {
			return self::gate_error( $item, Stages::BLOCKED, $unmet, array() );
		}

		return self::commit(
			$item,
			Stages::BLOCKED,
			array(
				'prior_stage' => $from,
				'blocked_at'  => bwx_forge_now(),
			),
			array(
				'action' => Events::BLOCKED,
				'gate'   => 'G-BLOCKED-ENTRY',
				'reason' => (string) $details['reason'],
				'detail' => (string) $details['next_action'],
			),
			$sent_version,
			$actor
		);
	}

	/**
	 * Returns blocked work to exactly the stage it left (#109).
	 *
	 * There is no target to choose: G-BLOCKED-EXIT makes the destination the
	 * stored prior stage and nothing else, so resolving a blocker cannot be used
	 * as a way to move work sideways past a gate.
	 *
	 * @param array<string, mixed> $item         The item, as read.
	 * @param string               $resolution   How the blocker was resolved.
	 * @param int                  $sent_version Version moved against.
	 * @param int                  $actor        Who is doing it.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function unblock( array $item, string $resolution, int $sent_version, int $actor ) {
		$resolution = trim( $resolution );

		if ( Stages::BLOCKED !== (string) $item['stage'] ) {
			return new WP_Error(
				'bwx_forge_not_blocked',
				__( 'That work is not blocked.', 'blueworx-forge' ),
				array( 'status' => 409 )
			);
		}

		$back = (string) $item['prior_stage'];

		if ( ! Stages::exists( $back ) || ! Stages::may_hold( $back, (string) $item['work_type'] ) ) {
			// Only reachable if the stored stage were nonsense, which the block
			// move cannot write — but a stuck item is worse than a plain answer.
			return new WP_Error(
				'bwx_forge_no_prior_stage',
				__( 'There is no stage recorded for this to go back to.', 'blueworx-forge' ),
				array( 'status' => 409 )
			);
		}

		if ( '' === $resolution ) {
			return self::gate_error(
				$item,
				$back,
				array( self::unmet_of( 'G-BLOCKED-EXIT-1' ) ),
				array()
			);
		}

		$elapsed = (int) $item['blocked_elapsed'];
		$since   = (int) $item['blocked_at'];

		if ( $since > 0 ) {
			// Added to rather than replaced: an item blocked three times has
			// been blocked for the sum of the three, and a report asking "how
			// long was this waiting" wants all of it.
			$elapsed += max( 0, bwx_forge_now() - $since );
		}

		return self::commit(
			$item,
			$back,
			array(
				'prior_stage'     => '',
				'blocked_at'      => 0,
				'blocked_elapsed' => $elapsed,
			),
			array(
				'action' => Events::UNBLOCKED,
				'gate'   => 'G-BLOCKED-EXIT',
				'reason' => $resolution,
			),
			$sent_version,
			$actor
		);
	}

	/**
	 * Ends work at one of the WF-2 outcomes (#111).
	 *
	 * @param array<string, mixed> $item         The item, as read.
	 * @param string               $outcome      One of Work\Outcomes::ALL.
	 * @param array<string, mixed> $params       reason, duplicate_of.
	 * @param int                  $sent_version Version moved against.
	 * @param int                  $actor        Who is doing it.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function end( array $item, string $outcome, array $params, int $sent_version, int $actor ) {
		$stage = (string) $item['stage'];

		if ( ! Outcomes::exists( $outcome ) ) {
			return new WP_Error(
				'bwx_forge_unknown_outcome',
				__( 'There is no such outcome.', 'blueworx-forge' ),
				array( 'status' => 400 )
			);
		}

		if ( Outcomes::is_closed( $item ) || ! empty( $item['archived'] ) ) {
			return self::closed_error();
		}

		if ( ! Outcomes::reachable_from( $outcome, $stage ) ) {
			return new WP_Error(
				'bwx_forge_outcome_not_reachable',
				__( 'That is not an outcome work can reach from where it is.', 'blueworx-forge' ),
				array(
					'status'    => 409,
					'from'      => $stage,
					'attempted' => $outcome,
					'available' => Outcomes::available_for( $item ),
				)
			);
		}

		$definition = (array) Outcomes::definition( $outcome );
		$reason     = trim( (string) ( $params['reason'] ?? '' ) );
		$duplicate  = trim( (string) ( $params['duplicate_of'] ?? '' ) );

		if ( 'reason' === $definition['needs'] && '' === $reason ) {
			return new WP_Error(
				'bwx_forge_reason_required',
				__( 'Ending work needs a reason.', 'blueworx-forge' ),
				array( 'status' => 400 )
			);
		}

		if ( 'duplicate_of' === $definition['needs'] && '' === $duplicate ) {
			return new WP_Error(
				'bwx_forge_duplicate_required',
				__( 'A duplicate has to say which item survives.', 'blueworx-forge' ),
				array( 'status' => 400 )
			);
		}

		/*
		 * The surviving item has to be real and on this site. A reference across
		 * the tenant boundary is the thing ARCH-3 forbids — the record would be
		 * reachable from two tenants at once — and a reference to nothing is a
		 * dead end somebody finds months later with no way to resolve it.
		 *
		 * Work cannot be a duplicate of itself either, which would leave an item
		 * pointing at itself as the survivor of its own closure.
		 */
		if ( '' !== $duplicate ) {
			$survivor = Items::get( $duplicate );

			if ( null === $survivor
				|| $duplicate === (string) $item['id']
				|| (string) $survivor['client_site_id'] !== (string) $item['client_site_id'] ) {
				return new WP_Error(
					'bwx_forge_unknown_work_item',
					__( 'There is no such work item.', 'blueworx-forge' ),
					array( 'status' => 404 )
				);
			}
		}

		/*
		 * Deferred is the one that does not stop: it goes back to being an idea
		 * and stays open. Everything else stays where it is with the outcome
		 * flagged, because "cancelled during development" and "cancelled at
		 * triage" are different facts and moving both to one place loses that.
		 */
		$to = '' === (string) $definition['returns_to'] ? $stage : (string) $definition['returns_to'];

		return self::commit(
			$item,
			$to,
			array(
				'terminal_outcome' => $outcome,
				'duplicate_of'     => $duplicate,
			),
			array(
				'action'  => Events::ENDED,
				'gate'    => '',
				'reason'  => $reason,
				'detail'  => $duplicate,
				'outcome' => $outcome,
			),
			$sent_version,
			$actor
		);
	}

	/**
	 * Puts an ended item out of the way (#111).
	 *
	 * It stays in the table, in the reports, and in every cycle-time
	 * calculation. Archiving hides it from the default views and does nothing
	 * else at all.
	 *
	 * @param array<string, mixed> $item         The item, as read.
	 * @param int                  $sent_version Version moved against.
	 * @param int                  $actor        Who is doing it.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function archive( array $item, int $sent_version, int $actor ) {
		if ( ! Outcomes::may_archive( $item ) ) {
			return new WP_Error(
				'bwx_forge_not_archivable',
				__( 'Only work that has ended can be archived.', 'blueworx-forge' ),
				array(
					'status' => 409,
					'from'   => (string) $item['stage'],
				)
			);
		}

		return self::commit(
			$item,
			(string) $item['stage'],
			array( 'archived' => 1 ),
			array(
				'action'  => Events::ARCHIVED,
				'gate'    => '',
				'outcome' => Outcomes::ARCHIVED,
			),
			$sent_version,
			$actor
		);
	}

	/**
	 * Picks finished work back up, as a new cycle (#113).
	 *
	 * Nothing is erased and nothing is rewound. The earlier completion and
	 * release records stay attached to the cycle they happened in, and the new
	 * cycle starts with its gates unsatisfied — because a reopened item has not
	 * undone its documentation approval, it has started another round that needs
	 * one of its own (WF-4).
	 *
	 * @param array<string, mixed> $item         The item, as read.
	 * @param string               $to           Documentation Period or In Development.
	 * @param string               $reason       Why it is being reopened.
	 * @param int                  $sent_version Version moved against.
	 * @param int                  $actor        Who is doing it.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function reopen( array $item, string $to, string $reason, int $sent_version, int $actor ) {
		$reason = trim( $reason );

		if ( '' === $reason ) {
			return new WP_Error(
				'bwx_forge_reason_required',
				__( 'Reopening finished work needs a reason.', 'blueworx-forge' ),
				array( 'status' => 400 )
			);
		}

		if ( ! Reopen::allowed( $item, $to ) ) {
			return new WP_Error(
				'bwx_forge_reopen_not_allowed',
				__( 'That work cannot be reopened there.', 'blueworx-forge' ),
				array(
					'status'    => 409,
					'from'      => (string) $item['stage'],
					'attempted' => $to,
					'available' => Reopen::targets( $item ),
				)
			);
		}

		return self::commit(
			$item,
			$to,
			array(
				'cycle'          => Reopen::next_cycle( $item ),
				// The new cycle reviews from scratch. The old attempt's records
				// keep their own cycle and stop counting, rather than being
				// deleted to make room.
				'review_attempt' => 1,
			),
			array(
				'action' => Events::REOPENED,
				'gate'   => '',
				'reason' => $reason,
				// Stamped with the cycle being *left*, so the changelog reads as
				// the closing entry of the old cycle rather than as the first
				// entry of the new one.
				'cycle'  => (int) $item['cycle'],
			),
			$sent_version,
			$actor
		);
	}

	/**
	 * The WF-5 override: any stage to any stage, by the Primary administrator,
	 * marked on the item for ever (#114).
	 *
	 * Who may call this is not decided here — Tenancy\Capabilities answers that,
	 * and answers a client role with the transition lock whatever else they
	 * hold, because the lock is a security boundary rather than a workflow gate.
	 * What is decided here is that it costs a reason and leaves a mark.
	 *
	 * @param array<string, mixed> $item         The item, as read.
	 * @param string               $to           Anywhere at all.
	 * @param string               $reason       Why the workflow had to be gone round.
	 * @param int                  $sent_version Version moved against.
	 * @param int                  $actor        Who is doing it.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function override( array $item, string $to, string $reason, int $sent_version, int $actor ) {
		$reason = trim( $reason );

		if ( '' === $reason ) {
			return new WP_Error(
				'bwx_forge_reason_required',
				__( 'An override is only ever made with a reason.', 'blueworx-forge' ),
				array( 'status' => 400 )
			);
		}

		if ( ! Override::allowed( $item, $to ) ) {
			return new WP_Error(
				'bwx_forge_override_not_allowed',
				__( 'Even an override cannot put that work there.', 'blueworx-forge' ),
				array(
					'status'    => 409,
					'from'      => (string) $item['stage'],
					'attempted' => $to,
				)
			);
		}

		return self::commit(
			$item,
			$to,
			Override::mark( $reason ),
			array(
				'action' => Events::OVERRIDDEN,
				'gate'   => '',
				'reason' => $reason,
				'via'    => Events::VIA_OVERRIDE,
			),
			$sent_version,
			$actor
		);
	}

	/**
	 * Records that this work is what a client's request became (#132).
	 *
	 * On the item rather than on the submission, because the submission's own
	 * copy of the link is the client-facing one and this is ours. It says which
	 * request, in the item's own history, next to the creation entry — so
	 * "where did this come from" is answered by reading the card rather than by
	 * knowing to look in the intake table.
	 *
	 * The client's words are not copied into the entry. A changelog is not the
	 * place to keep a second copy of text that is fixed somewhere else (REQ-1);
	 * the id is what makes the record findable, and the record is what has the
	 * words in it.
	 *
	 * @param array<string, mixed> $item       The work, created or linked.
	 * @param array<string, mixed> $submission The request it answers.
	 * @param int                  $actor      WordPress user id of the converter.
	 */
	public static function record_conversion( array $item, array $submission, int $actor ): void {
		Events::append(
			array(
				'item_id'        => (string) $item['id'],
				'client_site_id' => (string) $item['client_site_id'],
				'action'         => Events::CONVERTED,
				'to_stage'       => (string) $item['stage'],
				'reason'         => (string) ( $submission['title'] ?? '' ),
				'detail'         => (string) ( $submission['id'] ?? '' ),
				'cycle'          => (int) $item['cycle'],
				'actor'          => $actor,
			)
		);
	}

	/**
	 * Records what conversion itself established, as the Future Idea gate's own
	 * requirements (#132).
	 *
	 * The three records written here are not a way round the gate; they are the
	 * gate, answered. G-FUTURE-IDEA asks which site this is scoped to, where it
	 * came from, and whether it has been submitted for triage — and a
	 * conversion has just answered all three by definition. The site came off
	 * the submission and could not have been anything else, the source is a
	 * client request and the id says which, and choosing Triage as the entry
	 * stage *is* submitting it.
	 *
	 * They are written as ordinary gate records, with the converting person's
	 * name and the time on them, rather than by skipping the gate. That
	 * distinction is the whole reason this is here and not a flag on move():
	 * somebody reading the item afterwards sees three requirements completed by
	 * a named person on a date, which is true, instead of a stage the system
	 * silently allowed.
	 *
	 * The fourth requirement is the problem statement, and it is a field. It is
	 * filled from the client's description when the item is created, so there
	 * is nothing to record for it here.
	 *
	 * @param array<string, mixed> $item       The work just created.
	 * @param array<string, mixed> $submission The request it answers.
	 * @param int                  $actor      WordPress user id of the converter.
	 */
	public static function record_intake_gate( array $item, array $submission, int $actor ): void {
		$answers = array(
			'G-FUTURE-IDEA-2' => (string) $item['client_site_id'],
			'G-FUTURE-IDEA-3' => 'client-request:' . (string) ( $submission['id'] ?? '' ),
			'G-FUTURE-IDEA-4' => (string) ( $submission['title'] ?? '' ),
		);

		foreach ( $answers as $requirement => $value ) {
			GateRecords::complete(
				array(
					'item_id'        => (string) $item['id'],
					'client_site_id' => (string) $item['client_site_id'],
					'requirement'    => $requirement,
					'value'          => $value,
					'cycle'          => (int) $item['cycle'],
					'attempt'        => (int) $item['review_attempt'],
					'actor'          => $actor,
				)
			);
		}
	}

	/**
	 * Records the creation of an item, so its history starts where it does.
	 *
	 * @param array<string, mixed> $item  The item as created.
	 * @param int                  $actor WordPress user id of the author.
	 */
	public static function record_creation( array $item, int $actor ): void {
		Events::append(
			array(
				'item_id'        => (string) $item['id'],
				'client_site_id' => (string) $item['client_site_id'],
				'action'         => Events::CREATED,
				'to_stage'       => (string) $item['stage'],
				'gate'           => Transitions::CREATE_GATE,
				'cycle'          => (int) $item['cycle'],
				'actor'          => $actor,
			)
		);
	}

	/**
	 * Which requirements stand between an item and a stage, right now.
	 *
	 * Read by the item panel so a person can see what a move will ask for
	 * *before* being refused, which is the difference between a gate and an
	 * ambush.
	 *
	 * @param array<string, mixed>             $item     The item, as read.
	 * @param string                           $to       Where it would go.
	 * @param array<int, array<string, mixed>> $children Its children.
	 * @param string                           $reason   An over-allocation reason offered with the move.
	 * @return array{unmet: array<int, array<string, mixed>>, checks: array<int, array<string, mixed>>}
	 */
	public static function readiness( array $item, string $to, array $children = array(), string $reason = '' ): array {
		$gates = array( Transitions::gate_for( (string) $item['stage'], $to ), Transitions::entry_gate_for( $to ) );

		return self::evaluate( $item, $gates, $children, $reason );
	}

	/**
	 * Evaluates a list of gates and merges the results.
	 *
	 * @param array<string, mixed>             $item     The item, as read.
	 * @param array<int, string>               $gates    Gate names; blanks skipped.
	 * @param array<int, array<string, mixed>> $children Its children.
	 * @param string                           $reason   An over-allocation reason offered with the move.
	 * @return array{unmet: array<int, array<string, mixed>>, checks: array<int, array<string, mixed>>}
	 */
	private static function evaluate( array $item, array $gates, array $children, string $reason = '' ): array {
		$records = GateRecords::current_for( $item );
		$unmet   = array();
		$checks  = array();
		$gates   = array_unique( array_filter( $gates ) );

		$context = array(
			'children' => $children,
			'capacity' => array(
				'over'   => array(),
				'reason' => $reason,
			),
		);

		/*
		 * Worked out once for the whole evaluation rather than once per gate. A
		 * move can run an exit gate and an entry gate, both of which may ask,
		 * and the answer cannot differ between them — it is the same item over
		 * the same weeks.
		 */
		if ( self::asks_about_capacity( $gates ) ) {
			$context['capacity']['over'] = Impact::of( $item )['over'];
		}

		foreach ( $gates as $gate ) {
			$result = Gates::evaluate( $gate, $item, $records, $context );
			$unmet  = array_merge( $unmet, $result['unmet'] );
			$checks = array_merge( $checks, $result['checks'] );
		}

		return array(
			'unmet'  => $unmet,
			'checks' => $checks,
		);
	}

	/**
	 * Whether any gate in this move runs the capacity check.
	 *
	 * Asked before the reading rather than after. Impact::of() is two queries
	 * across every client, and most moves in the workflow have nothing to do
	 * with capacity.
	 *
	 * @param array<int, string> $gates Gate names.
	 * @return bool
	 */
	private static function asks_about_capacity( array $gates ): bool {
		foreach ( $gates as $gate ) {
			foreach ( Gates::requirements( $gate ) as $requirement ) {
				if ( 'capacity' === (string) ( $requirement['check'] ?? '' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * The mark and the record left by going ahead with an over-allocation.
	 *
	 * A reason only reaches here when the gate actually needed one — an
	 * over-allocation offered without a reason is refused above, and a reason
	 * offered when nobody was over-booked is not a decision anybody made. So
	 * this asks the same question the gate asked before writing anything down.
	 *
	 * @param array<string, mixed> $item   The item, as read.
	 * @param string               $to     Where it is going.
	 * @param string               $reason Why the week will take it.
	 * @param int                  $actor  Who decided.
	 * @return array<string, mixed> What to write onto the item.
	 */
	private static function over_allocation( array $item, string $to, string $reason, int $actor ): array {
		if ( '' === trim( $reason ) || Impact::clear( Impact::of( $item ) ) ) {
			return array();
		}

		Events::append(
			array(
				'item_id'        => (string) $item['id'],
				'client_site_id' => (string) $item['client_site_id'],
				'action'         => Events::OVER_ALLOCATED,
				'from_stage'     => (string) $item['stage'],
				'to_stage'       => $to,
				'reason'         => $reason,
				'actor'          => $actor,
			)
		);

		return CapacityOverride::mark( $reason );
	}

	/**
	 * The gate refusal for a move, or null when the gates are satisfied.
	 *
	 * @param array<string, mixed> $item  The item, as read.
	 * @param string               $to    Where it would go.
	 * @param array<int, string>   $gates Gate names.
	 * @param string               $reason An over-allocation reason offered with the move.
	 * @return WP_Error|null
	 */
	private static function gate_refusal( array $item, string $to, array $gates, string $reason = '' ): ?WP_Error {
		$result = self::evaluate( $item, $gates, Items::children( (string) $item['id'] ), $reason );

		if ( array() === $result['unmet'] ) {
			return null;
		}

		return self::gate_error( $item, $to, $result['unmet'], $result['checks'] );
	}

	/**
	 * Refuses a site's *first* go-live while its onboarding is unfinished (#166).
	 *
	 * Separate from the workflow gates above rather than added to them, because
	 * it is not a fact about this item. Every gate in Work\Gates asks whether
	 * this piece of work is ready; this asks whether the *site* is, and two
	 * items on the same site get the same answer. Folding it into the gate table
	 * would have meant a requirement whose evidence lives on another record
	 * entirely.
	 *
	 * It refuses in the gate's own shape, because a board already draws unmet
	 * requirements and a second shape would need a second thing to render it.
	 *
	 * @param array<string, mixed> $item The item being moved.
	 * @param string               $to   The stage it is moving to.
	 * @return WP_Error|null Null when nothing is in the way.
	 */
	private static function onboarding_refusal( array $item, string $to ) {
		if ( Stages::RELEASED !== $to ) {
			return null;
		}

		$site_id  = (string) ( $item['client_site_id'] ?? '' );
		$progress = Progress::of( Steps::for_site( $site_id ) );

		if ( ! LaunchGate::refuses( $progress, Events::has_ever_reached( $site_id, Stages::RELEASED ) ) ) {
			return null;
		}

		return self::gate_error( $item, $to, LaunchGate::unmet( $progress ), array() );
	}

	/**
	 * The gate failure, in the shape the REST layer publishes.
	 *
	 * @param array<string, mixed>             $item      The item, unchanged.
	 * @param string                           $attempted Where it tried to go.
	 * @param array<int, array<string, mixed>> $unmet     Every unmet requirement.
	 * @param array<int, array<string, mixed>> $checks    Every system result.
	 * @return WP_Error
	 */
	private static function gate_error( array $item, string $attempted, array $unmet, array $checks ): WP_Error {
		return new WP_Error(
			self::GATE_FAILURE,
			__( 'That work is not ready to move yet.', 'blueworx-forge' ),
			array(
				'status'    => 409,
				'item_id'   => (string) $item['id'],
				'stage'     => (string) $item['stage'],
				'attempted' => $attempted,
				'unmet'     => array_values( $unmet ),
				'checks'    => array_values( $checks ),
			)
		);
	}

	/**
	 * The G-BLOCKED-ENTRY answers that were not given.
	 *
	 * Blocked is entered with its answers in the request rather than recorded
	 * one at a time beforehand — an item is blocked at the moment somebody finds
	 * out it is blocked, and asking them to complete five records first would
	 * mean the board says work is progressing while everyone knows it is not.
	 *
	 * @param array<string, mixed> $details The answers given.
	 * @return array<int, array<string, mixed>> Unmet requirements, in gate order.
	 */
	private static function missing_blocker_details( array $details ): array {
		$fields = array(
			'G-BLOCKED-ENTRY-1' => 'reason',
			'G-BLOCKED-ENTRY-2' => 'owner',
			'G-BLOCKED-ENTRY-3' => 'dependency',
			'G-BLOCKED-ENTRY-4' => 'target_date',
			'G-BLOCKED-ENTRY-5' => 'next_action',
		);

		$unmet = array();

		foreach ( $fields as $requirement => $field ) {
			if ( '' === trim( (string) ( $details[ $field ] ?? '' ) ) ) {
				$unmet[] = self::unmet_of( $requirement );
			}
		}

		return $unmet;
	}

	/**
	 * One requirement in the unmet shape.
	 *
	 * @param string $requirement_id Requirement id.
	 * @return array<string, mixed>
	 */
	private static function unmet_of( string $requirement_id ): array {
		$requirement = (array) Gates::requirement( $requirement_id );

		return array(
			'id'           => $requirement_id,
			'label'        => (string) ( $requirement['label'] ?? $requirement_id ),
			'satisfied_by' => (string) ( $requirement['satisfied_by'] ?? '' ),
			'type'         => (string) ( $requirement['type'] ?? 'text' ),
			'evidence'     => (bool) ( $requirement['evidence'] ?? false ),
			'who'          => (string) ( $requirement['who'] ?? Gates::ANY ),
			'by'           => (string) ( $requirement['by'] ?? Gates::BY_RECORD ),
			'fields'       => (array) ( $requirement['fields'] ?? array() ),
		);
	}

	/**
	 * The refusals every move shares: no such stage, already there, ended, and
	 * a stage this work type may never hold (#110).
	 *
	 * @param array<string, mixed> $item The item, as read.
	 * @param string               $to   Where it would go.
	 * @return WP_Error|null
	 */
	private static function refuse_bad_target( array $item, string $to ): ?WP_Error {
		if ( ! Stages::exists( $to ) ) {
			return new WP_Error(
				'bwx_forge_unknown_stage',
				__( 'There is no such stage.', 'blueworx-forge' ),
				array( 'status' => 400 )
			);
		}

		if ( (string) $item['stage'] === $to ) {
			return new WP_Error(
				'bwx_forge_already_there',
				__( 'That item is already at that stage.', 'blueworx-forge' ),
				array( 'status' => 409 )
			);
		}

		if ( Outcomes::is_closed( $item ) || ! empty( $item['archived'] ) ) {
			return self::closed_error();
		}

		if ( ! Stages::may_hold( $to, (string) $item['work_type'] ) ) {
			/*
			 * #110. The forward table already refuses this, and so does this
			 * line, and that duplication is deliberate: every other mover —
			 * return, unblock, and whatever arrives next — comes through here,
			 * and a conditional stage guarded in only one of them is not
			 * guarded.
			 */
			return new WP_Error(
				'bwx_forge_stage_not_for_type',
				__( 'That stage only exists for bugs.', 'blueworx-forge' ),
				array(
					'status'    => 409,
					'attempted' => $to,
					'work_type' => (string) $item['work_type'],
				)
			);
		}

		return null;
	}

	/**
	 * The one answer for work that has already ended.
	 *
	 * @return WP_Error
	 */
	private static function closed_error(): WP_Error {
		return new WP_Error(
			'bwx_forge_work_ended',
			__( 'That work has ended, so it does not move any more.', 'blueworx-forge' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Writes a move and its changelog entry in one transaction.
	 *
	 * @param array<string, mixed> $item         The item, as read.
	 * @param string               $to           Stage after the move.
	 * @param array<string, mixed> $also         Other columns this move sets.
	 * @param array<string, mixed> $event        The changelog entry's own parts.
	 * @param int                  $sent_version Version moved against.
	 * @param int                  $actor        Who is doing it.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function commit( array $item, string $to, array $also, array $event, int $sent_version, int $actor ) {
		global $wpdb;

		$from = (string) $item['stage'];

		$wpdb->query( 'START TRANSACTION' );

		$moved = Items::apply_stage( (string) $item['id'], $to, $sent_version, $also );

		if ( ! $moved ) {
			$wpdb->query( 'ROLLBACK' );

			// Either somebody moved it first, or the write failed. Both are the
			// same answer to the caller: what you were looking at is out of
			// date, here is where the item actually is.
			return new WP_Error(
				'bwx_forge_stale_version',
				__( 'That item changed elsewhere first — reload and try again.', 'blueworx-forge' ),
				array(
					'status'  => 409,
					'current' => Items::get( (string) $item['id'] ),
				)
			);
		}

		$recorded = Events::append(
			array_merge(
				array(
					'item_id'        => (string) $item['id'],
					'client_site_id' => (string) $item['client_site_id'],
					'from_stage'     => $from,
					'to_stage'       => $to,
					'cycle'          => (int) $item['cycle'],
					'attempt'        => (int) $item['review_attempt'],
					'actor'          => $actor,
				),
				$event
			)
		);

		if ( ! $recorded ) {
			// A move nobody can account for afterwards is worse than a move that
			// did not happen, so the stage change goes back too.
			$wpdb->query( 'ROLLBACK' );

			return new WP_Error(
				'bwx_forge_write_failed',
				__( 'That move could not be recorded, so it was not made.', 'blueworx-forge' ),
				array( 'status' => 500 )
			);
		}

		/*
		 * #172. Arriving somewhere the client hears about raises the event that
		 * will become their email, inside the same transaction as the move.
		 *
		 * Inside, for the same reason the changelog entry is: a move the client
		 * is never told about is a move that half happened. Outside, a crash
		 * between the two would leave work released and nobody informed, and
		 * nothing later would know to look.
		 *
		 * A refused claim is not a failure and must not roll anything back. It
		 * means this release was already noticed — by a sync, a retry, or
		 * somebody's second click — and the whole point of the event id is that
		 * the second one quietly does nothing.
		 */
		$raises = Notifications::for_stage( $to );

		if ( '' !== $raises ) {
			Register::claim(
				array(
					'kind'           => $raises,
					'subject_id'     => (string) $item['id'],
					'occurrence'     => (int) $item['cycle'],
					'client_id'      => (string) ( $item['client_id'] ?? '' ),
					'client_site_id' => (string) $item['client_site_id'],
				)
			);
		}

		$wpdb->query( 'COMMIT' );

		return Items::get( (string) $item['id'] );
	}
}
