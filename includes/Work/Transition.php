<?php
/**
 * The one service that moves work.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Work;

use WP_Error;

/**
 * #106. Every stage change in the product goes through this method, and nothing
 * else writes the `stage` column — Work\Items::update() refuses to, and
 * Work\Validate refuses an edit that names it.
 *
 * That single door is the point. A stage change is never only a stage change:
 * it has a gate to satisfy (#105), a changelog entry to append, and later an
 * hour reservation to move (#149) and a notification to send (#172). Anything
 * that sets the column directly gets the stage right and everything else wrong.
 *
 * **Atomic, and proved by the failure case rather than the happy one.** The
 * move and its record go in one transaction, so a failure part-way leaves the
 * item exactly as it was and leaves no half-written history. #106's acceptance
 * is precisely that.
 */
final class Transition {

	/**
	 * Moves an item one step forward.
	 *
	 * @param array<string, mixed> $item         The item, as read.
	 * @param string               $to           The stage to move to.
	 * @param int                  $sent_version Version the move was made against.
	 * @param int                  $actor        WordPress user id requesting it.
	 * @return array<string, mixed>|WP_Error The item as it now stands.
	 */
	public static function move( array $item, string $to, int $sent_version, int $actor ) {
		global $wpdb;

		$from = (string) $item['stage'];

		if ( ! Stages::exists( $to ) ) {
			return new WP_Error(
				'bwx_forge_unknown_stage',
				__( 'There is no such stage.', 'blueworx-forge' ),
				array( 'status' => 400 )
			);
		}

		if ( $from === $to ) {
			return new WP_Error(
				'bwx_forge_already_there',
				__( 'That item is already at that stage.', 'blueworx-forge' ),
				array( 'status' => 409 )
			);
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

		$gate = Transitions::gate_for( $from, $to );

		/*
		 * The gate's requirements are not evaluated yet — #105 fills that in.
		 * The name is recorded now so that when it is, every historical move
		 * already says which gate it went through.
		 */

		$wpdb->query( 'START TRANSACTION' );

		$moved = Items::apply_stage( (string) $item['id'], $to, $sent_version );

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
			array(
				'item_id'        => (string) $item['id'],
				'client_site_id' => (string) $item['client_site_id'],
				'action'         => Events::MOVED,
				'from_stage'     => $from,
				'to_stage'       => $to,
				'gate'           => $gate,
				'actor'          => $actor,
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

		$wpdb->query( 'COMMIT' );

		return Items::get( (string) $item['id'] );
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
				'actor'          => $actor,
			)
		);
	}
}
