<?php
/**
 * A site's ledger is read whole.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Commerce\Ledger;
use PHPUnit\Framework\TestCase;

/**
 * #597. The support screen lists a site's ledger and shows "Left" beside it.
 * Reading only the first 500 entries, oldest first, dropped a busy client's
 * newest ones, so the lines stopped adding up to what was left.
 */
final class LedgerListWholeTest extends TestCase {

	public function test_a_sites_entries_are_read_without_a_cap(): void {
		$GLOBALS['bwx_forge_test_calls'] = array();

		Ledger::for_site( 'site_one' );

		$reads = array_values( array_filter( $GLOBALS['bwx_forge_test_calls'], static fn( array $call ): bool => 'get_results' === $call[0] ) );

		$this->assertCount( 1, $reads );
		$this->assertStringContainsString( 'ORDER BY occurred_at ASC, id ASC', $reads[0][1] );
		$this->assertStringNotContainsString( 'LIMIT', $reads[0][1] );
	}
}
