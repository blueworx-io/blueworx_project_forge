<?php
/**
 * The morning message.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Slack;

use Blueworx\Forge\Work\Items;
use DateTimeImmutable;

/**
 * The one scheduled job in the studio plugin, and the reason it is allowed
 * to be one: it sends a message and decides no state. A run that does not
 * happen is a morning without a ping, never a board that is wrong. Every
 * other feature here works out what is true when somebody looks; this one
 * exists to speak before anybody has.
 *
 * Once a day, at the time on the Connections screen, in the site's
 * timezone. Each person's message is claimed by the date, so a job that
 * fires twice — WP-Cron can — sends once.
 */
final class Morning {

	/**
	 * The cron hook.
	 */
	public const HOOK = 'bwx_forge_slack_morning';

	/**
	 * The option holding the time, as HH:MM.
	 */
	public const OPTION = 'bwx_forge_slack_morning';

	/**
	 * The default time.
	 */
	public const DEFAULT_TIME = '08:00';

	/**
	 * Hooks the job up.
	 */
	public static function boot(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );

		// Scheduled lazily rather than on activation alone, so a site that
		// updated into this feature gets its job without being reactivated.
		if ( is_admin() && false === wp_next_scheduled( self::HOOK ) ) {
			self::schedule( self::time() );
		}
	}

	/**
	 * The time the message goes, HH:MM.
	 *
	 * @return string
	 */
	public static function time(): string {
		$time = (string) get_option( self::OPTION, self::DEFAULT_TIME );

		return 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : self::DEFAULT_TIME;
	}

	/**
	 * (Re)schedules the daily job at a time.
	 *
	 * @param string $time HH:MM in the site's timezone.
	 */
	public static function schedule( string $time ): void {
		if ( 1 !== preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			$time = self::DEFAULT_TIME;
		}

		update_option( self::OPTION, $time, false );
		self::unschedule();

		$next = new DateTimeImmutable( 'today ' . $time, wp_timezone() );

		if ( $next->getTimestamp() <= time() ) {
			$next = $next->modify( '+1 day' );
		}

		wp_schedule_event( $next->getTimestamp(), 'daily', self::HOOK );
	}

	/**
	 * Clears the job.
	 */
	public static function unschedule(): void {
		$at = wp_next_scheduled( self::HOOK );

		while ( false !== $at ) {
			wp_unschedule_event( $at, self::HOOK );
			$at = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * Sends everyone their morning message. Returns how many were claimed.
	 *
	 * @return int
	 */
	public static function run(): int {
		$today = wp_date( 'Y-m-d' );
		$words = wp_date( 'l j F' );
		$sent  = 0;

		foreach ( People::connected() as $person ) {
			if ( empty( $person['prefs']['morning'] ) ) {
				continue;
			}

			$user_id = (string) $person['user_id'];
			$due     = array();
			$late    = array();

			foreach ( Items::held_by( $user_id ) as $item ) {
				$when = (string) $item['planned_due'];

				if ( '' === $when || $when > $today ) {
					continue;
				}

				$line = array(
					'title'       => (string) $item['title'],
					'client'      => Notify::client_name( $item ),
					'link'        => Notify::link( $item ),
					'planned_due' => $when,
				);

				if ( $when === $today ) {
					$due[] = $line;
				} else {
					$late[] = $line;
				}
			}

			$before = Events::id_for( Events::MORNING, 'day', $user_id, $today );

			Notify::send( Events::MORNING, 'day', $user_id, $today, 'morning', Message::morning( $due, $late, $words ) );

			if ( '' !== $before ) {
				++$sent;
			}
		}

		return $sent;
	}
}
