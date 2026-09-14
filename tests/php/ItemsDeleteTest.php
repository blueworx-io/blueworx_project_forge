<?php
/**
 * The order a piece of work and everything under it is removed in.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\Items;
use PHPUnit\Framework\TestCase;

/**
 * Children go before parents, so a delete that stops halfway leaves a parent
 * that can be tried again rather than orphans nothing can reach.
 */
final class ItemsDeleteTest extends TestCase {

	public function test_children_come_before_parents(): void {
		$rows = array(
			array(
				'id'        => 'a',
				'parent_id' => '',
			),
			array(
				'id'        => 'b',
				'parent_id' => 'a',
			),
			array(
				'id'        => 'c',
				'parent_id' => 'b',
			),
			array(
				'id'        => 'x',
				'parent_id' => '',
			),
		);

		self::assertSame( array( 'c', 'b', 'a' ), Items::delete_order( $rows, 'a' ) );
	}

	public function test_a_leaf_is_only_itself(): void {
		$rows = array(
			array(
				'id'        => 'a',
				'parent_id' => '',
			),
			array(
				'id'        => 'b',
				'parent_id' => 'a',
			),
		);

		self::assertSame( array( 'b' ), Items::delete_order( $rows, 'b' ) );
	}

	public function test_siblings_are_all_taken(): void {
		$rows = array(
			array(
				'id'        => 'a',
				'parent_id' => '',
			),
			array(
				'id'        => 'b',
				'parent_id' => 'a',
			),
			array(
				'id'        => 'c',
				'parent_id' => 'a',
			),
		);

		self::assertSame( array( 'b', 'c', 'a' ), Items::delete_order( $rows, 'a' ) );
	}
}
