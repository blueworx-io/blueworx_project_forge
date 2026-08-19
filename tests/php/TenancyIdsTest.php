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
 * Ids are random rather than sequential for the reason Sites\Registry gives:
 * they appear in URLs and logs, and a sequential one advertises how many
 * clients exist and lets a caller guess the next.
 */
final class TenancyIdsTest extends TestCase {

	/**
	 * The shape: a prefix that says what the record is, then random hex.
	 */
	public function test_an_id_is_its_prefix_and_random_hex(): void {
		$this->assertMatchesRegularExpression( '/^cli_[0-9a-f]{16}$/', Ids::create( 'cli' ) );
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
}
