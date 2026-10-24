<?php
/**
 * Creating a client's site on the network, linking it and giving its logins.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

require_once __DIR__ . '/support/multisite-stubs.php';

use Blueworx\Forge\Client\Access;
use Blueworx\Forge\Client\Connection;
use Blueworx\Forge\Client\Network\Sites;
use PHPUnit\Framework\TestCase;

/**
 * #621. What the network does when the studio asks: a site at a checked
 * address, owned by a network administrator who already has access; linked
 * to its Forge site; and the client's people added with the roles the design
 * names, by email, set-password email for new accounts only.
 */
final class NetworkSitesTest extends TestCase {

	protected function setUp(): void {
		bwx_forge_test_network();
		$GLOBALS['bwx_forge_test_options'] = array();
	}

	public function test_an_address_is_lowercase_letters_digits_and_hyphens(): void {
		$this->assertTrue( Sites::valid_slug( 'acme' ) );
		$this->assertTrue( Sites::valid_slug( 'acme-2026' ) );
		$this->assertFalse( Sites::valid_slug( '' ) );
		$this->assertFalse( Sites::valid_slug( 'Acme' ) );
		$this->assertFalse( Sites::valid_slug( '-acme' ) );
		$this->assertFalse( Sites::valid_slug( 'acme/..' ) );
		$this->assertFalse( Sites::valid_slug( 'wp-admin' ) );
		$this->assertFalse( Sites::valid_slug( str_repeat( 'a', 64 ) ) );
	}

	public function test_admins_get_editor_and_manager_viewers_manager_and_nothing_else_anything(): void {
		$this->assertSame( array( 'editor', Access::ROLE ), Sites::roles_for( 'editor' ) );
		$this->assertSame( array( Access::ROLE ), Sites::roles_for( 'manager' ) );
		$this->assertSame( array(), Sites::roles_for( 'administrator' ) );
		$this->assertSame( array(), Sites::roles_for( '' ) );
	}

	public function test_a_site_is_made_at_the_address_and_marked_as_forges(): void {
		$made = Sites::create( 'acme', 'Acme Ltd', 'cst_1' );

		$this->assertIsArray( $made );
		$this->assertFalse( $made['existed'] );
		$site = $GLOBALS['bwx_forge_test_net']['sites'][ $made['blog_id'] ];
		$this->assertSame( 'network.test', $site['domain'] );
		$this->assertSame( '/acme/', $site['path'] );
		$this->assertSame( 'Acme Ltd', $site['title'] );
		$this->assertSame( 'cst_1', get_blog_option( $made['blog_id'], Sites::MARK ) );
		$this->assertSame( 'https://network.test/acme', $made['url'] );
		$this->assertSame( 'https://network.test/acme/wp-admin/', $made['admin_url'] );
	}

	public function test_on_a_subdomain_network_the_address_is_a_subdomain(): void {
		bwx_forge_test_network( true, true );

		$made = Sites::create( 'acme', 'Acme Ltd', 'cst_1' );
		$site = $GLOBALS['bwx_forge_test_net']['sites'][ $made['blog_id'] ];

		$this->assertSame( 'acme.network.test', $site['domain'] );
		$this->assertSame( '/', $site['path'] );
	}

	public function test_the_owner_is_an_existing_network_administrator_and_nobody_new_is_made_one(): void {
		$made = Sites::create( 'acme', 'Acme Ltd', 'cst_1' );

		$this->assertSame( array( 1 => array( 'administrator' ) ), $GLOBALS['bwx_forge_test_net']['roles'][ $made['blog_id'] ] );
	}

	public function test_asking_again_for_the_same_forge_site_answers_with_the_site_already_made(): void {
		$first  = Sites::create( 'acme', 'Acme Ltd', 'cst_1' );
		$second = Sites::create( 'acme', 'Acme Ltd', 'cst_1' );

		$this->assertSame( $first['blog_id'], $second['blog_id'] );
		$this->assertTrue( $second['existed'] );
	}

	public function test_an_address_already_taken_by_another_site_is_refused(): void {
		Sites::create( 'acme', 'Acme Ltd', 'cst_1' );

		$answer = Sites::create( 'acme', 'Other', 'cst_2' );

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'bwx_forge_network_address_taken', $answer->get_error_code() );
	}

	public function test_a_site_is_not_made_off_a_network(): void {
		bwx_forge_test_network( false );

		$answer = Sites::create( 'acme', 'Acme Ltd', 'cst_1' );

		$this->assertInstanceOf( WP_Error::class, $answer );
		$this->assertSame( 'bwx_forge_network_not_a_network', $answer->get_error_code() );
	}

	public function test_linking_stores_the_connection_on_the_new_site(): void {
		$made = Sites::create( 'acme', 'Acme Ltd', 'cst_1' );

		$this->assertTrue( Sites::link( $made['blog_id'], 'cst_1', 'https://studio.test', 'site_9', str_repeat( 'ab', 32 ) ) );
		$this->assertSame( 'site_9', get_option( Connection::OPTION_SITE_ID ) );
		$this->assertSame( str_repeat( 'ab', 32 ), get_option( Connection::OPTION_KEY ) );
		// Back on the site the request came in on.
		$this->assertSame( 1, $GLOBALS['bwx_forge_test_net']['blog'] );
	}

	public function test_a_site_forge_did_not_make_for_this_forge_site_cannot_be_linked_or_given_logins(): void {
		$made = Sites::create( 'acme', 'Acme Ltd', 'cst_1' );

		foreach ( array( 1, $made['blog_id'] ) as $blog ) {
			$link = Sites::link( $blog, 'cst_other', 'https://studio.test', 'site_9', 'k' );
			$this->assertInstanceOf( WP_Error::class, $link );

			$people = Sites::people( $blog, 'cst_other', array() );
			$this->assertInstanceOf( WP_Error::class, $people );
		}
	}

	public function test_new_people_get_an_account_their_roles_and_a_set_password_email(): void {
		$made = Sites::create( 'acme', 'Acme Ltd', 'cst_1' );

		$outcomes = Sites::people(
			$made['blog_id'],
			'cst_1',
			array(
				array(
					'email'  => 'boss@acme.test',
					'name'   => 'Boss',
					'access' => 'editor',
				),
				array(
					'email'  => 'reader@acme.test',
					'name'   => 'Reader',
					'access' => 'manager',
				),
			)
		);

		$this->assertSame( array( 'created', 'created' ), array_column( $outcomes, 'outcome' ) );
		$boss   = get_user_by( 'email', 'boss@acme.test' )->ID;
		$reader = get_user_by( 'email', 'reader@acme.test' )->ID;
		$roles  = $GLOBALS['bwx_forge_test_net']['roles'][ $made['blog_id'] ];
		$this->assertSame( array( 'editor', Access::ROLE ), $roles[ $boss ] );
		$this->assertSame( array( Access::ROLE ), $roles[ $reader ] );
		$this->assertSame(
			array(
				array(
					'user'   => $boss,
					'notify' => 'user',
				),
				array(
					'user'   => $reader,
					'notify' => 'user',
				),
			),
			$GLOBALS['bwx_forge_test_net']['mail']
		);
		// The Forge: Manager role is made on the new site before anyone is given it.
		$this->assertContains( Access::ROLE, $GLOBALS['bwx_forge_test_net']['role_made'][ $made['blog_id'] ] ?? array() );
	}

	public function test_somebody_already_on_the_network_is_added_without_a_second_account_or_an_email(): void {
		$made = Sites::create( 'acme', 'Acme Ltd', 'cst_1' );
		$GLOBALS['bwx_forge_test_net']['users'][7] = array(
			'login' => 'boss',
			'email' => 'Boss@Acme.test',
		);

		$outcomes = Sites::people(
			$made['blog_id'],
			'cst_1',
			array(
				array(
					'email'  => 'boss@acme.test',
					'name'   => 'Boss',
					'access' => 'editor',
				),
			)
		);

		$this->assertSame( 'added', $outcomes[0]['outcome'] );
		$this->assertCount( 2, $GLOBALS['bwx_forge_test_net']['users'] );
		$this->assertSame( array(), $GLOBALS['bwx_forge_test_net']['mail'] );
		$this->assertSame( array( 'editor', Access::ROLE ), $GLOBALS['bwx_forge_test_net']['roles'][ $made['blog_id'] ][7] );
	}

	public function test_a_bad_email_or_an_access_nobody_named_is_skipped_and_said_so(): void {
		$made = Sites::create( 'acme', 'Acme Ltd', 'cst_1' );

		$outcomes = Sites::people(
			$made['blog_id'],
			'cst_1',
			array(
				array(
					'email'  => 'not-an-email',
					'access' => 'editor',
				),
				array(
					'email'  => 'sneaky@acme.test',
					'access' => 'administrator',
				),
			)
		);

		$this->assertSame( array( 'failed', 'failed' ), array_column( $outcomes, 'outcome' ) );
		$this->assertFalse( get_user_by( 'email', 'sneaky@acme.test' ) );
	}
}
