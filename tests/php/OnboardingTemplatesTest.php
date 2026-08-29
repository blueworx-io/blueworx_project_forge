<?php
/**
 * When a checklist may change, and when it may never change again.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Onboarding\TemplateSteps;
use Blueworx\Forge\Onboarding\Templates;
use PHPUnit\Framework\TestCase;

/**
 * ONB-E2 (#159). A published version is frozen, and that is the whole reason a
 * client's assignment (#160) means anything — a checklist that could be edited
 * underneath somebody part-way through is not a checklist, it is a moving
 * target.
 */
final class OnboardingTemplatesTest extends TestCase {

	public function test_a_draft_may_be_edited(): void {
		$this->assertTrue( Templates::may_edit( array( 'status' => Templates::DRAFT ) ) );
	}

	public function test_a_published_version_may_never_be_edited_again(): void {
		$this->assertFalse( Templates::may_edit( array( 'status' => Templates::PUBLISHED ) ) );
	}

	public function test_something_that_is_not_a_template_may_not_be_edited(): void {
		$this->assertFalse( Templates::may_edit( array() ) );
		$this->assertFalse( Templates::may_edit( array( 'status' => 'whatever' ) ) );
	}

	public function test_the_first_version_is_one(): void {
		$this->assertSame( 1, Templates::next_version( 0 ) );
	}

	public function test_each_version_follows_the_highest_there_is(): void {
		$this->assertSame( 3, Templates::next_version( 2 ) );
	}

	public function test_a_version_number_never_goes_backwards(): void {
		/*
		 * Not "count the versions plus one". A version deleted while still a
		 * draft would make a count reissue a number that a client's assignment
		 * already points at, and two different checklists would then answer to
		 * version 4.
		 */
		$this->assertSame( 12, Templates::next_version( 11 ) );
	}

	public function test_a_published_version_carries_who_published_it_and_when(): void {
		$stamp = Templates::publication( 42, 1756400000 );

		$this->assertSame( Templates::PUBLISHED, $stamp['status'] );
		$this->assertSame( 42, $stamp['published_by'] );
		$this->assertSame( 1756400000, $stamp['published_at'] );
	}

	public function test_dependencies_survive_a_round_trip(): void {
		$stored = TemplateSteps::render_dependencies( array( 'ots_one', 'ots_two' ) );

		$this->assertSame( array( 'ots_one', 'ots_two' ), TemplateSteps::read_dependencies( $stored ) );
	}

	public function test_a_step_depending_on_nothing_reads_as_nothing(): void {
		$this->assertSame( '', TemplateSteps::render_dependencies( array() ) );
		$this->assertSame( array(), TemplateSteps::read_dependencies( '' ) );
	}

	public function test_already_stored_dependencies_are_left_alone(): void {
		// Copying a step hands back what was read, so this has to be a no-op
		// rather than a list of characters.
		$this->assertSame( 'ots_one,ots_two', TemplateSteps::render_dependencies( 'ots_one,ots_two' ) );
	}
}
