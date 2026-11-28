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
			'plugins'        => self::plugins(),
			'mail_capable'   => $mail['capable'],
			'mail_detail'    => $mail['detail'],
		);
	}

	/**
	 * The most plugins one report lists. The studio keeps no more than this.
	 */
	public const MAX_PLUGINS = 200;

	/**
	 * Every active plugin, as its folder name and its version (#718), so the
	 * studio can see which release a site is really running.
	 *
	 * A plugin that is one file in the plugins folder has no folder, so its
	 * file name without .php stands in. Network-active plugins count too.
	 *
	 * @return array<int, array{slug: string, version: string}>
	 */
	public static function plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$installed = get_plugins();
		$list      = array();

		foreach ( array_unique( $active ) as $file ) {
			if ( ! isset( $installed[ $file ] ) ) {
				continue;
			}

			$folder = dirname( $file );
			$slug   = '.' === $folder ? basename( $file, '.php' ) : $folder;

			$list[] = array(
				'slug'    => $slug,
				'version' => (string) $installed[ $file ]['Version'],
			);

			if ( count( $list ) >= self::MAX_PLUGINS ) {
				break;
			}
		}

		return $list;
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
