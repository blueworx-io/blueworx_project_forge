<?php
/**
 * The three sections every onboarding step belongs to.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Onboarding\Sections;
use PHPUnit\Framework\TestCase;

/**
 * ONB-1 (#159). Three sections, in the order somebody works through them, so a
 * client has a sense of when their turn is rather than one flat list.
 */
final class OnboardingSectionsTest extends TestCase {

	public function test_there_are_three_sections_in_working_order(): void {
		$this->assertSame(
			array( Sections::FOUNDATIONS, Sections::BUILD_REVIEWS, Sections::LAUNCH ),
			Sections::ALL
		);
	}

	public function test_a_section_outside_the_list_does_not_exist(): void {
		$this->assertTrue( Sections::exists( Sections::LAUNCH ) );
		$this->assertFalse( Sections::exists( 'whatever' ) );
		$this->assertFalse( Sections::exists( '' ) );
	}

	public function test_every_section_reads_as_words(): void {
		foreach ( Sections::ALL as $section ) {
			$this->assertNotSame( '', Sections::label( $section ) );
			$this->assertNotSame( $section, Sections::label( $section ) );
		}
	}

	public function test_something_that_is_not_a_section_has_no_label(): void {
		$this->assertSame( '', Sections::label( 'whatever' ) );
	}
}
