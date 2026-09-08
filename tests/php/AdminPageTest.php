<?php
/**
 * The shell's markup, which eleven screens depend on being the same.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Admin\Page;
use PHPUnit\Framework\TestCase;

/**
 * What Page writes, and what it refuses to write.
 */
final class AdminPageTest extends TestCase {

	/**
	 * Captures what a Page call echoes.
	 *
	 * @param callable $fn The call to capture.
	 */
	private function render( callable $fn ): string {
		ob_start();
		$fn();
		return (string) ob_get_clean();
	}

	public function test_open_writes_the_shell_in_the_order_the_system_fixes(): void {
		$html = $this->render(
			static function () {
				Page::open( 'Client sites', 'Studio', 'Every site we look after.' );
			}
		);

		$this->assertStringContainsString( '<div class="wrap bw-wrap">', $html );
		$this->assertStringContainsString( '<div class="bw-admin bw-page">', $html );
		$this->assertStringContainsString( 'bw-pagehead__eyebrow', $html );
		$this->assertStringContainsString( '<h1 class="bw-pagehead__h1">Client sites</h1>', $html );
		$this->assertStringContainsString( 'bw-pagehead__lede', $html );
		$this->assertStringContainsString( '<div class="bw-panels">', $html );

		// The header closes before the panel column opens.
		$this->assertLessThan(
			strpos( $html, 'bw-panels' ),
			strpos( $html, '</header>' ),
			'the panel column must open after the header closes'
		);
	}

	public function test_open_omits_the_eyebrow_and_lede_when_it_has_none(): void {
		$html = $this->render(
			static function () {
				Page::open( 'Updates' );
			}
		);

		$this->assertStringNotContainsString( 'bw-pagehead__eyebrow', $html );
		$this->assertStringNotContainsString( 'bw-pagehead__lede', $html );
	}

	public function test_a_panel_carries_its_name_for_tests_to_hold(): void {
		$html = $this->render(
			static function () {
				Page::panel_open( 'Connected sites', 'sites' );
			}
		);

		$this->assertStringContainsString( '<section class="bw-card"', $html );
		$this->assertStringContainsString( 'data-bwx-panel="sites"', $html );
		$this->assertStringContainsString( '<h2 class="bw-card__title">Connected sites</h2>', $html );
		$this->assertStringContainsString( '<div class="bw-card__body">', $html );
	}

	/**
	 * Whole class names, never a stem with the tone appended: the admin UI
	 * check reads the classes a screen writes, and one assembled from a
	 * variable is one it cannot see.
	 */
	public function test_each_tone_writes_its_whole_class_name(): void {
		foreach ( array( 'success', 'warning', 'danger', 'info' ) as $tone ) {
			$html = $this->render(
				static function () use ( $tone ) {
					Page::notice( $tone, 'Saved.' );
				}
			);

			$this->assertStringContainsString( 'class="bw-notice bw-notice--' . $tone . '"', $html );
		}
	}

	public function test_a_danger_notice_is_an_alert_and_the_rest_are_status(): void {
		$danger = $this->render(
			static function () {
				Page::notice( 'danger', 'That did not work.' );
			}
		);
		$info = $this->render(
			static function () {
				Page::notice( 'info', 'For information.' );
			}
		);

		$this->assertStringContainsString( 'role="alert"', $danger );
		$this->assertStringContainsString( 'role="status"', $info );
	}

	public function test_an_unknown_tone_falls_back_to_info_rather_than_writing_a_broken_class(): void {
		$html = $this->render(
			static function () {
				Page::notice( 'purple', 'Hello.' );
			}
		);

		$this->assertStringContainsString( 'class="bw-notice bw-notice--info"', $html );
	}

	public function test_notice_text_is_escaped_unless_the_caller_says_it_is_markup(): void {
		$escaped = $this->render(
			static function () {
				Page::notice( 'info', '<script>alert(1)</script>' );
			}
		);

		$this->assertStringNotContainsString( '<script>', $escaped );
	}

	public function test_a_notice_carries_the_attributes_it_was_given(): void {
		$html = $this->render(
			static function () {
				Page::notice( 'success', 'Connected.', array( 'data-bwx-result' => 'saved' ) );
			}
		);

		$this->assertStringContainsString( 'data-bwx-result="saved"', $html );
	}

	public function test_ours_matches_studio_screens_only(): void {
		$this->assertTrue( Page::ours( 'toplevel_page_blueworx-forge-sites' ) );
		$this->assertTrue( Page::ours( 'forge_page_blueworx-forge-clients' ) );
		$this->assertFalse( Page::ours( 'edit.php' ) );
		$this->assertFalse( Page::ours( 'toplevel_page_some-other-plugin' ) );
	}
}
