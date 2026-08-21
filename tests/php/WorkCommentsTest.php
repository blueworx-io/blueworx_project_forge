<?php
/**
 * Internal notes do not leak.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Rest\Scope;
use Blueworx\Forge\Tenancy\Roles;
use Blueworx\Forge\Work\Comments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #100's acceptance, as a unit: a client reads client-visible comments and
 * cannot reach an internal note by any route.
 *
 * The query itself is the other half of that proof and lives in the REST suite,
 * because it needs a database. What is testable here is the rule the query
 * implements, and the default that applies when nobody said.
 */
final class WorkCommentsTest extends TestCase {

	/**
	 * A comment.
	 *
	 * @param string $visibility internal or client.
	 * @return array<string, mixed>
	 */
	private function comment( string $visibility ): array {
		return array(
			'id'         => 'cmt_1',
			'visibility' => $visibility,
			'body'       => 'Something.',
		);
	}

	/**
	 * Staff see both. That is what internal means.
	 */
	public function test_staff_see_everything(): void {
		$this->assertTrue( Comments::visible_to( $this->comment( Comments::INTERNAL ), Comments::SCOPE_STAFF ) );
		$this->assertTrue( Comments::visible_to( $this->comment( Comments::CLIENT ), Comments::SCOPE_STAFF ) );
	}

	/**
	 * A client sees one of the two, and it is not the internal one.
	 */
	public function test_a_client_never_sees_an_internal_note(): void {
		$this->assertFalse( Comments::visible_to( $this->comment( Comments::INTERNAL ), Comments::SCOPE_CLIENT ) );
		$this->assertTrue( Comments::visible_to( $this->comment( Comments::CLIENT ), Comments::SCOPE_CLIENT ) );
	}

	/**
	 * A comment with no visibility on it, or an unrecognised one, is internal.
	 * The safe default is the one where a note is wrongly kept private rather
	 * than wrongly published.
	 */
	public function test_an_unknown_visibility_is_internal(): void {
		$this->assertFalse( Comments::visible_to( array( 'body' => 'No visibility set.' ), Comments::SCOPE_CLIENT ) );
		$this->assertSame( Comments::INTERNAL, Comments::visibility_of( 'everyone', Comments::SCOPE_STAFF ) );
		$this->assertSame( Comments::INTERNAL, Comments::visibility_of( '', Comments::SCOPE_STAFF ) );
	}

	/**
	 * A client cannot write an internal note whatever they ask for. There is
	 * nowhere for one of theirs to be internal to.
	 */
	public function test_a_client_cannot_write_an_internal_note(): void {
		$this->assertSame( Comments::CLIENT, Comments::visibility_of( Comments::INTERNAL, Comments::SCOPE_CLIENT ) );
	}

	/**
	 * Staff may write either, deliberately.
	 */
	public function test_staff_choose_the_visibility(): void {
		$this->assertSame( Comments::CLIENT, Comments::visibility_of( Comments::CLIENT, Comments::SCOPE_STAFF ) );
		$this->assertSame( Comments::INTERNAL, Comments::visibility_of( Comments::INTERNAL, Comments::SCOPE_STAFF ) );
	}

	/**
	 * The client-side roles from AUTH-5.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function roles(): array {
		return array(
			'primary administrator' => array( Roles::PRIMARY_ADMIN, Comments::SCOPE_STAFF ),
			'staff'                 => array( Roles::STAFF, Comments::SCOPE_STAFF ),
			'internal viewer'       => array( Roles::INTERNAL_VIEWER, Comments::SCOPE_STAFF ),
			'client administrator'  => array( Roles::CLIENT_ADMIN, Comments::SCOPE_CLIENT ),
			'client viewer'         => array( Roles::CLIENT_VIEWER, Comments::SCOPE_CLIENT ),
		);
	}

	/**
	 * Each role reads in the scope AUTH-5 gives it. An internal viewer is
	 * internal; a client administrator is not, however much administration they
	 * do.
	 *
	 * @param string $role     The role held.
	 * @param string $expected The scope it reads in.
	 */
	#[DataProvider( 'roles' )]
	public function test_each_role_reads_in_its_own_scope( string $role, string $expected ): void {
		$this->assertSame( $expected, Scope::from_roles( array( $role ) ) );
	}

	/**
	 * Somebody with no membership on this client is not a client reader with an
	 * empty list — they are nobody, and the route answers 403 rather than 200.
	 */
	public function test_no_membership_is_not_a_scope(): void {
		$this->assertSame( Scope::NONE, Scope::from_roles( array() ) );
		$this->assertSame( Scope::NONE, Scope::from_roles( array( 'not_a_role' ) ) );
	}
}
