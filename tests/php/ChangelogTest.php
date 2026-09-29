<?php
/**
 * The changelog, read for the admin screen (#466).
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Admin\Changelog;
use PHPUnit\Framework\TestCase;

final class ChangelogTest extends TestCase {

	private const SAMPLE = "# Changelog\n\nIntro with a [link](https://example.com).\n\n## [2.1.0] - 2026-09-29\n\n### Added\n\n- One thing.\n- Another thing that\n  carries on here.\n\n### Fixed\n\n- A fix.\n\n## [2.0.0] - 2026-09-01\n\n### Note\n\nA plain paragraph\nover two lines.\n";

	public function test_releases_come_out_newest_first_with_their_dates(): void {
		$releases = Changelog::parse( self::SAMPLE );

		$this->assertSame( array( '2.1.0', '2.0.0' ), array_column( $releases, 'version' ) );
		$this->assertSame( '2026-09-29', $releases[0]['date'] );
	}

	public function test_the_intro_is_skipped(): void {
		$this->assertStringNotContainsString( 'Intro', (string) wp_json_encode( Changelog::parse( self::SAMPLE ) ) );
	}

	public function test_sections_hold_their_entries_and_continuations_join(): void {
		$sections = Changelog::parse( self::SAMPLE )[0]['sections'];

		$this->assertSame( array( 'Added', 'Fixed' ), array_column( $sections, 'heading' ) );
		$this->assertSame( array( 'One thing.', 'Another thing that carries on here.' ), $sections[0]['entries'] );
	}

	public function test_a_plain_paragraph_is_kept_as_one_entry(): void {
		$sections = Changelog::parse( self::SAMPLE )[1]['sections'];

		$this->assertSame( array( 'A plain paragraph over two lines.' ), $sections[0]['entries'] );
	}

	public function test_inline_formatting_and_escaping(): void {
		$this->assertSame( '<strong>Bold</strong> and <code>npm run x</code>', Changelog::inline( '**Bold** and `npm run x`' ) );
		$this->assertSame( 'See the docs', Changelog::inline( 'See [the docs](https://example.com)' ) );
		$this->assertSame( '&lt;script&gt; &amp; more', Changelog::inline( '<script> & more' ) );
	}

	public function test_an_empty_file_has_no_releases(): void {
		$this->assertSame( array(), Changelog::parse( '' ) );
	}

	public function test_a_missing_file_reads_as_null(): void {
		$this->assertNull( Changelog::read( __DIR__ . '/no-such-changelog.md' ) );
	}
}
