<?php
/**
 * What Forge does with a message from GitHub.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\GitHub;

/**
 * #680. Only called with a message that has passed its signature check.
 *
 * A start: it answers a ping and ignores everything else. The work of turning
 * GitHub's issue messages into Forge items is added here, later.
 */
final class Inbound {

	/**
	 * Handles one verified message.
	 *
	 * @param string               $event   The X-GitHub-Event name.
	 * @param array<string, mixed> $payload The decoded body.
	 * @return string The outcome: pong, or ignored.
	 */
	public static function handle( string $event, array $payload ): string {
		unset( $payload );

		return 'ping' === $event ? 'pong' : 'ignored';
	}
}
