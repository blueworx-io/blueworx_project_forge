<?php
/**
 * What this site tells the studio about itself.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * The studio cannot see a client site from outside. It can see requests
 * arriving, which tells it the connection works, and nothing at all about what
 * that site is running or whether it could email anybody.
 *
 * So the site says. On activation, whenever somebody uses the connection
 * screen, and once a day on cron — three occasions chosen so the record is
 * fresh when it matters and cheap the rest of the time.
 *
 * Nothing here is canonical (ARCH-2): this is the site describing itself to the
 * record the studio owns.
 */
final class Report {

	/**
	 * The daily cron hook.
	 */
	public const CRON_HOOK = 'bwx_forge_client_daily_report';

	/**
	 * Studio route the report is posted to.
	 */
	public const ROUTE = '/client/report';

	/**
	 * Hooks the report up.
	 */
	public static function boot(): void {
		add_action( self::CRON_HOOK, array( self::class, 'send' ) );
		add_action( 'bwx_forge_client_connected', array( self::class, 'send' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Stops reporting. Called on deactivation, so a disabled plugin does not
	 * leave a cron event behind that fires into nothing.
	 */
	public static function unschedule(): void {
		$next = wp_next_scheduled( self::CRON_HOOK );

		if ( false !== $next ) {
			wp_unschedule_event( (int) $next, self::CRON_HOOK );
		}
	}

	/**
	 * What this site is.
	 *
	 * @return array<string, mixed>
	 */
	public static function payload(): array {
		$mail = Mail::capability();

		return array(
			'home_url'       => home_url(),
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'plugin_version' => defined( 'BWX_FORGE_CLIENT_VERSION' ) ? BWX_FORGE_CLIENT_VERSION : '',
			'mail_capable'   => $mail['capable'],
			'mail_detail'    => $mail['detail'],
		);
	}

	/**
	 * Sends it.
	 *
	 * A site that is not connected yet reports nothing rather than failing: on
	 * activation there is usually no key, and an error in the log for the
	 * ordinary case would train everybody to ignore the log.
	 *
	 * @return array<string, mixed>|\WP_Error|null Null when there was nothing to
	 *                                             send it with.
	 */
	public static function send() {
		if ( ! Connection::is_configured() ) {
			return null;
		}

		return Connection::post( self::ROUTE, self::payload() );
	}
}
