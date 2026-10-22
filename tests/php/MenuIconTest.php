<?php
/**
 * The Forge menu's icon (#579).
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Admin\MenuIcon;
use PHPUnit\Framework\TestCase;

final class MenuIconTest extends TestCase {

	public function test_the_icon_is_the_bolt_with_its_hole_cut_out(): void {
		$svg = MenuIcon::svg( '#a7aaad' );

		self::assertStringContainsString( 'fill="#a7aaad"', $svg );
		self::assertStringContainsString( 'fill-rule="evenodd"', $svg );
		self::assertStringContainsString( 'M21 16V8a2 2 0 0 0-1-1.73l-7-4', $svg );
		self::assertStringContainsString( 'M16 12a4 4 0 1 0-8 0', $svg );
	}

	public function test_the_menu_gets_it_as_a_data_uri(): void {
		$uri = MenuIcon::data_uri();

		self::assertStringStartsWith( 'data:image/svg+xml;base64,', $uri );
		self::assertSame( MenuIcon::svg( '#a7aaad' ), base64_decode( substr( $uri, strlen( 'data:image/svg+xml;base64,' ) ) ) );
	}
}
