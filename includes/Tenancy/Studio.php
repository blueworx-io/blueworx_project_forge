<?php
/**
 * The studio's own client.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Tenancy;

/**
 * Every piece of work belongs to a client site, and the studio is not a
 * client — so until now there was nowhere to put the studio's own work. This
 * gives it a client and a site of its own, made once, on the first request
 * after the plugin is updated, and named after the WordPress site it runs on.
 *
 * It is an ordinary client in every other way. It can be renamed (the Clients
 * screen has a field for it), deactivated, and given people; nothing else in
 * Forge treats it specially except the site picker, which opens on it.
 *
 * One autoloaded option remembers which client and site are the studio's, so
 * the ordinary request costs nothing more than the option read WordPress has
 * already done. The client is looked up only to notice it has gone — a site
 * whose tables were emptied gets its studio back rather than a dangling id.
 */
final class Studio {

	/**
	 * The option holding `{ client_id, site_id }`.
	 */
	public const OPTION = 'bwx_forge_studio';

	/**
	 * Whether the studio's client has to be made.
	 *
	 * Pure, so the decision can be tested without a database: it is what runs
	 * on every request, and it must say "nothing to do" exactly when there is
	 * nothing to do.
	 *
	 * @param mixed      $option What the option holds — an array, or false.
	 * @param array|null $client The recorded client, or null when it is gone.
	 * @return bool
	 */
	public static function needs_creating( $option, ?array $client ): bool {
		if ( ! is_array( $option ) ) {
			return true;
		}

		if ( '' === (string) ( $option['client_id'] ?? '' ) || '' === (string) ( $option['site_id'] ?? '' ) ) {
			return true;
		}

		return null === $client;
	}

	/**
	 * Makes the studio's client and site when they are missing.
	 */
	public static function ensure(): void {
		$option = get_option( self::OPTION, false );
		$client = is_array( $option ) && '' !== (string) ( $option['client_id'] ?? '' )
			? Clients::get( (string) $option['client_id'] )
			: null;

		if ( ! self::needs_creating( $option, $client ) ) {
			return;
		}

		$name = trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) );
		$name = '' === $name ? 'Studio' : $name;

		$domain = (string) substr( strrchr( (string) get_option( 'admin_email', '' ), '@' ), 1 );

		$checked = Validate::client(
			array(
				'display_name'  => $name,
				'timezone'      => wp_timezone_string(),
				'email_domains' => $domain,
			),
			false
		);

		if ( array() !== $checked['errors'] ) {
			// A timezone WordPress reports but PHP does not know is the only way
			// in here; the client is still worth having, in UTC.
			$checked = Validate::client( array( 'display_name' => $name ), false );
		}

		$client = Clients::create( $checked['values'], 0 );

		if ( null === $client ) {
			return;
		}

		$site = ClientSites::create(
			(string) $client['id'],
			array(
				'name' => $name,
				'url'  => home_url( '/' ),
			),
			0
		);

		if ( null === $site ) {
			return;
		}

		update_option(
			self::OPTION,
			array(
				'client_id' => (string) $client['id'],
				'site_id'   => (string) $site['id'],
			),
			true
		);
	}

	/**
	 * The studio's client id, or an empty string before it exists.
	 *
	 * @return string
	 */
	public static function client_id(): string {
		$option = get_option( self::OPTION, false );

		return is_array( $option ) ? (string) ( $option['client_id'] ?? '' ) : '';
	}

	/**
	 * The studio's site id, or an empty string before it exists.
	 *
	 * @return string
	 */
	public static function site_id(): string {
		$option = get_option( self::OPTION, false );

		return is_array( $option ) ? (string) ( $option['site_id'] ?? '' ) : '';
	}

	/**
	 * Whether a site is the studio's own.
	 *
	 * @param string $site_id Site id.
	 * @return bool
	 */
	public static function is_studio_site( string $site_id ): bool {
		return '' !== $site_id && self::site_id() === $site_id;
	}
}
