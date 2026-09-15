<?php
/**
 * Secrets kept at rest.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

use Blueworx\Forge\Tenancy\Secrets;
use PHPUnit\Framework\TestCase;

final class SecretsTest extends TestCase {

	public function test_what_is_sealed_can_be_opened(): void {
		$sealed = Secrets::seal( 'sk_live_abc123' );

		self::assertNotSame( 'sk_live_abc123', $sealed );
		self::assertStringNotContainsString( 'abc123', $sealed );
		self::assertSame( 'sk_live_abc123', Secrets::open( $sealed ) );
	}

	public function test_the_same_secret_seals_differently_each_time(): void {
		self::assertNotSame( Secrets::seal( 'token' ), Secrets::seal( 'token' ) );
	}

	public function test_a_tampered_seal_opens_to_nothing(): void {
		$sealed = Secrets::seal( 'token' );
		$bytes  = base64_decode( $sealed, true );
		$bytes[ strlen( $bytes ) - 1 ] = chr( ord( $bytes[ strlen( $bytes ) - 1 ] ) ^ 1 );

		self::assertNull( Secrets::open( base64_encode( $bytes ) ) );
		self::assertNull( Secrets::open( 'not base64 at all!' ) );
		self::assertNull( Secrets::open( '' ) );
	}

	public function test_an_empty_secret_round_trips(): void {
		self::assertSame( '', Secrets::open( Secrets::seal( '' ) ) );
	}
}
