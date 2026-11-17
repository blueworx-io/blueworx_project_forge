<?php
/**
 * An issue's words, as an item's.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\GitHub\IssueText;
use Blueworx\Forge\Work\Fields;
use PHPUnit\Framework\TestCase;

/**
 * #680. GitHub writes Markdown and an item holds a little HTML. Only the
 * shapes people actually use in an issue are carried across; everything else
 * comes through as the words they typed.
 */
final class GitHubIssueTextTest extends TestCase {

	public function test_paragraphs_and_lists_become_html(): void {
		self::assertSame(
			'<p>One</p><p>Two</p><ul><li>a</li><li>b</li></ul>',
			IssueText::to_html( "One\n\nTwo\n- a\n- b" )
		);
	}

	public function test_single_line_breaks_and_star_lists_are_kept(): void {
		$html = IssueText::to_html( "First\r\nsecond\n\n* x\n* y\nafter" );

		self::assertSame( '<p>First<br>second</p><ul><li>x</li><li>y</li></ul><p>after</p>', $html );
		self::assertSame( $html, wp_kses( $html, Fields::ALLOWED_HTML ) );
	}

	public function test_markup_in_an_issue_is_escaped(): void {
		self::assertSame( '<p>&lt;script&gt;x&lt;/script&gt;</p>', IssueText::to_html( '<script>x</script>' ) );
	}

	public function test_an_empty_body_is_an_empty_description(): void {
		self::assertSame( '', IssueText::to_html( '' ) );
		self::assertSame( '', IssueText::to_html( "  \n\n " ) );
	}

	public function test_long_titles_are_cut_to_fit(): void {
		$title = IssueText::title( str_repeat( 'é', 300 ) );

		self::assertSame( 191, mb_strlen( $title ) );
		self::assertTrue( mb_check_encoding( $title, 'UTF-8' ) );
		self::assertSame( 'Fix it', IssueText::title( "  Fix it \n" ) );
	}
}
