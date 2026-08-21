<?php
/**
 * Record ids.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\Ids;
use PHPUnit\Framework\TestCase;

/**
 * Ids carry the moment they were made and then randomness. The time is what
 * every history in this plugin leans on: it is read oldest first, ordered by a
 * timestamp held in whole seconds with the id breaking the tie, so ids that did
 * not sort left same-second events in an order nobody chose. The randomness is
 * the reason Sites\Registry gives for ids not being counters — they appear in
 * URLs and logs, and knowing one must not hand somebody the next.
 */
final class TenancyIdsTest extends TestCase {

	/**
	 * The shape: a prefix that says what the record is, then the time, then
	 * random hex — and short enough for the varchar(32) every id column is.
	 */
	public function test_an_id_is_its_prefix_then_hex(): void {
		$id = Ids::create( 'cli' );

		$this->assertMatchesRegularExpression( '/^cli_[0-9a-f]{26}$/', $id );
		$this->assertLessThanOrEqual( 32, strlen( $id ) );
	}

	/**
	 * The property the histories depend on.
	 */
	public function test_ids_sort_in_the_order_they_were_made(): void {
		$made = array();

		for ( $i = 0; $i < 500; $i++ ) {
			$made[] = Ids::create( 'evt' );
		}

		$sorted = $made;
		sort( $sorted, SORT_STRING );

		$this->assertSame( $made, $sorted );
	}

	/**
	 * Two ids never collide.
	 */
	public function test_ids_do_not_repeat(): void {
		$ids = array();

		for ( $i = 0; $i < 200; $i++ ) {
			$ids[] = Ids::create( 'cst' );
		}

		$this->assertCount( 200, array_unique( $ids ) );
	}

	/**
	 * Ids made in the same instant still end unpredictably, so the time in
	 * front of them does not turn ids into a sequence anybody can walk.
	 */
	public function test_knowing_one_id_does_not_give_you_the_next(): void {
		$tails = array();

		for ( $i = 0; $i < 500; $i++ ) {
			$tails[] = substr( Ids::create( 'evt' ), -12 );
		}

		$this->assertCount( 500, array_unique( $tails ) );
	}
}
