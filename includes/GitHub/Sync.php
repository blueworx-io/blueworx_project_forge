<?php
/**
 * Keeps a linked issue in step with its task.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\GitHub;

use Blueworx\Forge\Work\Outcomes;
use Blueworx\Forge\Work\Stages;

/**
 * #682. When a linked task moves, its issue shows the stage as one
 * `forge:` label, and closes and reopens with the task. When it is renamed
 * the issue is too, and a Forge-made issue's description follows the task's.
 * The change goes through the outbox, so neither waits on GitHub.
 */
final class Sync {

	/**
	 * What every stage label starts with, so Forge only ever touches its own.
	 */
	public const LABEL_PREFIX = 'forge: ';

	/**
	 * The fields a Forge-made issue's description is written from.
	 */
	public const BODY_FIELDS = array( 'problem', 'scope', 'requirements', 'acceptance_criteria' );

	/**
	 * The label for a stage.
	 *
	 * @param string $stage The stage.
	 * @return string
	 */
	public static function label_for( string $stage ): string {
		return self::LABEL_PREFIX . Stages::label( $stage );
	}

	/**
	 * Whether an item's issue should be open or closed, and why.
	 *
	 * @param array<string, mixed> $item The item.
	 * @return array{state: string, reason: string}
	 */
	public static function state_for( array $item ): array {
		if ( Stages::RELEASED === (string) ( $item['stage'] ?? '' ) ) {
			return array(
				'state'  => 'closed',
				'reason' => 'completed',
			);
		}

		if ( Outcomes::is_closed( $item ) ) {
			return array(
				'state'  => 'closed',
				'reason' => 'not_planned',
			);
		}

		return array(
			'state'  => 'open',
			'reason' => '',
		);
	}

	/**
	 * Asks for whatever the issue needs after a move.
	 *
	 * @param array<string, mixed> $before The item before the move.
	 * @param array<string, mixed> $after  The item after it.
	 */
	public static function moved( array $before, array $after ): void {
		$id   = (string) ( $after['id'] ?? '' );
		$link = '' === $id ? null : Links::for_item( $id );

		if ( null === $link ) {
			return;
		}

		$stage = (string) ( $after['stage'] ?? '' );

		if ( (string) ( $before['stage'] ?? '' ) !== $stage ) {
			Jobs::queue( Outbound::SET_LABEL, $id, array( 'stage' => $stage ) );
		}

		$state = self::state_wanted( $before, $after, $link );

		if ( null !== $state ) {
			Jobs::queue( Outbound::SET_STATE, $id, $state );
		}
	}

	/**
	 * Whether a move needs the issue opened or closed, and how.
	 *
	 * The move itself decides, and so does what GitHub last showed: work
	 * sent back after its issue was closed in GitHub is open on both sides
	 * of the move, but its issue still has to reopen. Approving it is open
	 * on both sides too, and leaves the issue closed.
	 *
	 * @param array<string, mixed> $before The item before the move.
	 * @param array<string, mixed> $after  The item after it.
	 * @param array<string, mixed> $link   The item's link.
	 * @return array{state: string, reason: string}|null Null when nothing needs sending.
	 */
	public static function state_wanted( array $before, array $after, array $link ): ?array {
		$state = self::state_for( $after );

		if ( self::state_for( $before ) !== $state ) {
			return $state;
		}

		// Only a send-back to before review reopens an issue GitHub closed. A
		// pull request closing the issue is how finished work reaches review,
		// so a move on to review or past it leaves that close alone.
		$at = Stages::position( (string) ( $after['stage'] ?? '' ) );

		if (
			'open' === $state['state']
			&& 'closed' === (string) ( $link['state_seen'] ?? '' )
			&& 0 <= $at
			&& $at < Stages::position( 'in-review' )
		) {
			return $state;
		}

		return null;
	}

	/**
	 * Asks for the issue to show the item as it is now, straight after the
	 * two are linked: the item may have moved on, or ended, while the issue
	 * was waiting to be made.
	 *
	 * @param array<string, mixed> $item The item.
	 */
	public static function linked( array $item ): void {
		$id = (string) ( $item['id'] ?? '' );

		if ( '' === $id ) {
			return;
		}

		Jobs::queue( Outbound::SET_LABEL, $id, array( 'stage' => (string) ( $item['stage'] ?? '' ) ) );

		$state = self::state_for( $item );

		if ( 'closed' === $state['state'] ) {
			Jobs::queue( Outbound::SET_STATE, $id, $state );
		}
	}

	/**
	 * Asks for whatever the issue needs after an edit.
	 *
	 * @param array<string, mixed> $before The item before the edit.
	 * @param array<string, mixed> $after  The item after it.
	 */
	public static function updated( array $before, array $after ): void {
		$id = (string) ( $after['id'] ?? '' );

		if ( '' === $id ) {
			return;
		}

		foreach ( self::wanted( $before, $after, Links::for_item( $id ) ) as $kind => $payload ) {
			Jobs::queue( $kind, $id, $payload );
		}
	}

	/**
	 * What an edit needs sent to the issue, and what each job is asked for.
	 *
	 * A title is sent only when it is not the one last seen there, so a
	 * rename that came from GitHub is not sent back. A description is only
	 * ever written to an issue Forge made: one raised in GitHub is its
	 * author's, and Forge never edits it.
	 *
	 * Each save asks for something different, so a save made while the job
	 * for the last one is running puts the job back in the queue rather than
	 * being taken as already sent.
	 *
	 * @param array<string, mixed>      $before The item before the edit.
	 * @param array<string, mixed>      $after  The item after it.
	 * @param array<string, mixed>|null $link   The item's link, or null.
	 * @return array<string, array<string, mixed>> Job kind to what it is asked for.
	 */
	public static function wanted( array $before, array $after, ?array $link ): array {
		if ( null === $link ) {
			return array();
		}

		$jobs  = array();
		$title = (string) ( $after['title'] ?? '' );

		if ( (string) ( $before['title'] ?? '' ) !== $title && (string) ( $link['title_seen'] ?? '' ) !== $title ) {
			$jobs[ Outbound::SET_TITLE ] = array( 'title' => $title );
		}

		if ( 'forge' !== (string) ( $link['origin'] ?? '' ) ) {
			return $jobs;
		}

		foreach ( self::BODY_FIELDS as $field ) {
			if ( (string) ( $before[ $field ] ?? '' ) !== (string) ( $after[ $field ] ?? '' ) ) {
				$jobs[ Outbound::SET_BODY ] = array( 'version' => (int) ( $after['record_version'] ?? 0 ) );
				break;
			}
		}

		return $jobs;
	}
}
