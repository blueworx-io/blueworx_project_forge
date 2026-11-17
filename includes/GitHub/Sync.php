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
		$id = (string) ( $after['id'] ?? '' );

		if ( '' === $id || null === Links::for_item( $id ) ) {
			return;
		}

		$stage = (string) ( $after['stage'] ?? '' );

		if ( (string) ( $before['stage'] ?? '' ) !== $stage ) {
			Jobs::queue( Outbound::SET_LABEL, $id, array( 'stage' => $stage ) );
		}

		$state = self::state_for( $after );

		if ( self::state_for( $before ) !== $state ) {
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

		foreach ( self::wanted( $before, $after, Links::for_item( $id ) ) as $kind ) {
			Jobs::queue( $kind, $id );
		}
	}

	/**
	 * What an edit needs sent to the issue.
	 *
	 * A title is sent only when it is not the one last seen there, so a
	 * rename that came from GitHub is not sent back. A description is only
	 * ever written to an issue Forge made: one raised in GitHub is its
	 * author's, and Forge never edits it.
	 *
	 * @param array<string, mixed>      $before The item before the edit.
	 * @param array<string, mixed>      $after  The item after it.
	 * @param array<string, mixed>|null $link   The item's link, or null.
	 * @return array<int, string> Job kinds.
	 */
	public static function wanted( array $before, array $after, ?array $link ): array {
		if ( null === $link ) {
			return array();
		}

		$kinds = array();
		$title = (string) ( $after['title'] ?? '' );

		if ( (string) ( $before['title'] ?? '' ) !== $title && (string) ( $link['title_seen'] ?? '' ) !== $title ) {
			$kinds[] = Outbound::SET_TITLE;
		}

		if ( 'forge' !== (string) ( $link['origin'] ?? '' ) ) {
			return $kinds;
		}

		foreach ( self::BODY_FIELDS as $field ) {
			if ( (string) ( $before[ $field ] ?? '' ) !== (string) ( $after[ $field ] ?? '' ) ) {
				$kinds[] = Outbound::SET_BODY;
				break;
			}
		}

		return $kinds;
	}
}
