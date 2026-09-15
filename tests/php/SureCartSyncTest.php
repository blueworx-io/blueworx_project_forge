<?php
/**
 * What Forge makes of a SureCart subscription.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Commerce\SureCart\Sync;
use PHPUnit\Framework\TestCase;

final class SureCartSyncTest extends TestCase {

	private function fixture(): array {
		return (array) json_decode( (string) file_get_contents( __DIR__ . '/fixtures/surecart-subscriptions.json' ), true );
	}

	public function test_a_subscription_is_read_into_the_shape_forge_keeps(): void {
		$row = Sync::normalise( $this->fixture()['data'][0] );

		self::assertSame( 'sub_01', $row['id'] );
		self::assertSame( 'active', $row['status'] );
		self::assertSame( 'Acme Ltd', $row['customer_name'] );
		self::assertSame( 'billing@acme.test', $row['customer_email'] );
		self::assertSame( 'Care Plan', $row['product_name'] );
		self::assertSame( 12000, $row['amount'] );
		self::assertSame( 'GBP', $row['currency'] );
		self::assertSame( 'monthly', $row['interval'] );
		self::assertSame( '2026-09-15', $row['renews_on'] );
	}

	public function test_a_customer_with_only_first_and_last_names_is_named(): void {
		$row = Sync::normalise( $this->fixture()['data'][1] );

		self::assertSame( 'Jo Bloggs', $row['customer_name'] );
		self::assertSame( 'yearly', $row['interval'] );
		self::assertSame( 'USD', $row['currency'] );
	}

	public function test_an_odd_interval_is_spelled_out(): void {
		$row = Sync::normalise( $this->fixture()['data'][2] );

		self::assertSame( 'every 3 months', $row['interval'] );
		self::assertSame( 'canceled', $row['status'] );
	}

	public function test_missing_fields_become_nothing_rather_than_errors(): void {
		$row = Sync::normalise( array( 'id' => 'sub_x' ) );

		self::assertSame( 'sub_x', $row['id'] );
		self::assertSame( '', $row['customer_name'] );
		self::assertSame( 0, $row['amount'] );
		self::assertSame( '', $row['renews_on'] );
	}

	public function test_the_reminder_title_names_the_customer_and_the_money(): void {
		self::assertSame( 'Subscription Renewal: Acme Ltd - (£120.00)', Sync::title( 'Acme Ltd', 12000, 'GBP' ) );
		self::assertSame( 'Subscription Renewal: Jo Bloggs - ($499.00)', Sync::title( 'Jo Bloggs', 49900, 'USD' ) );
		self::assertSame( 'Subscription Renewal: Gone Co - (€10.00)', Sync::title( 'Gone Co', 1000, 'EUR' ) );
		self::assertSame( 'Subscription Renewal: Someone - (CHF 5.50)', Sync::title( 'Someone', 550, 'CHF' ) );
		self::assertSame( 'Subscription Renewal: Nobody - (£0.00)', Sync::title( '', 0, 'GBP' ) );
	}
}
