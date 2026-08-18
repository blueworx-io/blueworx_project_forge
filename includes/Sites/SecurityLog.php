<?php
/**
 * The record of refused client-site requests.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Sites;

/**
 * ARCH-6 requires that use of a revoked key is logged as a security event, and
 * the client transition lock in docs/architecture/workflow-state-machine.md
 * requires each refusal to record the source interface and time. Both land here.
 *
 * Every refusal is recorded, not only the revoked-key case: a single revoked key
 * being tried once is unremarkable, and the same key tried four hundred times
 * overnight is the thing worth seeing — which only reads that way if the near
 * misses are in the log beside it.
 *
 * The store is capped and rotates. This is a signal that something needs
 * looking at, not an audit trail; Milestone 2's Client Site Integration record
 * is where durable key state belongs. Each entry also fires an action so a site
 * can forward these somewhere real without waiting for that.
 */
final class SecurityLog {

	/**
	 * Option holding recent refusals.
	 */
	public const OPTION = 'bwx_forge_security_log';

	/**
	 * Fired for every refusal, so a site can forward it to real monitoring.
	 */
	public const HOOK = 'bwx_forge_security_event';

	/**
	 * How many refusals are kept. Enough to see a pattern, small enough that the
	 * option stays a sensible size.
	 */
	public const MAX_ENTRIES = 200;

	/**
	 * Records a refused request.
	 *
	 * @param string               $site_id The site the request claimed to be, which
	 *                                      may be a site that does not exist.
	 * @param string               $reason  Machine-readable reason, e.g. revoked_site.
	 * @param array<string, mixed> $context Anything else worth keeping.
	 */
	public static function refused( string $site_id, string $reason, array $context = array() ): void {
		$entry = array(
			'time'      => bwx_forge_now(),
			'site_id'   => $site_id,
			'reason'    => $reason,
			'interface' => 'rest',
			'ip'        => self::request_ip(),
			'context'   => $context,
		);

		$entries = self::entries();
		array_unshift( $entries, $entry );

		update_option( self::OPTION, array_slice( $entries, 0, self::MAX_ENTRIES ) );

		// Spelled out rather than passed as self::HOOK: a hook name built from a
		// constant is invisible to the tooling that checks every hook carries the
		// plugin's prefix, and to anybody grepping for who fires this.
		do_action( 'bwx_forge_security_event', $entry );
	}

	/**
	 * The most recent refusals, newest first.
	 *
	 * @param int $limit How many to return.
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( int $limit = self::MAX_ENTRIES ): array {
		return array_slice( self::entries(), 0, max( 0, $limit ) );
	}

	/**
	 * Empties the log.
	 */
	public static function clear(): void {
		update_option( self::OPTION, array() );
	}

	/**
	 * The stored entries.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function entries(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * The requesting IP, as far as it can be trusted.
	 *
	 * Only REMOTE_ADDR is read. Forwarded-for headers are attacker-controlled
	 * unless a known proxy is in front, and a log that can be written to by the
	 * thing it is logging is worse than no log.
	 *
	 * @return string
	 */
	private static function request_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
			: '';

		return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
	}
}
