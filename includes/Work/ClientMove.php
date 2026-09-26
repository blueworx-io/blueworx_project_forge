<?php
/**
 * When a task's client may change.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

use Blueworx\Forge\Tenancy\PersonReach;

/**
 * #390. Tasks get added under the wrong client, and this is the rule for
 * putting one right (2026-09-26).
 *
 * A task's client can change until work starts. From In Development on, the
 * hours are spent against the client's allowance, so the client is fixed with
 * them. A task linked to other work — a parent, children, a dependency either
 * way — is refused too: every one of those links is kept to one site, and
 * moving one end of it would leave the link crossing two clients.
 *
 * Pure: the route reads the item and its links, and asks here.
 */
final class ClientMove {

	/**
	 * Work has started, so the client is fixed.
	 */
	public const STARTED = 'work_started';

	/**
	 * Work has ended, and stays with the client it ended under.
	 */
	public const ENDED = 'work_ended';

	/**
	 * Linked to other work, which is kept on one site.
	 */
	public const LINKED = 'work_linked';

	/**
	 * Came from the client's own request, so the client is certain.
	 */
	public const REQUESTED = 'work_requested';

	/**
	 * The client has seen or written comments on it, which would travel with it.
	 */
	public const CLIENT_SEEN = 'client_has_seen_it';

	/**
	 * Made by a recurring task or a reminder, which names the client itself.
	 */
	public const RECURRING = 'work_recurring';

	/**
	 * Hours have been used on it, against the client it is on.
	 */
	public const HOURS_USED = 'hours_used';

	/**
	 * The refusals that follow from the item alone: started, ended, or made
	 * by a schedule. Asked before anything else is read.
	 *
	 * @param array<string, mixed> $item The item, as read.
	 * @return array{code: string, message: string}|null
	 */
	public static function fixed( array $item ): ?array {
		if ( self::started( $item ) ) {
			return array(
				'code'    => self::STARTED,
				'message' => __( "This task has started, so its client can't change. Hours are already counted against this client.", 'blueworx-forge' ),
			);
		}

		if ( Outcomes::is_closed( $item ) ) {
			return array(
				'code'    => self::ENDED,
				'message' => __( "This task has ended, so its client can't change.", 'blueworx-forge' ),
			);
		}

		// The next copy would be made on the old client again, so the change
		// belongs on the recurring task or reminder itself.
		if ( '' !== (string) ( $item['recurring_id'] ?? '' ) ) {
			return array(
				'code'    => self::RECURRING,
				'message' => __( 'This task comes from a recurring task or reminder. Change the client there instead.', 'blueworx-forge' ),
			);
		}

		return null;
	}

	/**
	 * The refusal for hours already used, or null when none have been.
	 * Only work that has started uses hours, so this is a guard for a ledger
	 * that disagrees with the stage.
	 *
	 * @param float $used Hours the ledger shows used against the item.
	 * @return array{code: string, message: string}|null
	 */
	public static function hours_used( float $used ): ?array {
		if ( $used <= 0.0 ) {
			return null;
		}

		return array(
			'code'    => self::HOURS_USED,
			'message' => __( "Hours have already been used on this task, so its client can't change.", 'blueworx-forge' ),
		);
	}

	/**
	 * Why this item's client may not change, or null when it may.
	 *
	 * @param array<string, mixed> $item        The item, as read.
	 * @param bool                 $linked      Whether it has a parent, children or dependencies.
	 * @param bool                 $requested   Whether a client's request was converted into it.
	 * @param bool                 $client_seen Whether it has comments the client can see or wrote.
	 * @return array{code: string, message: string}|null
	 */
	public static function refusal( array $item, bool $linked, bool $requested = false, bool $client_seen = false ): ?array {
		$fixed = self::fixed( $item );

		if ( null !== $fixed ) {
			return $fixed;
		}

		if ( $linked ) {
			return array(
				'code'    => self::LINKED,
				'message' => __( 'Move it on its own first: it is linked to other work.', 'blueworx-forge' ),
			);
		}

		/*
		 * The two ways the client is already part of the task. Either would
		 * carry one client's words to another: their request stays linked to
		 * the work, and their comments move with it.
		 */
		if ( $requested ) {
			return array(
				'code'    => self::REQUESTED,
				'message' => __( "This task came from the client's own request, so its client can't change.", 'blueworx-forge' ),
			);
		}

		if ( $client_seen ) {
			return array(
				'code'    => self::CLIENT_SEEN,
				'message' => __( "The client can already see comments on this task, so its client can't change.", 'blueworx-forge' ),
			);
		}

		return null;
	}

	/**
	 * Whether work on this item has started: it is at In Development or
	 * later, or blocked out of one of those.
	 *
	 * @param array<string, mixed> $item The item, as read.
	 * @return bool
	 */
	public static function started( array $item ): bool {
		$stage = (string) ( $item['stage'] ?? '' );

		if ( Stages::BLOCKED === $stage ) {
			$stage = (string) ( $item['prior_stage'] ?? '' );
		}

		return Stages::position( $stage ) >= Stages::position( 'in-development' );
	}

	/**
	 * The people a move took out of a seat, in seat order, each once.
	 *
	 * @param array<string, mixed> $before The seats before.
	 * @param array<string, mixed> $after  The seats after.
	 * @return array<int, string> Person ids.
	 */
	public static function taken_off( array $before, array $after ): array {
		$gone = array();

		foreach ( PersonReach::SEATS as $field ) {
			$was = (string) ( $before[ $field ] ?? '' );

			if ( '' !== $was && '' === (string) ( $after[ $field ] ?? '' ) && ! in_array( $was, $gone, true ) ) {
				$gone[] = $was;
			}
		}

		return $gone;
	}
}
