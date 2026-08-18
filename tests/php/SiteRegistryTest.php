<?php
/**
 * Client site registration, key issue, rotation and revocation.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Sites\Registry;
use PHPUnit\Framework\TestCase;

/**
 * ARCH-6: the studio issues a per-site key at registration; keys are rotatable
 * and revocable from the studio; registration is a manual studio action, so
 * there is no self-service enrolment anywhere in this class.
 */
final class SiteRegistryTest extends TestCase {

	/**
	 * Clears the fake option store before each test.
	 */
	protected function setUp(): void {
		$GLOBALS['bwx_forge_test_options'] = array();
		$GLOBALS['bwx_forge_test_actions'] = array();
	}

	/**
	 * Registering yields an id and a key, and the key is long enough to be worth
	 * having.
	 */
	public function test_registering_issues_a_site_id_and_a_key(): void {
		$site = Registry::register( 'Acme', 'https://acme.example' );

		$this->assertNotSame( '', $site['site_id'] );
		$this->assertGreaterThanOrEqual( 43, strlen( $site['key'] ) );
	}

	/**
	 * Two registrations never collide, in id or in key.
	 */
	public function test_every_site_gets_its_own_id_and_key(): void {
		$first  = Registry::register( 'Acme', 'https://acme.example' );
		$second = Registry::register( 'Beta', 'https://beta.example' );

		$this->assertNotSame( $first['site_id'], $second['site_id'] );
		$this->assertNotSame( $first['key'], $second['key'] );
	}

	/**
	 * A registered site is active and its key is retrievable for signing.
	 */
	public function test_a_registered_site_is_active(): void {
		$site = Registry::register( 'Acme', 'https://acme.example' );

		$record = Registry::get( $site['site_id'] );

		$this->assertSame( Registry::STATUS_ACTIVE, $record['status'] );
		$this->assertSame( $site['key'], Registry::key_for( $site['site_id'] ) );
	}

	/**
	 * Rotation issues a new key and the old one stops working immediately —
	 * rotation that left the old key valid would not be rotation.
	 */
	public function test_rotation_replaces_the_key(): void {
		$site = Registry::register( 'Acme', 'https://acme.example' );
		$old  = $site['key'];

		$new = Registry::rotate( $site['site_id'] );

		$this->assertNotSame( $old, $new );
		$this->assertSame( $new, Registry::key_for( $site['site_id'] ) );
	}

	/**
	 * Revoking leaves the record — the site's history is not deleted — but the
	 * key can no longer be used to sign anything.
	 */
	public function test_revoking_keeps_the_record_and_kills_the_key(): void {
		$site = Registry::register( 'Acme', 'https://acme.example' );

		$this->assertTrue( Registry::revoke( $site['site_id'] ) );

		$record = Registry::get( $site['site_id'] );

		$this->assertSame( Registry::STATUS_REVOKED, $record['status'] );
		$this->assertNull( Registry::key_for( $site['site_id'] ), 'a revoked site must not hand back a usable key' );
	}

	/**
	 * A revoked site cannot be rotated back into life. Reinstating is a
	 * registration decision, not a key operation.
	 */
	public function test_a_revoked_site_cannot_be_rotated_back(): void {
		$site = Registry::register( 'Acme', 'https://acme.example' );
		Registry::revoke( $site['site_id'] );

		$this->assertNull( Registry::rotate( $site['site_id'] ) );
		$this->assertSame( Registry::STATUS_REVOKED, Registry::get( $site['site_id'] )['status'] );
	}

	/**
	 * Operations on a site that was never registered are refused rather than
	 * quietly creating one — there is no self-service enrolment (ARCH-6).
	 */
	public function test_an_unknown_site_is_not_created_by_asking_for_it(): void {
		$this->assertNull( Registry::get( 'nope' ) );
		$this->assertNull( Registry::key_for( 'nope' ) );
		$this->assertNull( Registry::rotate( 'nope' ) );
		$this->assertFalse( Registry::revoke( 'nope' ) );
		$this->assertSame( array(), Registry::all() );
	}

	/**
	 * Listing sites never exposes their keys. This is the method an admin screen
	 * or a REST response uses, and a key belongs in exactly one response: the one
	 * that issued it.
	 */
	public function test_listing_sites_never_exposes_a_key(): void {
		$site = Registry::register( 'Acme', 'https://acme.example' );

		$listed = Registry::all();

		$this->assertArrayHasKey( $site['site_id'], $listed );
		$this->assertArrayNotHasKey( 'key', $listed[ $site['site_id'] ] );
	}
}
