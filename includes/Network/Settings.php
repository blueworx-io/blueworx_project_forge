<?php
/**
 * The WordPress multisite network Forge creates client sites on.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Network;

use Blueworx\Forge\Tenancy\Secrets;

/**
 * #621. Entered once by an administrator in Connections: the network's
 * address and the key its Forge network screen made. The key is sealed at
 * rest and never handed back beyond its last four characters.
 *
 * The address must be https — a signed call to it carries the new site's own
 * key — apart from this machine, which is how the test suite reaches its
 * second WordPress.
 */
final class Settings {

	/**
	 * Where it is kept.
	 */
	public const OPTION = 'bwx_forge_network';

	/**
	 * Whether an address is one Forge will send a key to.
	 *
	 * @param string $url The address.
	 * @return bool
	 */
	public static function valid_url( string $url ): bool {
		$parts = wp_parse_url( trim( $url ) );

		if ( ! is_array( $parts ) || '' === (string) ( $parts['host'] ?? '' ) ) {
			return false;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) $parts['host'] );

		return 'https' === $scheme || ( 'http' === $scheme && in_array( $host, array( '127.0.0.1', 'localhost' ), true ) );
	}

	/**
	 * Saves the network.
	 *
	 * @param string               $url   Its address.
	 * @param string               $key   The key.
	 * @param array<string, mixed> $about What the network said about itself: name, domain, path, subdomains.
	 */
	public static function save( string $url, string $key, array $about ): void {
		update_option(
			self::OPTION,
			array(
				'url'          => untrailingslashit( esc_url_raw( trim( $url ) ) ),
				'key'          => Secrets::seal( trim( $key ) ),
				'hint'         => substr( trim( $key ), -4 ),
				'name'         => sanitize_text_field( (string) ( $about['name'] ?? '' ) ),
				'domain'       => sanitize_text_field( (string) ( $about['domain'] ?? '' ) ),
				'path'         => sanitize_text_field( (string) ( $about['path'] ?? '/' ) ),
				'subdomains'   => ! empty( $about['subdomains'] ),
				'connected_at' => bwx_forge_now(),
				'last_ok_at'   => bwx_forge_now(),
			),
			false
		);
	}

	/**
	 * The address, or ''.
	 *
	 * @return string
	 */
	public static function url(): string {
		return (string) ( self::stored()['url'] ?? '' );
	}

	/**
	 * The key, opened. For signing only.
	 *
	 * @return string|null
	 */
	public static function key(): ?string {
		$sealed = (string) ( self::stored()['key'] ?? '' );

		return '' === $sealed ? null : Secrets::open( $sealed );
	}

	/**
	 * What an administrator is shown of the key.
	 *
	 * @return string
	 */
	public static function key_hint(): string {
		$hint = (string) ( self::stored()['hint'] ?? '' );

		return '' === $hint ? '' : '••••' . $hint;
	}

	/**
	 * Whether sites can be created.
	 *
	 * @return bool
	 */
	public static function connected(): bool {
		return '' !== self::url() && null !== self::key();
	}

	/**
	 * What the network said about itself when connected.
	 *
	 * @return array{name: string, domain: string, path: string, subdomains: bool, last_ok_at: int}
	 */
	public static function about(): array {
		$stored = self::stored();

		return array(
			'name'       => (string) ( $stored['name'] ?? '' ),
			'domain'     => (string) ( $stored['domain'] ?? '' ),
			'path'       => (string) ( $stored['path'] ?? '/' ),
			'subdomains' => ! empty( $stored['subdomains'] ),
			'last_ok_at' => (int) ( $stored['last_ok_at'] ?? 0 ),
		);
	}

	/**
	 * Notes that the network just answered a signed call.
	 */
	public static function note_ok(): void {
		$stored = self::stored();

		if ( array() !== $stored ) {
			$stored['last_ok_at'] = bwx_forge_now();
			update_option( self::OPTION, $stored, false );
		}
	}

	/**
	 * Disconnects.
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
	}

	/**
	 * What is saved.
	 *
	 * @return array<string, mixed>
	 */
	private static function stored(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}
}
