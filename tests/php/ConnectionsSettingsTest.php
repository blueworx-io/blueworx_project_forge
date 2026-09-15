<?php
/**
 * Who a store's renewal reminders are for.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Commerce\SureCart\Connections;
use PHPUnit\Framework\TestCase;

final class ConnectionsSettingsTest extends TestCase {

	public function test_a_primary_is_required_and_hours_default(): void {
		$checked = Connections::settings_from( array( 'primary_user_id' => 'usr_a' ) );

		self::assertSame( array(), $checked['errors'] );
		self::assertSame( 'usr_a', $checked['values']['primary_user_id'] );
		self::assertSame( '', $checked['values']['reviewer_id'] );
		self::assertSame( '0.25', $checked['values']['hours_primary'] );
		self::assertSame( '0', $checked['values']['hours_review'] );
	}

	public function test_nobody_is_refused(): void {
		$checked = Connections::settings_from( array() );

		self::assertArrayHasKey( 'primary_user_id', $checked['errors'] );
	}

	public function test_a_seat_must_be_a_person(): void {
		$checked = Connections::settings_from(
			array(
				'primary_user_id' => 'usr_a',
				'reviewer_id'     => 'bob',
			)
		);

		self::assertArrayHasKey( 'reviewer_id', $checked['errors'] );
	}

	public function test_hours_are_numbers(): void {
		$checked = Connections::settings_from(
			array(
				'primary_user_id' => 'usr_a',
				'hours_primary'   => 'lots',
				'hours_review'    => '-2',
				'hours_delivery'  => '1.333',
			)
		);

		self::assertArrayHasKey( 'hours_primary', $checked['errors'] );
		self::assertArrayHasKey( 'hours_review', $checked['errors'] );
		self::assertSame( '1.33', $checked['values']['hours_delivery'] );
	}
}
