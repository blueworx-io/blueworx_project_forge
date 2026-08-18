<?php
/**
 * The client site's connection to the studio.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

use WP_Error;

/**
 * Where the site's credentials live, and how it calls the studio with them.
 *
 * Credentials come from wp-config.php constants first and fall back to options.
 * The constants are the right home for a real site — a secret in wp-config is
 * not in the database, so it does not travel in a database export or show up in
 * a plugin that dumps options — and the options exist because the site has to
 * be configurable by whoever installs it without editing wp-config by hand.
 *
 * ARCH-6's "no self-service enrolment" is about the studio: it refuses any site
 * it did not register. Storing credentials here is a client site being told who
 * it is, not a site enrolling itself.
 */
final class Connection {

	/**
	 * Option holding the studio URL.
	 */
	public const OPTION_STUDIO_URL = 'bwx_forge_client_studio_url';

	/**
	 * Option holding the site id.
	 */
	public const OPTION_SITE_ID = 'bwx_forge_client_site_id';

	/**
	 * Option holding the signing key.
	 */
	public const OPTION_KEY = 'bwx_forge_client_key';

	/**
	 * The studio's REST namespace, as seen from here.
	 */
	public const STUDIO_NAMESPACE = '/blueworx-forge/v1';

	/**
	 * The studio's base URL, without a trailing slash.
	 *
	 * @return string
	 */
	public static function studio_url(): string {
		$url = defined( 'BWX_FORGE_STUDIO_URL' ) && BWX_FORGE_STUDIO_URL
			? (string) BWX_FORGE_STUDIO_URL
			: (string) get_option( self::OPTION_STUDIO_URL, '' );

		return rtrim( $url, '/' );
	}

	/**
	 * This site's id, as issued by the studio.
	 *
	 * @return string
	 */
	public static function site_id(): string {
		return defined( 'BWX_FORGE_CLIENT_SITE_ID' ) && BWX_FORGE_CLIENT_SITE_ID
			? (string) BWX_FORGE_CLIENT_SITE_ID
			: (string) get_option( self::OPTION_SITE_ID, '' );
	}

	/**
	 * This site's signing key.
	 *
	 * @return string
	 */
	public static function key(): string {
		return defined( 'BWX_FORGE_CLIENT_KEY' ) && BWX_FORGE_CLIENT_KEY
			? (string) BWX_FORGE_CLIENT_KEY
			: (string) get_option( self::OPTION_KEY, '' );
	}

	/**
	 * Whether the site has everything it needs to call the studio.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return '' !== self::studio_url() && '' !== self::site_id() && '' !== self::key();
	}

	/**
	 * Stores credentials. Used by the client-side settings route.
	 *
	 * @param string $studio_url Studio base URL.
	 * @param string $site_id    Site id issued by the studio.
	 * @param string $key        Signing key issued by the studio.
	 */
	public static function store( string $studio_url, string $site_id, string $key ): void {
		update_option( self::OPTION_STUDIO_URL, esc_url_raw( rtrim( $studio_url, '/' ) ) );
		update_option( self::OPTION_SITE_ID, sanitize_text_field( $site_id ) );
		update_option( self::OPTION_KEY, sanitize_text_field( $key ) );

		// Whatever is cached was read as whoever this site was before. After a
		// re-connection it may be a different site, and serving the old copy would
		// show one client another client's record.
		Cache::flush();
	}

	/**
	 * Forgets the credentials this site was given.
	 */
	public static function forget(): void {
		delete_option( self::OPTION_STUDIO_URL );
		delete_option( self::OPTION_SITE_ID );
		delete_option( self::OPTION_KEY );
		Cache::flush();
	}

	/**
	 * Makes a signed GET to the studio.
	 *
	 * The path signed is the studio's REST route, which is what the studio
	 * verifies against — not the full URL. Signing the URL would break the
	 * moment a site sat behind a proxy that rewrote it.
	 *
	 * @param string $route Route within the studio namespace, e.g. /client/handshake.
	 * @return array<string, mixed>|WP_Error The decoded response body.
	 */
	public static function get( string $route ) {
		if ( ! self::is_configured() ) {
			return new WP_Error(
				'bwx_forge_client_not_configured',
				__( 'This site has not been connected to the studio yet.', 'blueworx-forge' ),
				array( 'status' => 409 )
			);
		}

		$path = self::STUDIO_NAMESPACE . $route;

		$response = wp_remote_get(
			self::studio_url() . '/wp-json' . $path,
			array(
				'timeout' => 15,
				'headers' => Signer::headers( self::key(), self::site_id(), 'GET', $path ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'bwx_forge_client_refused',
				__( 'The studio refused this site.', 'blueworx-forge' ),
				array(
					'status'        => $status,
					'studio_answer' => $body,
				)
			);
		}

		return is_array( $body ) ? $body : array();
	}
}
