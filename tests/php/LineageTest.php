<?php
/**
 * Walking a task up to its milestone and project.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\Lineage;
use PHPUnit\Framework\TestCase;

/**
 * #669. Which milestone and project a task sits under.
 */
final class LineageTest extends TestCase {

	/**
	 * One item row, as the lineage reads it.
	 *
	 * @param string $id        Id.
	 * @param string $parent_id Parent id, empty for none.
	 * @param string $level     Level.
	 * @param string $title     Title.
	 * @return array<string, string>
	 */
	private function row( string $id, string $parent_id, string $level, string $title = '' ): array {
		return array(
			'id'        => $id,
			'parent_id' => $parent_id,
			'level'     => $level,
			'title'     => $title,
		);
	}

	/**
	 * A project, a milestone, a feature and a sub-feature in a line, plus a loose one.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function tree(): array {
		return array(
			'p1'    => $this->row( 'p1', '', 'project', 'Rebuild' ),
			'm1'    => $this->row( 'm1', 'p1', 'milestone', 'Phase one' ),
			'f1'    => $this->row( 'f1', 'm1', 'feature', 'Header' ),
			's1'    => $this->row( 's1', 'f1', 'sub-feature', 'Logo' ),
			'loose' => $this->row( 'loose', '', 'sub-feature', 'Odd job' ),
		);
	}

	public function test_walks_up_to_milestone_and_project(): void {
		$items = $this->tree();
		$this->assertSame(
			array(
				'milestone' => array(
					'id'    => 'm1',
					'title' => 'Phase one',
				),
				'project'   => array(
					'id'    => 'p1',
					'title' => 'Rebuild',
				),
			),
			Lineage::of( $items, 's1' )
		);
		$this->assertSame(
			array(
				'milestone' => null,
				'project'   => array(
					'id'    => 'p1',
					'title' => 'Rebuild',
				),
			),
			Lineage::of( $items, 'm1' )
		);
		$this->assertSame(
			array(
				'milestone' => null,
				'project'   => null,
			),
			Lineage::of( $items, 'loose' )
		);
	}

	public function test_under_counts_every_descendant(): void {
		$items = $this->tree();
		$this->assertTrue( Lineage::under( $items, 'm1', 's1' ) );
		$this->assertTrue( Lineage::under( $items, 'p1', 's1' ) );
		$this->assertTrue( Lineage::under( $items, 'm1', 'm1' ) );
		$this->assertFalse( Lineage::under( $items, 'm1', 'loose' ) );
	}

	public function test_a_cycle_does_not_hang(): void {
		$items = array(
			'a' => $this->row( 'a', 'b', 'feature' ),
			'b' => $this->row( 'b', 'a', 'feature' ),
		);
		$this->assertSame(
			array(
				'milestone' => null,
				'project'   => null,
			),
			Lineage::of( $items, 'a' )
		);
		$this->assertFalse( Lineage::under( $items, 'z', 'a' ) );
	}
}
