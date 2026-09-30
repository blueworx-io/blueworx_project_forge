<?php
/**
 * Deactivation, never deletion.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Data\Schema;
use Blueworx\Forge\Tenancy\ClientErasure;
use Blueworx\Forge\Tenancy\ClientSites;
use Blueworx\Forge\Tenancy\Clients;
use Blueworx\Forge\Tenancy\Integrations;
use Blueworx\Forge\Tenancy\Memberships;
use Blueworx\Forge\Tenancy\Users;
use PHPUnit\Framework\TestCase;

/**
 * NOTIF-5. A deleted client takes its sites', its work's and its ledger's
 * meaning with it, so there is no delete to call by accident — this asserts the
 * absence rather than trusting a comment saying so.
 *
 * Deleting a whole client on purpose (#458) is the administrator's, and lives
 * in ClientErasure alone.
 *
 * The other exception is a person with nothing under their name: somebody added
 * by mistake, or twice. Users::delete() exists for them and refuses everyone
 * else, and the Playwright suite proves the refusal, where there is a database.
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
		foreach ( array( Clients::class, ClientSites::class, Integrations::class, Memberships::class ) as $class ) {
			$methods = get_class_methods( $class );

			foreach ( array( 'delete', 'remove', 'drop', 'purge' ) as $forbidden ) {
				$this->assertNotContains( $forbidden, $methods, $class . ' must not be able to delete a record.' );
			}
		}

		$this->assertContains( 'deactivate', get_class_methods( Clients::class ) );
		$this->assertContains( 'deactivate', get_class_methods( ClientSites::class ) );
		$this->assertContains( 'deactivate', get_class_methods( Users::class ) );
		$this->assertContains( 'deactivate', get_class_methods( Memberships::class ) );
	}

	/**
	 * #458. Deleting a client is one class's job, with one entry point, so the
	 * repositories above still cannot delete by accident.
	 */
	public function test_a_client_is_deleted_only_through_the_erasure(): void {
		$methods = get_class_methods( ClientErasure::class );

		$this->assertContains( 'erase', $methods );
		$this->assertContains( 'counts', $methods );
	}

	/**
	 * Every table is either cleared for a deleted client or belongs to no
	 * client at all, so a table added later cannot be forgotten quietly.
	 */
	public function test_every_table_is_covered_by_a_client_delete(): void {
		$cleared = array_unique( array_column( ClientErasure::plan(), 0 ) );
		$global  = ClientErasure::global_tables();

		$this->assertSame( array(), array_values( array_intersect( $cleared, $global ) ), 'A table is both cleared and global.' );

		foreach ( array_keys( Schema::definitions() ) as $table ) {
			$this->assertTrue( in_array( $table, $cleared, true ) || in_array( $table, $global, true ), $table . ' is neither cleared for a deleted client nor listed as global.' );
		}
	}

	/**
	 * The client row goes last, so a delete that stops halfway can be tried
	 * again; and the studio's own client is never deleted.
	 */
	public function test_the_client_goes_last_and_the_studio_stays(): void {
		$plan = ClientErasure::plan();
		$last = end( $plan );

		$this->assertSame( array( Schema::clients_table(), 'id', ClientErasure::BY_CLIENT ), $last );
		$this->assertNotSame( '', ClientErasure::refusal( array( 'id' => 'cli_studio' ), 'cli_studio' ) );
		$this->assertSame( '', ClientErasure::refusal( array( 'id' => 'cli_test' ), 'cli_studio' ) );
	}

	/**
	 * A person can only go when nothing has happened under their name, so the
	 * question of whether anything has is asked in one place the delete and
	 * the screen both read.
	 */
	public function test_a_person_is_only_deleted_without_history(): void {
		$methods = get_class_methods( Users::class );

		$this->assertContains( 'delete', $methods );
		$this->assertContains( 'has_history', $methods );

		foreach ( array( 'remove', 'drop', 'purge' ) as $forbidden ) {
			$this->assertNotContains( $forbidden, $methods );
		}
	}
}
