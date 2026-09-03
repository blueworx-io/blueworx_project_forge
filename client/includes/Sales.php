<?php
/**
 * What this client has, and what they could buy.
 *
 * @package Blueworx\Forge
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client;

/**
 * #156, COMM-2. The client's own view of their entitlement.
 *
 * Read through the same cache as everything else here (ARCH-4): a client site
 * keeps working when the studio is unreachable by showing what it last saw, and
 * saying so. That matters more on this screen than most, because the number on
 * it is money — somebody reading a stale balance as a live one would make a
 * decision on it.
 *
 * **Nothing on this side is worked out.** The balance, the entitlement and what
 * a package costs all arrive as the studio calculated them. A client site that
 * summed a ledger for itself would be a second answer to a question that must
 * have exactly one, and #158's whole point is that the two interfaces cannot
 * disagree about money.
 */
final class Sales {

	/**
	 * The studio route this reads.
	 */
	public const ROUTE = '/client/sales';

	/**
	 * What this site can currently see of its own entitlement.
	 *
	 * @param bool $force True to ignore a still-fresh copy and ask the studio.
	 * @return array<string, mixed>
	 */
	public static function view( bool $force = false ): array {
		$read = ReadThrough::view( self::ROUTE, $force );

		// Null when the studio has never been reached. Everything below reads
		// through this, so the empty case is made an empty array once rather
		// than guarded at each of six lines — and missing one of those guards
		// is a fatal error on the screen a client checks their hours on.
		$payload = is_array( $read['payload'] ?? null ) ? $read['payload'] : array();

		return array(
			'ok'          => is_array( $payload['entitlement'] ?? null ),
			'entitlement' => self::entitlement( $payload ),

			/*
			 * Null rather than nought when it has never been read. Nought is a
			 * client with no hours left, which is a fact worth acting on;
			 * "we have not heard from the studio" is not, and showing the
			 * second as the first would tell a client with forty hours that
			 * they have none.
			 */
			'balance'     => array_key_exists( 'balance', $payload ) ? (float) $payload['balance'] : null,
			'support'     => is_array( $payload['support'] ?? null ) ? $payload['support'] : array(),
			'purchases'   => is_array( $payload['purchases'] ?? null ) ? $payload['purchases'] : array(),
			'packages'    => is_array( $payload['packages'] ?? null ) ? $payload['packages'] : array(),
			'sync'        => $read['sync'],
		);
	}

	/**
	 * The entitlement, with its numbers as numbers.
	 *
	 * Not a calculation — a shape. JSON gives back forty as an integer and
	 * forty and a half as a float, and a screen formatting one of them should
	 * not care which arrived. What the figures *are* is still entirely the
	 * studio's.
	 *
	 * @param array<string, mixed> $payload What the studio sent.
	 * @return array<string, mixed>
	 */
	private static function entitlement( array $payload ): array {
		$given = is_array( $payload['entitlement'] ?? null ) ? $payload['entitlement'] : array();

		if ( array() === $given ) {
			return array();
		}

		return array(
			'state'         => (string) ( $given['state'] ?? '' ),
			'label'         => (string) ( $given['label'] ?? '' ),
			'may_use_hours' => (bool) ( $given['may_use_hours'] ?? false ),
			'hours_granted' => (float) ( $given['hours_granted'] ?? 0 ),
			'starts_on'     => (string) ( $given['starts_on'] ?? '' ),
			'ends_on'       => (string) ( $given['ends_on'] ?? '' ),
			'term_ends_on'  => (string) ( $given['term_ends_on'] ?? '' ),
		);
	}

	/**
	 * How many hours are left, for a screen that wants only that.
	 *
	 * @param array<string, mixed> $view What {@see self::view()} returned.
	 * @return string
	 */
	public static function balance_label( array $view ): string {
		if ( null === $view['balance'] ) {
			return __( 'Not read from the studio yet', 'blueworx-forge' );
		}

		return sprintf(
			/* translators: %s: a number of hours. */
			__( '%s hours left', 'blueworx-forge' ),
			number_format( (float) $view['balance'], 2 )
		);
	}
}
