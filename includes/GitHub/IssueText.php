<?php
/**
 * An issue's words, in the shape an item keeps them.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\GitHub;

use Blueworx\Forge\Work\Items;
use Blueworx\Forge\Work\Validate;

/**
 * #680. GitHub writes Markdown; an item's description is a little HTML. Only
 * paragraphs, line breaks and bulleted lists are carried across. Everything
 * else arrives as the words that were typed, escaped, so nothing in an issue
 * can become markup in Forge.
 */
final class IssueText {

	/**
	 * The most a description may hold, in bytes. The column takes 65,535; this
	 * leaves room, so a huge issue is cut rather than failing every time.
	 */
	public const MAX_HTML = 60000;

	/**
	 * What ends a description that had to be cut.
	 */
	public const CUT_NOTE = 'This issue was too long to bring across in full. The rest is on GitHub.';

	/**
	 * An issue body as a description.
	 *
	 * @param string $markdown The body as GitHub sent it.
	 * @return string HTML using only p, br, ul and li.
	 */
	public static function to_html( string $markdown ): string {
		$text = trim( str_replace( array( "\r\n", "\r" ), "\n", $markdown ) );
		$html = self::convert( $text );

		if ( strlen( $html ) <= self::MAX_HTML ) {
			return $html;
		}

		$note  = '<p>' . esc_html( self::CUT_NOTE ) . '</p>';
		$room  = self::MAX_HTML - strlen( $note );
		$chars = mb_strlen( $text );

		// Escaping makes the HTML longer than the words, by an amount that
		// depends on them: cut the words in proportion until it fits.
		do {
			$chars = (int) floor( $chars * $room / strlen( $html ) * 0.95 );
			$html  = self::convert( trim( mb_substr( $text, 0, $chars ) ) );
			$size  = strlen( $html );
		} while ( $size > $room && $chars > 0 );

		return $html . $note;
	}

	/**
	 * Markdown as HTML, whatever its length.
	 *
	 * @param string $text Trimmed, with plain newlines.
	 * @return string
	 */
	private static function convert( string $text ): string {
		if ( '' === $text ) {
			return '';
		}

		$html = '';

		foreach ( preg_split( '/\n[ \t]*\n/', $text ) as $block ) {
			// A run of blank lines splits into empty blocks: they are not paragraphs.
			if ( '' === trim( $block ) ) {
				continue;
			}

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
	 * The body of the issue Forge makes for an item (#681): the item's words
	 * under headings, only those it has, then the way back to Forge and the
	 * marker that says which item the issue is.
	 *
	 * @param array<string, mixed> $item      The item.
	 * @param string               $forge_url Where the item is in Forge.
	 * @return string Markdown.
	 */
	public static function issue_body( array $item, string $forge_url ): string {
		$sections = array(
			'problem'             => 'Description',
			'scope'               => 'Scope',
			'requirements'        => 'Requirements',
			'acceptance_criteria' => 'Acceptance criteria',
		);

		$parts = array();

		foreach ( $sections as $field => $heading ) {
			$text = self::to_markdown( (string) ( $item[ $field ] ?? '' ) );

			if ( '' !== $text ) {
				$parts[] = '## ' . $heading . "\n\n" . $text;
			}
		}

		// GitHub takes 65,536 characters; the link and marker must always fit.
		$words = mb_substr( implode( "\n\n", $parts ), 0, self::MAX_HTML );

		return ltrim( $words . "\n\nTracked in Forge: " . $forge_url . "\n\n<!-- forge:" . (string) ( $item['id'] ?? '' ) . ' -->' );
	}

	/**
	 * An item's HTML as Markdown. Paragraphs, line breaks, list entries, bold
	 * and links are kept; every other tag goes and leaves its words. Text with
	 * no tags at all is a plain field, and keeps its own line breaks.
	 *
	 * @param string $html What the item holds.
	 * @return string
	 */
	public static function to_markdown( string $html ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $html );

		if ( 1 === preg_match( '#</?[a-z][^>]*>#i', $text ) ) {
			$text = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $text );
			$text = (string) preg_replace( '/\s+/', ' ', $text );
			$text = (string) preg_replace_callback(
				'#<a\s[^>]*?href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a\s*>#is',
				static function ( array $link ): string {
					$href  = trim( html_entity_decode( $link[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
					$words = trim( $link[3] );

					return '' === $href ? $words : '[' . $words . '](' . str_replace( array( '(', ')', ' ' ), array( '%28', '%29', '%20' ), $href ) . ')';
				},
				$text
			);
			$text = (string) preg_replace( '#</?(strong|b)(\s[^>]*)?>#i', '**', $text );
			$text = (string) preg_replace( '#<br\s*/?>\s*#i', "\n", $text );
			$text = (string) preg_replace( '#<li(\s[^>]*)?>\s*#i', "\n- ", $text );
			$text = (string) preg_replace( '#</?(p|ul|ol)(\s[^>]*)?>#i', "\n\n", $text );
			$text = wp_strip_all_tags( $text );
		}

		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Only Forge writes the marker: words that look like one are shown, not hidden.
		$text  = str_replace( '<!--', '&lt;!--', $text );
		$lines = array_map( 'trim', explode( "\n", $text ) );

		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $lines ) ) );
	}

	/**
	 * The item an issue was made for, from the marker Forge ends its body with.
	 *
	 * @param string $body The issue body.
	 * @return string The item id, or '' when there is no marker.
	 */
	public static function marker_in( string $body ): string {
		if ( 0 === preg_match_all( '/<!-- forge:(' . preg_quote( Items::PREFIX, '/' ) . '_[A-Za-z0-9]+) -->/', $body, $found ) ) {
			return '';
		}

		// The last one is Forge's: the body ends with it.
		return (string) end( $found[1] );
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
