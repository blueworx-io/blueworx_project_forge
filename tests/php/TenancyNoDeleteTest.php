<?php
/**
 * Deactivation, never deletion.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Clients;
use Blueworx\Forge\Tenancy\Integrations;
use PHPUnit\Framework\TestCase;

/**
 * NOTIF-5. A deleted client takes its sites', its work's and its ledger's
 * meaning with it, so there is no delete to call by accident — this asserts the
 * absence rather than trusting a comment saying so.
 *
 * The rest of these repositories talk to a database and are proven in the
 * Playwright suite, where there is one.
 */
final class TenancyNoDeleteTest extends TestCase {

	/**
	 * No repository exposes anything that removes a row. An integration is cut
	 * off by revoking its key, which keeps the record and its history.
	 */
	public function test_neither_repository_can_delete(): void {
		foreach ( array( Clients::class, ClientSites::class, Integrations::class ) as $class ) {
			$methods = get_class_methods( $class );

			foreach ( array( 'delete', 'remove', 'drop', 'purge' ) as $forbidden ) {
				$this->assertNotContains( $forbidden, $methods, $class . ' must not be able to delete a record.' );
			}
		}

		$this->assertContains( 'deactivate', get_class_methods( Clients::class ) );
		$this->assertContains( 'deactivate', get_class_methods( ClientSites::class ) );
	}
}
