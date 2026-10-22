<?php
/**
 * What the Settings screen is told about updates (#603).
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Rest\UpdatesController;
use PHPUnit\Framework\TestCase;

final class UpdatesAnswerTest extends TestCase {

	private function found( string $release, string $state = 'ok' ): array {
		return array(
			'state'   => $state,
			'message' => 'Updates can be fetched.',
			'release' => $release,
		);
	}

	public function test_a_newer_release_is_an_update(): void {
		$answer = UpdatesController::answer( $this->found( 'v2.206.0' ), '2.205.0' );

		self::assertSame( '2.206.0', $answer['latest'] );
		self::assertSame( '2.205.0', $answer['installed'] );
		self::assertTrue( $answer['update_available'] );
	}

	public function test_the_same_or_an_older_release_is_not(): void {
		self::assertFalse( UpdatesController::answer( $this->found( 'v2.205.0' ), '2.205.0' )['update_available'] );
		self::assertFalse( UpdatesController::answer( $this->found( 'v2.204.0' ), '2.205.0' )['update_available'] );
	}

	public function test_no_release_found_is_not_an_update(): void {
		$answer = UpdatesController::answer( $this->found( '', 'unreachable' ), '2.205.0' );

		self::assertSame( '', $answer['latest'] );
		self::assertSame( 'unreachable', $answer['state'] );
		self::assertFalse( $answer['update_available'] );
	}

	public function test_versions_compare_as_numbers_not_text(): void {
		self::assertTrue( UpdatesController::answer( $this->found( 'v2.210.0' ), '2.99.9' )['update_available'] );
	}
}
