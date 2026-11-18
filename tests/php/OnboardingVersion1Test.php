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
 * ONB-1 (#159). What the seed is.
 */
final class OnboardingVersion1Test extends TestCase {

	public function test_the_agreed_definition_is_ready_to_seed(): void {
		$this->assertTrue( Version1::READY );
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

	public function test_it_covers_the_twelve_agreed_categories_in_order(): void {
		$this->assertSame(
			array(
				'Domain and DNS',
				'Hosting',
				'Email and SMTP',
				'People and sign-off',
				'Brand',
				'Content',
				'Accounts and tools',
				'Search and your old site',
				'Design review',
				'Build review',
				'Legal and compliance',
				'Review and launch',
			),
			array_values( array_unique( array_column( Version1::steps(), 'category' ) ) )
		);
	}

	public function test_every_step_is_in_a_real_section_and_the_sections_run_in_order(): void {
		$order = array( Sections::FOUNDATIONS, Sections::BUILD_REVIEWS, Sections::LAUNCH );
		$last  = 0;

		foreach ( Version1::steps() as $step ) {
			$this->assertTrue( Sections::exists( $step['section'] ) );

			$at = array_search( $step['section'], $order, true );
			$this->assertGreaterThanOrEqual( $last, $at, $step['title'] . ' is out of section order' );
			$last = $at;
		}
	}

	public function test_every_step_in_a_launch_critical_category_gates_the_launch(): void {
		foreach ( Version1::steps() as $step ) {
			if ( in_array( $step['category'], Version1::LAUNCH_CRITICAL, true ) ) {
				$this->assertSame( 1, $step['launch_critical'], $step['title'] );
			}
		}
	}

	public function test_an_optional_step_never_gates_the_launch(): void {
		foreach ( Version1::steps() as $step ) {
			if ( 1 === $step['optional'] ) {
				$this->assertSame( 0, $step['launch_critical'], $step['title'] );
			}
		}
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

			$this->assertStringNotContainsString( 'password', $text );
			$this->assertStringNotContainsString( 'share your login', $text );
			$this->assertStringNotContainsString( 'send us your login', $text );
		}
	}

	public function test_every_step_names_who_does_it(): void {
		foreach ( Version1::steps() as $step ) {
			$this->assertContains( $step['owner_side'], TemplateSteps::SIDES );
		}
	}

	public function test_our_team_has_its_own_steps(): void {
		$internal = array_filter(
			Version1::steps(),
			static fn ( array $step ): bool => TemplateSteps::INTERNAL === $step['owner_side']
		);

		$this->assertNotEmpty( $internal );
	}
}
