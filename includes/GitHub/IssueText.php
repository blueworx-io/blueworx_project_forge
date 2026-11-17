<?php
/**
 * An issue's words, in the shape an item keeps them.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\GitHub;

use Blueworx\Forge\Work\Validate;

/**
 * #680. GitHub writes Markdown; an item's description is a little HTML. Only
 * paragraphs, line breaks and bulleted lists are carried across. Everything
 * else arrives as the words that were typed, escaped, so nothing in an issue
 * can become markup in Forge.
 */
final class IssueText {

	/**
	 * An issue body as a description.
	 *
	 * @param string $markdown The body as GitHub sent it.
	 * @return string HTML using only p, br, ul and li.
	 */
	public static function to_html( string $markdown ): string {
		$text = trim( str_replace( array( "\r\n", "\r" ), "\n", $markdown ) );

		if ( '' === $text ) {
			return '';
		}

		$html = '';

		foreach ( preg_split( '/\n[ \t]*\n/', $text ) as $block ) {
			$lines = array();
			$items = array();

			foreach ( explode( "\n", trim( $block ) ) as $line ) {
				$line = trim( $line );

				if ( 1 === preg_match( '/^[-*] (.*)$/', $line, $bullet ) ) {
					$html   .= self::paragraph( $lines );
					$lines   = array();
					$items[] = '<li>' . esc_html( trim( $bullet[1] ) ) . '</li>';
					continue;
				}

				$html   .= self::listed( $items );
				$items   = array();
				$lines[] = esc_html( $line );
			}

			$html .= self::paragraph( $lines ) . self::listed( $items );
		}

		return $html;
	}

	/**
	 * An issue title as an item title: trimmed, and short enough to keep.
	 *
	 * @param string $title The title as GitHub sent it.
	 * @return string
	 */
	public static function title( string $title ): string {
		return trim( mb_substr( trim( $title ), 0, Validate::MAX_TITLE ) );
	}

	/**
	 * Lines of text as one paragraph.
	 *
	 * @param array<int, string> $lines Escaped lines.
	 * @return string
	 */
	private static function paragraph( array $lines ): string {
		return array() === $lines ? '' : '<p>' . implode( '<br>', $lines ) . '</p>';
	}

	/**
	 * List entries as one list.
	 *
	 * @param array<int, string> $items Each entry, already an li.
	 * @return string
	 */
	private static function listed( array $items ): string {
		return array() === $items ? '' : '<ul>' . implode( '', $items ) . '</ul>';
	}
}
