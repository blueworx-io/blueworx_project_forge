<?php
/**
 * Stale-write rejection.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Rest;

use WP_Error;

/**
 * ARCH-5. There is one canonical database, so there are no merge conflicts —
 * only stale writes. Every write carries the record version it was made
 * against; a mismatch is rejected and the current state returned, never merged,
 * and the rejection surfaces to the person who made it rather than into a
 * queue.
 *
 * The name of the parameter carrying that version is fixed here so every later
 * endpoint spells it the same way.
 */
final class Versioning {

	/**
	 * The request parameter carrying the version the write was made against.
	 */
	public const PARAM = 'record_version';

	/**
	 * Decides whether a write may proceed.
	 *
	 * @param int|null             $sent    Version the write was made against.
	 * @param int                  $current The record's version now.
	 * @param array<string, mixed> $state   The record's current state, returned
	 *                                      with a rejection so the caller can see
	 *                                      what moved underneath them.
	 * @return WP_Error|null Null when the write may proceed.
	 */
	public static function check( ?int $sent, int $current, array $state = array() ): ?WP_Error {
		if ( null === $sent ) {
			return Errors::rest(
				'missing_version',
				__( 'This write did not say which version of the record it was made against.', 'blueworx-forge' ),
				400,
				array( 'current_version' => $current )
			);
		}

		if ( $sent === $current ) {
			return null;
		}

		/*
		 * A version ahead of the current one is stale in the same sense: it was
		 * made against something this server never issued, so it cannot be
		 * reasoned about and is refused rather than guessed at.
		 */
		return Errors::rest(
			'stale_write',
			__( 'This record changed since you loaded it. Your change was not saved.', 'blueworx-forge' ),
			Errors::STATUS_CONFLICT,
			array(
				'sent_version'    => $sent,
				'current_version' => $current,
				'current'         => $state,
			)
		);
	}
}
