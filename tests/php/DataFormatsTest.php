<?php
/**
 * Column formats, said rather than guessed.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Data\Formats;
use PHPUnit\Framework\TestCase;

/**
 * The failure this prevents is silent. Core maps a set of column *names* to
 * '%d' — user_id and site_id among them — so a plugin table with a column of
 * that name holding a prefixed string id has every write cast to an integer,
 * stores 0, and reports success.
 */
final class DataFormatsTest extends TestCase {

	/**
	 * A string id stays a string, whatever the column is called. This is the
	 * whole point: `user_id` is one of the names core would have made '%d'.
	 */
	public function test_a_string_id_is_written_as_a_string(): void {
		$row = array(
			'id'      => 'mem_0123456789abcdef',
			'user_id' => 'usr_0123456789abcdef',
			'site_id' => 'site_0123456789abcdef',
		);

		$this->assertSame( array( '%s', '%s', '%s' ), Formats::for_row( $row ) );
	}

	/**
	 * Numbers are still numbers.
	 */
	public function test_integers_are_written_as_integers(): void {
		$row = array(
			'created_at'     => 1000000,
			'record_version' => 1,
		);

		$this->assertSame( array( '%d', '%d' ), Formats::for_row( $row ) );
	}

	/**
	 * The list is positional, so it has to come out in the row's own order.
	 */
	public function test_the_list_follows_the_rows_order(): void {
		$row = array(
			'id'         => 'usr_0123456789abcdef',
			'created_at' => 1000000,
			'email'      => 'sam@acme.co.uk',
			'created_by' => 3,
		);

		$this->assertSame( array( '%s', '%d', '%s', '%d' ), Formats::for_row( $row ) );
	}

	/**
	 * One format per column, always. A short list would have $wpdb fall back to
	 * guessing for the rest, which is the behaviour being avoided.
	 */
	public function test_every_column_gets_a_format(): void {
		$row = array_fill_keys( range( 'a', 'j' ), 'value' );

		$this->assertCount( count( $row ), Formats::for_row( $row ) );
	}
}
