<?php
/**
 * What a client's checklist is made of, and when it is fixed.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Onboarding\Assignment;
use Blueworx\Forge\Onboarding\Statuses;
use PHPUnit\Framework\TestCase;

/**
 * ONB-E3 (#160). Assigning a version writes real step rows there and then,
 * carrying what the template said at that moment. A later template edit has
 * nothing to reach.
 */
final class OnboardingAssignmentTest extends TestCase {

	/**
	 * A template step as the definition holds it.
	 *
	 * @param array<string, mixed> $overrides Anything to change.
	 * @return array<string, mixed>
	 */
	private function template_step( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                    => 'ots_one',
				'section'               => 'foundations',
				'category'              => 'Domain and DNS',
				'title'                 => 'Delegate the registrar',
				'owner_side'            => 'client',
				'optional'              => 0,
				'launch_critical'       => 1,
				'allows_not_applicable' => 0,
				'position'              => 3,
			),
			$overrides
		);
	}

	/**
	 * A site's onboarding record.
	 *
	 * @return array<string, mixed>
	 */
	private function onboarding(): array {
		return array(
			'id'             => 'sob_one',
			'client_site_id' => 'cst_one',
		);
	}

	public function test_a_new_step_starts_where_nobody_has_touched_it(): void {
		$step = Assignment::step_from( $this->template_step(), $this->onboarding() );

		$this->assertSame( Statuses::NOT_STARTED, $step['status'] );
	}

	public function test_the_step_carries_what_the_template_said(): void {
		$step = Assignment::step_from( $this->template_step(), $this->onboarding() );

		$this->assertSame( 'Delegate the registrar', $step['title'] );
		$this->assertSame( 'foundations', $step['section'] );
		$this->assertSame( 'client', $step['owner_side'] );
		$this->assertSame( 1, $step['launch_critical'] );
		$this->assertSame( 3, $step['position'] );
		$this->assertSame( 'ots_one', $step['template_step_id'] );
	}

	public function test_the_step_belongs_to_the_site_it_was_assigned_to(): void {
		$step = Assignment::step_from( $this->template_step(), $this->onboarding() );

		$this->assertSame( 'sob_one', $step['site_onboarding_id'] );
		$this->assertSame( 'cst_one', $step['client_site_id'] );
	}

	public function test_a_copied_step_is_not_the_template_step(): void {
		/*
		 * ONB-E3. Its own id, so the client's copy and the definition are two
		 * records from the first moment — which is what lets one change while
		 * the other never does.
		 */
		$step = Assignment::step_from( $this->template_step(), $this->onboarding() );

		$this->assertNotSame( 'ots_one', $step['id'] );
		$this->assertStringStartsWith( 'obs', $step['id'] );
	}

	public function test_a_step_has_nowhere_to_put_a_credential(): void {
		/*
		 * ONB-3, asserted rather than trusted. If a field for a secret ever
		 * appears, this is what says so.
		 */
		$step = Assignment::step_from( $this->template_step(), $this->onboarding() );

		foreach ( array_keys( $step ) as $field ) {
			foreach ( array( 'password', 'secret', 'token', 'credential', 'api_key' ) as $forbidden ) {
				$this->assertStringNotContainsString( $forbidden, $field );
			}
		}
	}

	public function test_the_handover_fields_start_empty_rather_than_absent(): void {
		/*
		 * ONB-3 stores what access was asked for and whether it was verified.
		 * Present and blank, so the shape of a step never depends on how far
		 * through it is.
		 */
		$step = Assignment::step_from( $this->template_step(), $this->onboarding() );

		foreach ( array( 'provider', 'account_identifier', 'access_role', 'invitation_status', 'verification_outcome' ) as $field ) {
			$this->assertArrayHasKey( $field, $step );
			$this->assertSame( '', $step[ $field ] );
		}
	}

	public function test_an_internal_step_is_still_the_clients_checklist(): void {
		// Who does it is not who it is for. Both kinds live on the same site.
		$step = Assignment::step_from(
			$this->template_step( array( 'owner_side' => 'internal' ) ),
			$this->onboarding()
		);

		$this->assertSame( 'internal', $step['owner_side'] );
		$this->assertSame( 'cst_one', $step['client_site_id'] );
	}

	public function test_a_step_starts_with_nobody_named(): void {
		/*
		 * The reviewer is the Point of Contact by default and overridable per
		 * step (ONB-2), and neither is known at assignment. Blank rather than
		 * guessed: a wrong name on a step is worse than no name, because
		 * somebody acts on it.
		 */
		$step = Assignment::step_from( $this->template_step(), $this->onboarding() );

		$this->assertSame( '', $step['owner_id'] );
		$this->assertSame( '', $step['reviewer_id'] );
		$this->assertSame( '', $step['due_on'] );
	}
}
