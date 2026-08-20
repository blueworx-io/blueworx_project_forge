<?php
/**
 * What a client site is allowed to say about itself.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\Validate;
use PHPUnit\Framework\TestCase;

/**
 * The report is the one place a client site writes to a studio record, so what
 * it may write is a closed list and every value is bounded. A signed request
 * proves which site is calling; it does not make that site trusted to put five
 * hundred characters into a varchar(32).
 */
final class ValidateReportTest extends TestCase {

	/**
	 * An ordinary report comes through cleaned.
	 */
	public function test_a_plain_report_is_accepted(): void {
		$checked = Validate::report(
			array(
				'home_url'       => 'https://client.example',
				'wp_version'     => '6.7.1',
				'php_version'    => '8.3.3',
				'plugin_version' => '2.10.0',
				'mail_capable'   => 'yes',
				'mail_detail'    => 'wp_mail is available.',
			)
		);

		$this->assertSame( array(), $checked['errors'] );
		$this->assertSame( 'https://client.example', $checked['values']['home_url'] );
		$this->assertSame( 'yes', $checked['values']['mail_capable'] );
	}

	/**
	 * A site that says something else about its mail is not believed. Anything
	 * outside the three known answers would end up in a column that screens
	 * switch on.
	 */
	public function test_an_unknown_mail_answer_is_refused(): void {
		$checked = Validate::report( array( 'mail_capable' => 'probably' ) );

		$this->assertArrayHasKey( 'mail_capable', $checked['errors'] );
	}

	/**
	 * A field nobody asked for is dropped rather than stored. The report writes
	 * to a record the studio owns, and the site does not get to choose which
	 * columns it touches.
	 */
	public function test_fields_outside_the_list_are_dropped(): void {
		$checked = Validate::report(
			array(
				'wp_version' => '6.7.1',
				'key_state'  => 'active',
				'client_id'  => 'cli_ffffffffffffffff',
			)
		);

		$this->assertSame( array( 'wp_version' ), array_keys( $checked['values'] ) );
	}

	/**
	 * Long values are trimmed to what will store, not refused: a site running an
	 * odd build should still get its mail capability recorded.
	 */
	public function test_over_long_values_are_trimmed_rather_than_refused(): void {
		$checked = Validate::report(
			array(
				'wp_version'  => str_repeat( '9', 100 ),
				'mail_detail' => str_repeat( 'x', 500 ),
				'home_url'    => 'https://client.example/' . str_repeat( 'a', 400 ),
			)
		);

		$this->assertSame( array(), $checked['errors'] );
		$this->assertLessThanOrEqual( 32, strlen( $checked['values']['wp_version'] ) );
		$this->assertLessThanOrEqual( 191, strlen( $checked['values']['mail_detail'] ) );
		$this->assertLessThanOrEqual( 255, strlen( $checked['values']['home_url'] ) );
	}

	/**
	 * An empty report is not an error. A site with nothing new to say still
	 * proves it is alive by asking, and that sighting is worth recording.
	 */
	public function test_an_empty_report_is_accepted(): void {
		$checked = Validate::report( array() );

		$this->assertSame( array(), $checked['errors'] );
		$this->assertSame( array(), $checked['values'] );
	}
}
