<?php
/**
 * The plugin's changelog, read for the admin screen.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Admin;

/**
 * Reads CHANGELOG.md into releases (#466). It understands only the shape the
 * file is written in: `## [x.y.z] - date`, `### Heading`, `- entry` with
 * indented continuation lines, and the odd plain paragraph. Pure apart from
 * read().
 */
final class Changelog {

	/**
	 * The releases in a changelog, in file order (newest first).
	 *
	 * @param string $markdown The file's contents.
	 * @return list<array{version: string, date: string, sections: list<array{heading: string, entries: list<string>}>}>
	 */
	public static function parse( string $markdown ): array {
		$releases = array();
		$release  = -1;
		$section  = -1;
		$open     = false;

		foreach ( preg_split( '/\r\n|\n/', $markdown ) as $line ) {
			if ( 1 === preg_match( '/^## \[([^\]]+)\](?:\s*-\s*(\S+))?/', $line, $found ) ) {
				$releases[] = array(
					'version'  => $found[1],
					'date'     => $found[2] ?? '',
					'sections' => array(),
				);
				$release    = count( $releases ) - 1;
				$section    = -1;
				$open       = false;
				continue;
			}

			// Anything before the first release is the file's own intro.
			if ( $release < 0 ) {
				continue;
			}

			if ( 1 === preg_match( '/^### (.+)$/', $line, $found ) ) {
				$releases[ $release ]['sections'][] = array(
					'heading' => trim( $found[1] ),
					'entries' => array(),
				);
				$section                            = count( $releases[ $release ]['sections'] ) - 1;
				$open                               = false;
				continue;
			}

			if ( '' === trim( $line ) ) {
				$open = false;
				continue;
			}

			if ( $section < 0 ) {
				continue;
			}

			$entries = &$releases[ $release ]['sections'][ $section ]['entries'];

			if ( 1 === preg_match( '/^- (.*)$/', $line, $found ) ) {
				$entries[] = trim( $found[1] );
				$open      = true;
			} elseif ( $open ) {
				$entries[ count( $entries ) - 1 ] .= ' ' . trim( $line );
			} else {
				$entries[] = trim( $line );
				$open      = true;
			}

			unset( $entries );
		}

		return $releases;
	}

	/**
	 * One entry as HTML: escaped, with bold and code kept and links reduced to
	 * their text.
	 *
	 * @param string $text The entry.
	 * @return string
	 */
	public static function inline( string $text ): string {
		$html = esc_html( preg_replace( '/\[([^\]]+)\]\([^)]*\)/', '$1', $text ) ?? $text );
		$html = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $html ) ?? $html;

		return preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html ) ?? $html;
	}

	/**
	 * The releases in a changelog file, or null when it cannot be read.
	 *
	 * @param string $path The file.
	 * @return list<array{version: string, date: string, sections: list<array{heading: string, entries: list<string>}>}>|null
	 */
	public static function read( string $path ): ?array {
		if ( ! is_readable( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file shipped with the plugin, not a remote URL.
		$markdown = file_get_contents( $path );

		return false === $markdown ? null : self::parse( $markdown );
	}
}
