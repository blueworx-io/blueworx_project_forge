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
	 * The plugin list keeps what is well formed and drops the rest, without
	 * refusing the report (#718).
	 */
	public function test_bad_plugin_entries_are_dropped_not_refused(): void {
		$checked = Validate::report(
			array(
				'plugins' => array(
					array(
						'slug'    => 'blueworx-forge-client',
						'version' => '2.234.0',
					),
					array(
						'slug'    => 'Upper_Case',
						'version' => '1',
					),
					array(
						'slug'    => '../etc',
						'version' => '1',
					),
					array(
						'slug'    => "trailing-newline
",
						'version' => '1',
					),
					array(
						'slug'    => str_repeat( 'a', 101 ),
						'version' => '1',
					),
					array(
						'slug'    => '',
						'version' => '1',
					),
					array(
						'slug'    => 'long-version',
						'version' => str_repeat( '1', 33 ),
					),
					array(
						'slug'    => 'no-version',
					),
					array(
						'slug'    => array( 'x' ),
						'version' => '1',
					),
					'not-an-entry',
					array(
						'slug'    => 'blueworx-forge-client',
						'version' => '2.234.0',
					),
					array(
						'slug'    => 'edge',
						'version' => str_repeat( '1', 32 ),
					),
					array(
						'slug'    => str_repeat( 'a', 100 ),
						'version' => '',
					),
				),
			)
		);

		$this->assertSame( array(), $checked['errors'] );
		$this->assertSame(
			array(
				array(
					'slug'    => 'blueworx-forge-client',
					'version' => '2.234.0',
				),
				array(
					'slug'    => 'edge',
					'version' => str_repeat( '1', 32 ),
				),
				array(
					'slug'    => str_repeat( 'a', 100 ),
					'version' => '',
				),
			),
			$checked['values']['plugins']
		);
	}

	/**
	 * No more than 200 plugins are kept, and a report with no list, or a list
	 * that is not a list, leaves the stored one alone (#718).
	 */
	public function test_the_plugin_list_is_capped_and_optional(): void {
		$many = array();

		for ( $i = 0; $i < 250; $i++ ) {
			$many[] = array(
				'slug'    => 'plugin-' . $i,
				'version' => '1.0',
			);
		}

		$checked = Validate::report( array( 'plugins' => $many ) );

		$this->assertCount( 200, $checked['values']['plugins'] );
		$this->assertArrayNotHasKey( 'plugins', Validate::report( array( 'wp_version' => '6.7' ) )['values'] );
		$this->assertArrayNotHasKey( 'plugins', Validate::report( array( 'plugins' => 'nope' ) )['values'] );
		// A JSON object is not a list: it must not clear what is stored.
		$this->assertArrayNotHasKey( 'plugins', Validate::report( array( 'plugins' => array( 'slug' => 'x' ) ) )['values'] );
		// Two active plugin files in one folder are two plugins.
		$this->assertSame(
			array(
				array(
					'slug'    => 'suite',
					'version' => '1.0',
				),
				array(
					'slug'    => 'suite',
					'version' => '2.0',
				),
			),
			Validate::report(
				array(
					'plugins' => array(
						array(
							'slug'    => 'suite',
							'version' => '1.0',
						),
						array(
							'slug'    => 'suite',
							'version' => '2.0',
						),
					),
				)
			)['values']['plugins']
		);
		$this->assertSame( array(), Validate::report( array( 'plugins' => array() ) )['values']['plugins'] );
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
