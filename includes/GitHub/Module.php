<?php
/**
 * Hooks GitHub sync into WordPress.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\GitHub;

use Throwable;

/**
 * #680. Where the GitHub parts hook in: a daily job that forgets delivery ids
 * after 30 days, since GitHub does not resend that late; and (#681) the
 * outbox, run once a request that asked for a job has answered, and every
 * five minutes for whatever is still waiting.
 */
final class Module {

	/**
	 * The cron hook.
	 */
	public const PRUNE_HOOK = 'bwx_forge_github_prune';

	/**
	 * The cron hook that runs the outbox.
	 */
	public const JOBS_HOOK = 'bwx_forge_github_jobs';

	/**
	 * The outbox's interval.
	 */
	public const FIVE_MINUTES = 'bwx_forge_five_minutes';

	/**
	 * How long a delivery id is kept.
	 */
	public const KEEP = 30 * DAY_IN_SECONDS;

	/**
	 * How many jobs a request runs after it has answered. The rest wait for cron.
	 */
	public const AFTER_REQUEST = 5;

	/**
	 * Hooks the module up.
	 */
	public static function register(): void {
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- A change waiting on GitHub is retried every five minutes, as the design asks; each run is a few rows and returns at once while GitHub is not connected.
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) );
		add_action( self::PRUNE_HOOK, array( self::class, 'prune' ) );
		add_action( self::JOBS_HOOK, array( self::class, 'run_jobs' ) );
		add_action( 'shutdown', array( self::class, 'after_request' ) );

		// Scheduled lazily, like the morning message, so a site that updated
		// into this feature gets its job without being reactivated.
		if ( is_admin() && false === wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
		}

		if ( is_admin() && false === wp_next_scheduled( self::JOBS_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::FIVE_MINUTES, self::JOBS_HOOK );
		}
	}

	/**
	 * Adds the five-minute interval, unless something already has.
	 *
	 * @param mixed $schedules The intervals WordPress knows.
	 * @return mixed
	 */
	public static function schedules( $schedules ) {
		if ( is_array( $schedules ) && ! isset( $schedules[ self::FIVE_MINUTES ] ) ) {
			$schedules[ self::FIVE_MINUTES ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every five minutes', 'blueworx-forge' ),
			);
		}

		return $schedules;
	}

	/**
	 * Forgets old deliveries.
	 */
	public static function prune(): void {
		Deliveries::prune( bwx_forge_now() - self::KEEP );
	}

	/**
	 * Runs what is waiting to go to GitHub.
	 */
	public static function run_jobs(): void {
		Jobs::run();
	}

	/**
	 * Runs the jobs this request asked for, once it has answered.
	 *
	 * Nothing here may reach the person who saved: whatever it prints is
	 * thrown away, so a warning cannot corrupt the answer already sent, and
	 * anything that goes wrong is left for the next run.
	 */
	public static function after_request(): void {
		if ( ! Jobs::queued() ) {
			return;
		}

		// Where PHP can, hand the answer over now, so a slow GitHub never
		// holds the person's save open.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}

		ob_start();

		try {
			Jobs::run( self::AFTER_REQUEST );
		} catch ( Throwable $thrown ) {
			unset( $thrown );
		} finally {
			ob_end_clean();
		}
	}

	/**
	 * Clears the jobs.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::PRUNE_HOOK );
		wp_clear_scheduled_hook( self::JOBS_HOOK );
	}
}
