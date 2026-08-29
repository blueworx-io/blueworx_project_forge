<?php
/**
 * The checklist a fresh install starts with.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Onboarding\Sections;
use Blueworx\Forge\Onboarding\TemplateSteps;
use Blueworx\Forge\Onboarding\Version1;
use PHPUnit\Framework\TestCase;

/**
 * ONB-1 (#159). What the seed is, and — for now — what stops it running.
 */
final class OnboardingVersion1Test extends TestCase {

	public function test_it_does_not_seed_while_the_definition_is_incomplete(): void {
		/*
		 * Seven of the twelve categories in §11.2 have not been supplied. A
		 * version published with five of them would be handed to a client as
		 * though it were the agreed checklist, so nothing is seeded until the
		 * list is filled in. When it is, this test is the one to change.
		 */
		$this->assertFalse( Version1::READY );
		$this->assertFalse( Version1::seed() );
	}

	public function test_the_five_launch_critical_categories_are_the_ones_onb_1_names(): void {
		$this->assertSame(
			array(
				'Domain and DNS',
				'Hosting',
				'Email and SMTP',
				'Legal and compliance',
				'Review and launch',
			),
			Version1::LAUNCH_CRITICAL
		);
	}

	public function test_every_step_it_does_define_is_launch_critical_and_in_a_real_section(): void {
		// The only categories known so far are the launch-critical ones, so
		// every step here must be one — anything else would be invented.
		foreach ( Version1::steps() as $step ) {
			$this->assertTrue( Sections::exists( $step['section'] ) );
			$this->assertSame( 1, $step['launch_critical'] );
			$this->assertContains( $step['category'], Version1::LAUNCH_CRITICAL );
		}
	}

	public function test_every_step_covers_a_launch_critical_category(): void {
		$covered = array_unique( array_column( Version1::steps(), 'category' ) );

		sort( $covered );
		$expected = Version1::LAUNCH_CRITICAL;
		sort( $expected );

		$this->assertSame( $expected, $covered );
	}

	public function test_the_steps_are_ordered(): void {
		$positions = array_column( Version1::steps(), 'position' );

		$this->assertSame( array_unique( $positions ), $positions, 'no two steps share a position' );

		$sorted = $positions;
		sort( $sorted );

		$this->assertSame( $sorted, $positions, 'they are already in order' );
	}

	public function test_no_step_asks_anybody_for_a_credential(): void {
		/*
		 * ONB-3. The wording matters as much as the schema: a step that says
		 * "send us the password" would get one, whatever the database can hold.
		 */
		foreach ( Version1::steps() as $step ) {
			$text = strtolower( $step['title'] . ' ' . $step['description'] );

			$this->assertStringNotContainsString( 'send us the password', $text );
			$this->assertStringNotContainsString( 'provide your password', $text );
			$this->assertStringNotContainsString( 'share your login', $text );
		}
	}

	public function test_every_step_names_who_does_it(): void {
		foreach ( Version1::steps() as $step ) {
			$this->assertContains( $step['owner_side'], TemplateSteps::SIDES );
		}
	}
}
