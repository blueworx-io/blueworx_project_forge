<?php
/**
 * Hooks GitHub sync into WordPress.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\GitHub;

/**
 * #680. Where the GitHub parts hook in. For now that is one daily job, which
 * forgets delivery ids after 30 days: GitHub does not resend that late.
 */
final class Module {

	/**
	 * The cron hook.
	 */
	public const PRUNE_HOOK = 'bwx_forge_github_prune';

	/**
	 * How long a delivery id is kept.
	 */
	public const KEEP = 30 * DAY_IN_SECONDS;

	/**
	 * Hooks the module up.
	 */
	public static function register(): void {
		add_action( self::PRUNE_HOOK, array( self::class, 'prune' ) );

		// Scheduled lazily, like the morning message, so a site that updated
		// into this feature gets its job without being reactivated.
		if ( is_admin() && false === wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
		}
	}

	/**
	 * Forgets old deliveries.
	 */
	public static function prune(): void {
		Deliveries::prune( bwx_forge_now() - self::KEEP );
	}

	/**
	 * Clears the job.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::PRUNE_HOOK );
	}
}
