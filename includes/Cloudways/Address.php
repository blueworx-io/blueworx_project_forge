<?php
/**
 * A web address, reduced to what identifies a site.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Cloudways;

/**
 * #621. How a Cloudways application is matched to a Forge client site: by
 * host, lowercased, with the scheme, a leading `www.`, the port and the path
 * left off — `https://www.acme.test/shop` and `acme.test` are one site.
 */
final class Address {

	/**
	 * The host an address names.
	 *
	 * @param string $url An address, with or without its scheme.
	 * @return string '' when there is none.
	 */
	public static function host( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		if ( false === strpos( $url, '://' ) ) {
			$url = 'http://' . $url;
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}
}
