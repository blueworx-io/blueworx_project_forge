<?php
/**
 * Tests for formatted descriptions and the checklist.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Work\Fields;
use Blueworx\Forge\Work\Validate;
use PHPUnit\Framework\TestCase;

/**
 * What a description may carry, what it may not, and how a checklist is
 * bounded — the rules the panel relies on and the API enforces.
 */
final class WorkRichTextChecklistTest extends TestCase {

	/**
	 * The formatting the editor offers is kept; anything else is dropped.
	 */
	public function test_a_description_keeps_the_allowed_formatting_and_nothing_else(): void {
		$checked = Validate::item(
			array( 'problem' => '<p>Keep <strong>this</strong> and <em>this</em></p><script>alert(1)</script><img src="x"><ul><li>and this</li></ul>' ),
			true
		);

		$this->assertSame( array(), $checked['errors'] );
		$this->assertSame( '<p>Keep <strong>this</strong> and <em>this</em></p>alert(1)<ul><li>and this</li></ul>', $checked['values']['problem'] );
	}

	/**
	 * An empty paragraph is an empty field, so a gate cannot be met by formatting alone.
	 */
	public function test_formatting_with_nothing_in_it_is_stored_as_nothing(): void {
		$checked = Validate::item( array( 'acceptance_criteria' => '<p></p><p><br></p>' ), true );

		$this->assertSame( '', $checked['values']['acceptance_criteria'] );
	}

	/**
	 * Plain text keeps its lines when read back without the tags.
	 */
	public function test_plain_text_of_a_description_keeps_its_line_breaks(): void {
		$this->assertSame( "One\n\nTwo Three", Fields::plain( '<p>One</p><p>Two <strong>Three</strong></p>' ) );
	}

	/**
	 * Ten rows, each one line; empty rows are dropped rather than refused.
	 */
	public function test_a_checklist_is_at_most_ten_one_line_rows(): void {
		$rows = array();

		for ( $i = 1; $i <= 10; $i++ ) {
			$rows[] = array( 'text' => "Line {$i}", 'done' => 1 === $i );
		}

		$rows[] = array( 'text' => '   ' );

		$checked = Validate::item( array( 'checklist' => $rows ), true );

		$this->assertSame( array(), $checked['errors'] );

		$kept = json_decode( $checked['values']['checklist'], true );

		$this->assertCount( 10, $kept );
		$this->assertTrue( $kept[0]['done'] );
		$this->assertFalse( $kept[1]['done'] );
		$this->assertSame( 'Line 10', $kept[9]['text'] );

		$rows[] = array( 'text' => 'Line 11' );

		$this->assertArrayHasKey( 'checklist', Validate::item( array( 'checklist' => $rows ), true )['errors'] );
		$this->assertArrayHasKey( 'checklist', Validate::item( array( 'checklist' => array( array( 'text' => "Two\nlines" ) ) ), true )['errors'] );
		$this->assertArrayHasKey( 'checklist', Validate::item( array( 'checklist' => 'not a list' ), true )['errors'] );
	}
}
