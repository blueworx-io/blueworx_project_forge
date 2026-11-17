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

	public function test_runs_of_blank_lines_make_no_empty_paragraphs(): void {
		self::assertSame( '<p>One</p><p>Two</p>', IssueText::to_html( "One\n\n\n\nTwo" ) );
		self::assertSame( '<p>One</p><p>Two</p>', IssueText::to_html( "One\n\n \n\n\n\t\n\nTwo" ) );
	}

	public function test_a_huge_body_is_cut_to_fit_with_a_note(): void {
		$body = implode( "\n\n", array_fill( 0, 4000, str_repeat( 'Words & <more> words. ', 4 ) ) );
		$html = IssueText::to_html( $body );

		self::assertLessThan( 60000, strlen( $html ) );
		self::assertGreaterThan( 50000, strlen( $html ) );
		self::assertStringEndsWith( '<p>' . IssueText::CUT_NOTE . '</p>', $html );
		self::assertSame( $html, wp_kses( $html, Fields::ALLOWED_HTML ) );
	}

	public function test_one_huge_paragraph_is_cut_too(): void {
		$html = IssueText::to_html( str_repeat( '<é> ', 40000 ) );

		self::assertLessThan( 60000, strlen( $html ) );
		self::assertTrue( mb_check_encoding( $html, 'UTF-8' ) );
		self::assertStringStartsWith( '<p>&lt;é&gt;', $html );
		self::assertStringEndsWith( '<p>' . IssueText::CUT_NOTE . '</p>', $html );
	}

	public function test_long_titles_are_cut_to_fit(): void {
		$title = IssueText::title( str_repeat( 'é', 300 ) );

		self::assertSame( 191, mb_strlen( $title ) );
		self::assertTrue( mb_check_encoding( $title, 'UTF-8' ) );
		self::assertSame( 'Fix it', IssueText::title( "  Fix it \n" ) );
	}
}
