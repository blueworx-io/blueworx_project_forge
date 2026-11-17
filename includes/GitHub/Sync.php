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
 * `forge:` label, and closes and reopens with the task. The change goes
 * through the outbox, so a move never waits on GitHub.
 */
final class Sync {

	/**
	 * What every stage label starts with, so Forge only ever touches its own.
	 */
	public const LABEL_PREFIX = 'forge: ';

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
}
