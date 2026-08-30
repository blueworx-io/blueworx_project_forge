<?php
/**
 * The studio deciding whether a step is done.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Onboarding;

/**
 * #163, ONB-2. Approve, return with feedback, or waive.
 *
 * Three decisions and no fourth. There is no "reject", because a step that is
 * not done is a step that comes back with something to change — a client whose
 * work is rejected has nowhere to go, and the point of the review is to say
 * what would make it acceptable.
 *
 * Two of the three insist on a reason and one does not, which is the whole
 * shape of the class. Approving needs no explanation: the work speaks for
 * itself and the history records who said so. Returning without one is an
 * instruction with no content, and the client emails to ask what we meant —
 * which is exactly what their own screen exists to avoid. Waiving without one
 * leaves nobody able to say, a year later, why a launch-critical step was not
 * done.
 *
 * **The feedback is read out of the history, never stored on the step.** A
 * column would have to be cleared each time the step moved on, by whichever
 * caller happened to move it, and the first one to forget leaves a client
 * reading last month's complaint against work that has since been approved.
 * The history already holds what was asked and when, so it is asked instead —
 * the same reasoning that keeps overdue out of the schema (#161) and completion
 * out of it (#164).
 */
final class Review {

	/**
	 * What a reviewer may decide.
	 *
	 * @var array<int, string>
	 */
	public const DECISIONS = array( 'approve', 'return', 'not-applicable' );

	/**
	 * Longest a piece of feedback may be. It lands in the history's reason.
	 */
	public const MAX_REASON = StepEvents::MAX_REASON;

	/**
	 * Where each decision puts the step.
	 *
	 * @var array<string, string>
	 */
	private const LANDS = array(
		'approve'        => Statuses::APPROVED,
		'return'         => Statuses::RETURNED,
		'not-applicable' => Statuses::NOT_APPLICABLE,
	);

	/**
	 * The decisions that cannot be made without saying why.
	 *
	 * @var array<int, string>
	 */
	private const NEEDS_REASON = array( 'return', 'not-applicable' );

	/**
	 * Why this decision cannot be made.
	 *
	 * @param string               $decision One of {@see self::DECISIONS}.
	 * @param array<string, mixed> $step     The step, as read.
	 * @param string               $reason   What the reviewer typed.
	 * @return string Empty when the decision may be made; otherwise a sentence.
	 */
	public static function refusal( string $decision, array $step, string $reason ): string {
		if ( ! in_array( $decision, self::DECISIONS, true ) ) {
			return 'That is not something you can decide about a step.';
		}

		$status = (string) ( $step['status'] ?? '' );

		if ( in_array( $status, Statuses::SETTLED, true ) ) {
			// Approving an approved step writes a second approval into the
			// history and tells nobody anything.
			return 'This step is already finished.';
		}

		if ( in_array( $decision, self::NEEDS_REASON, true ) && '' === trim( $reason ) ) {
			return 'return' === $decision
				? 'Say what needs changing. The client sees this, and "send it back" on its own tells them nothing.'
				: 'Say why this one does not apply. Nobody will remember a year from now.';
		}

		if ( 'not-applicable' === $decision && empty( $step['allows_not_applicable'] ) ) {
			/*
			 * ONB-1 lets a template mark which steps may be waived. A step that
			 * may not be is one somebody has to actually do, and a reason is
			 * not a way round it.
			 */
			return 'This step cannot be marked as not applicable. The checklist requires it.';
		}

		/*
		 * Approving and returning both answer something that was handed over,
		 * so there has to be something there to answer. Waiving does not: it
		 * decides the step is not needed at all, and waiting for somebody to
		 * submit work they are never going to do would be a deadlock.
		 */
		if ( 'not-applicable' !== $decision && Statuses::SUBMITTED !== $status ) {
			return 'Nothing has been sent to us for this step yet.';
		}

		return '';
	}

	/**
	 * Where a decision puts the step.
	 *
	 * @param string $decision One of {@see self::DECISIONS}.
	 * @return string Empty when it is not a decision.
	 */
	public static function status_for( string $decision ): string {
		return self::LANDS[ $decision ] ?? '';
	}

	/**
	 * The feedback a client should currently see on a step.
	 *
	 * Only while the step is actually returned — see the class comment for why
	 * that condition is here rather than in whoever moves the step on.
	 *
	 * @param string                           $status  Where the step is now.
	 * @param array<int, array<string, mixed>> $history Its entries.
	 * @return string
	 */
	public static function feedback_from( string $status, array $history ): string {
		if ( Statuses::RETURNED !== $status ) {
			return '';
		}

		$latest = null;

		foreach ( $history as $entry ) {
			if ( Statuses::RETURNED !== (string) ( $entry['to_status'] ?? '' ) ) {
				continue;
			}

			// Ordered here rather than trusted from the caller. Every history
			// in this product is read oldest first, but nothing should break if
			// one arrives another way round.
			if ( null === $latest || (int) ( $entry['occurred_at'] ?? 0 ) >= (int) ( $latest['occurred_at'] ?? 0 ) ) {
				$latest = $entry;
			}
		}

		return null === $latest ? '' : (string) ( $latest['reason'] ?? '' );
	}

	/**
	 * Records a decision, and the history entry that says who made it.
	 *
	 * @param array<string, mixed> $step     The step, as read.
	 * @param string               $decision One of {@see self::DECISIONS}.
	 * @param string               $reason   What the reviewer typed.
	 * @param int                  $actor    Who decided.
	 * @return array{step?: array<string, mixed>, message?: string}
	 */
	public static function record( array $step, string $decision, string $reason, int $actor ): array {
		$refusal = self::refusal( $decision, $step, $reason );

		if ( '' !== $refusal ) {
			return array( 'message' => $refusal );
		}

		if ( $actor <= 0 ) {
			return array( 'message' => 'We could not tell who was deciding, so nothing was saved.' );
		}

		$id      = (string) ( $step['id'] ?? '' );
		$from    = (string) ( $step['status'] ?? '' );
		$to      = self::status_for( $decision );
		$version = (int) ( $step['record_version'] ?? 1 );

		if ( ! Steps::apply_status( $id, $to, $version ) ) {
			return array( 'message' => 'Somebody else changed this step while you were looking at it. Reload it and try again.' );
		}

		StepEvents::append(
			array(
				'step_id'          => $id,
				'client_site_id'   => (string) ( $step['client_site_id'] ?? '' ),
				'action'           => StepEvents::MOVED,
				'from_status'      => $from,
				'to_status'        => $to,
				'reason'           => mb_substr( trim( $reason ), 0, self::MAX_REASON ),
				'actor'            => $actor,
				'source_interface' => 'studio',
			)
		);

		return array( 'step' => Steps::get( $id ) ?? $step );
	}
}
