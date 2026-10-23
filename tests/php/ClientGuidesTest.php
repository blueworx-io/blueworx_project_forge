<?php
/**
 * The guides Forge gives a client.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

use Blueworx\Forge\Client\Access;
use Blueworx\Forge\Client\Guides;
use PHPUnit\Framework\TestCase;

/**
 * #580. One list of guides, shown on the BlueWorx Guides page and in the
 * client workspace. A Forge: Manager cannot open the first, so the second has
 * to carry the same words.
 */
final class ClientGuidesTest extends TestCase {

	public function test_the_nine_approved_guides_are_registered_for_forge_users_only(): void {
		$guides = Guides::add_guides( array() );

		$this->assertCount( 9, $guides );

		foreach ( $guides as $guide ) {
			$this->assertSame( Access::USE, $guide['capability'], $guide['id'] );
			$this->assertNotSame( '', trim( $guide['title'] ), $guide['id'] );
			$this->assertNotSame( '', trim( wp_strip_all_tags( $guide['body'] ) ), $guide['id'] );
		}
	}

	public function test_every_id_is_unique_and_prefixed(): void {
		$ids = array_column( Guides::add_guides( array() ), 'id' );

		$this->assertSame( $ids, array_values( array_unique( $ids ) ) );

		foreach ( $ids as $id ) {
			$this->assertSame( $id, sanitize_key( $id ) );
			$this->assertStringStartsWith( 'forge-', $id );
		}
	}

	public function test_another_plugins_guides_are_kept(): void {
		$guides = Guides::add_guides( array( array( 'id' => 'acme-one', 'title' => 'One', 'body' => '<p>x</p>' ) ) );

		$this->assertSame( 'acme-one', $guides[0]['id'] );
		$this->assertCount( 10, $guides );
	}

	public function test_every_guide_sits_in_a_tab_under_the_forge_product(): void {
		$tabs     = Guides::add_tabs( array( 'other' => 'Other' ) );
		$products = Guides::add_products( array( 'blueworx' => 'BlueWorx' ) );
		$map      = Guides::add_tab_products( array() );

		$this->assertArrayHasKey( 'other', $tabs, 'existing tabs are kept' );
		$this->assertArrayHasKey( 'blueworx', $products, 'existing products are kept' );
		$this->assertSame( 'Forge', $products['forge'] );

		foreach ( Guides::add_guides( array() ) as $guide ) {
			$this->assertArrayHasKey( $guide['tab'], $tabs, $guide['id'] );
			$this->assertSame( 'forge', $map[ $guide['tab'] ], $guide['id'] );
		}
	}

	public function test_the_workspace_gets_the_same_guides_in_the_same_order(): void {
		$page      = array_column( Guides::add_guides( array() ), 'id' );
		$workspace = Guides::for_workspace();

		$this->assertSame( $page, array_column( $workspace['guides'], 'id' ) );

		foreach ( $workspace['guides'] as $guide ) {
			$this->assertContains( $guide['tab'], array_column( $workspace['tabs'], 'id' ), $guide['id'] );
			$this->assertNotEmpty( $guide['steps'], $guide['id'] );
		}
	}

	public function test_a_button_label_is_emphasised_and_nothing_else_is_markup(): void {
		$guides = Guides::add_guides( array() );
		$ask    = $guides[1]['body'];

		$this->assertSame( 'forge-ask-for-something', $guides[1]['id'] );
		$this->assertStringContainsString( '<em>Send to the studio</em>', $ask );
		$this->assertStringNotContainsString( '*', $ask );
	}
}
