<?php
/**
 * What a person's role accepts.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\StaffRoles;
use PHPUnit\Framework\TestCase;

/**
 * #474. The two refusals the issue names, and the tidying around them.
 */
final class StaffRolesTest extends TestCase {

	public function test_an_empty_role_is_fine(): void {
		$checked = StaffRoles::clean( array() );

		$this->assertSame( array(), $checked['errors'] );
		$this->assertSame( array(), $checked['values']['duties'] );
	}

	public function test_an_ongoing_contract_has_no_end(): void {
		$checked = StaffRoles::clean( array( 'starts_on' => '2026-01-05' ) );

		$this->assertSame( array(), $checked['errors'] );
		$this->assertSame( '', $checked['values']['ends_on'] );
	}

	public function test_an_end_before_the_start_is_refused(): void {
		$checked = StaffRoles::clean(
			array(
				'starts_on' => '2026-03-01',
				'ends_on'   => '2026-02-28',
			)
		);

		$this->assertArrayHasKey( 'ends_on', $checked['errors'] );
	}

	public function test_the_same_day_is_allowed(): void {
		$checked = StaffRoles::clean(
			array(
				'starts_on' => '2026-03-01',
				'ends_on'   => '2026-03-01',
			)
		);

		$this->assertSame( array(), $checked['errors'] );
	}

	public function test_hours_must_be_a_non_negative_number(): void {
		$this->assertArrayHasKey( 'weekly_hours', StaffRoles::clean( array( 'weekly_hours' => '-1' ) )['errors'] );
		$this->assertArrayHasKey( 'weekly_hours', StaffRoles::clean( array( 'weekly_hours' => 'lots' ) )['errors'] );
		$this->assertSame( array(), StaffRoles::clean( array( 'weekly_hours' => '37.5' ) )['errors'] );
		$this->assertSame( array(), StaffRoles::clean( array( 'weekly_hours' => '0' ) )['errors'] );
	}

	public function test_blank_duties_are_dropped(): void {
		$checked = StaffRoles::clean( array( 'duties' => array( ' Weekly release ', '', '  ' ) ) );

		$this->assertSame( array( 'Weekly release' ), $checked['values']['duties'] );
	}
}
